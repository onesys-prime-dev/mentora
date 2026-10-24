<?php

declare(strict_types=1);

namespace App\Services\Sis;

use App\Models\Academic\InstitutionModel;
use App\Services\Notifications\NotificationTriggers;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use App\Services\Subscriptions\EffectiveStatus;

/**
 * The outbound sync (SA10): final grades from Mentora to the SIS. Mentora is
 * the source of truth here, and only final grades go out (course, student,
 * percentage, remarks): never assessment scores or feedback.
 *
 * - Where the institution has a dean, only grades the dean signed off go out
 *   (GradeSignoffService); a change approved after sign-off (an override) is
 *   sent once like any other change. Without a dean, a finalized grade is enough.
 * - Only what is new or changed since it was last sent is delivered
 *   (`sis_grade_exports` remembers), so a run with nothing new sends nothing.
 * - A delivery has a key made from its content: the same grades always give
 *   the same key, and the connector replaces a delivery it already has. A
 *   retry, a repeat run, or a crash between sending and recording therefore
 *   never duplicates.
 * - A failed delivery is retried right away (`attempts`); if it still fails,
 *   nothing is marked as sent, the run is recorded as failed and the
 *   administrators are told. The next run sends the same batch again.
 * - Grades whose course or student the SIS does not know (not imported from
 *   it) are not sent; they are counted as skipped.
 * - A preview works out the counts and writes and sends nothing.
 */
class SisGradeExporter
{
    /** How many left-out grades a run keeps in words. */
    private const MAX_ISSUES = 100;

    public const DEFAULT_ATTEMPTS = 3;

    public function __construct(
        private readonly ?SisConnectionService $connections = null,
        private readonly ?SisRunLog $runs = null,
        private readonly ?SisConnector $connector = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException when a run is not allowed right now (nothing is recorded)
     */
    public function run(int $institutionId, ?User $actor, bool $dryRun, string $trigger = SisRunLog::MANUAL, ?DateTimeImmutable $now = null, int $attempts = self::DEFAULT_ATTEMPTS): SisImportResult
    {
        $now ??= new DateTimeImmutable();

        $institution = (new InstitutionModel())->find($institutionId) ?? throw new InvalidArgumentException('That institution does not exist.');
        $connections = $this->connections ?? new SisConnectionService();
        $runs        = $this->runs ?? new SisRunLog();
        $view        = $connections->view($institutionId);

        if (! $view['flagOn']) {
            throw new InvalidArgumentException('The "' . SisConnectionService::FLAG . '" feature is off for this institution.');
        }

        if (! $view['configured']) {
            throw new InvalidArgumentException('Save the connection settings first.');
        }

        if (! $dryRun) {
            if (! $view['enabled']) {
                throw new InvalidArgumentException('The connection is not enabled.');
            }

            // By its dates as well, so a lapsed subscription stops sending even before the nightly job runs.
            if (EffectiveStatus::of($institutionId, $now) !== InstitutionModel::ACTIVE) {
                throw new InvalidArgumentException('Only an active institution can send grades.');
            }
        }

        $runId = $runs->start($institutionId, SisRunLog::OUTBOUND, $trigger, $actor === null ? null : (int) $actor->id, $dryRun, $now);

        try {
            $plan = SisGradePayload::plan($this->candidates($institutionId), $this->alreadySent($institutionId));
        } catch (Throwable $e) {
            log_message('error', 'SIS grade export for institution ' . $institutionId . ' failed: ' . $e->getMessage());

            return $this->failed($runs, $runId, $dryRun, $institution, 'An unexpected error stopped the export; nothing was sent. The details are in the server log.', $now);
        }

        $counts = ['created' => $plan['created'], 'updated' => $plan['updated'], 'skipped' => $plan['skipped'], 'failed' => 0];
        $key    = SisGradePayload::batchKey($institutionId, $plan['rows']);

        // Who is left out and why, so a preview answers "where is Ann's grade?" instead of giving a number.
        [$issues, $truncated] = $this->issues($plan['left_out']);

        if ($dryRun || $plan['rows'] === []) {
            $message = $plan['rows'] === [] ? 'No new or changed final grades to send.' : $this->summary($counts, true);
            $runs->finish($runId, true, $counts, $message, $now, ['datasets' => ['grades' => $counts], 'issues' => $issues, 'truncated' => $truncated]);

            if ($dryRun) {
                service('auditLogger')->log('sis.grades_previewed', $actor, $this->audit($institutionId, $counts, $runId, null));
            }

            return new SisImportResult($runId, true, $dryRun, ['grades' => $counts], $issues, $message);
        }

        try {
            $connector = $this->connector ?? $connections->connector($institutionId);
            $attempt   = 0;
            $last      = null;

            while ($attempt < max(1, $attempts)) {
                $attempt++;

                try {
                    $connector->publishGrades($key, $plan['rows']);
                    $last = null;

                    break;
                } catch (SisException $e) {
                    $last = $e;
                }
            }

            if ($last !== null) {
                throw new SisException("The grades could not be delivered after {$attempt} attempt(s): {$last->getMessage()}");
            }
        } catch (SisException $e) {
            return $this->failed($runs, $runId, false, $institution, $e->getMessage(), $now);
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $stamp = $now->format('Y-m-d H:i:s');

            foreach ($plan['items'] as $item) {
                $where = ['offering_id' => $item['offering_id'], 'student_id' => $item['student_id']];
                $data  = ['percentage' => $item['percentage'], 'remarks' => $item['remarks'], 'batch_key' => $key, 'sent_at' => $stamp];

                if ($db->table('sis_grade_exports')->where($where)->countAllResults() > 0) {
                    $db->table('sis_grade_exports')->where($where)->update($data);
                } elseif (! $db->table('sis_grade_exports')->insert($where + $data + ['institution_id' => $institutionId])) {
                    throw new \RuntimeException('The sent grade could not be recorded.');
                }
            }

            service('auditLogger')->log('sis.grades_sent', $actor, $this->audit($institutionId, $counts, $runId, $key));

            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'SIS grade export for institution ' . $institutionId . ' could not be recorded: ' . $e->getMessage());

            return $this->failed($runs, $runId, false, $institution, 'The grades were delivered but could not be recorded. The next run sends the same batch again, which replaces it, so nothing is duplicated.', $now);
        }

        $message = $this->summary($counts, false);
        $runs->finish($runId, true, $counts, $message, $now, ['datasets' => ['grades' => $counts], 'issues' => $issues, 'truncated' => $truncated, 'batch' => $key]);

        return new SisImportResult($runId, true, false, ['grades' => $counts], $issues, $message);
    }

    /**
     * Every student record of the institution, each either ready to hand
     * over (a final grade, of a course and student the SIS knows) or marked
     * with why it is left out (`skip`).
     *
     * @return list<array{offering_id: int, student_id: int, course_external_id: string, student_external_id: string, percentage: ?float, remarks: string, skip?: string}>
     */
    private function candidates(int $institutionId): array
    {
        $db = db_connect();

        $links = static function (string $entity) use ($db, $institutionId): array {
            $map = [];

            foreach ($db->table('sis_links')->select('local_id, external_id')->where(['institution_id' => $institutionId, 'entity' => $entity])->get()->getResultArray() as $row) {
                $map[(int) $row['local_id']] = (string) $row['external_id'];
            }

            return $map;
        };

        $courses  = $links('course');
        $students = $links('person');

        $records = $db->table('gradebook_records g')
            ->select('g.course_offering_id, g.student_id, g.calculated_percentage, g.is_override, g.overridden_percentage, g.finalized_at, g.final_percentage, g.remarks, o.course_id')
            ->join('course_offerings o', 'o.id = g.course_offering_id')
            ->join('courses c', 'c.id = o.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->orderBy('g.id')
            ->get()->getResultArray();

        // Where the institution has a dean, only the grades the dean signed off go out; elsewhere (null) finalization is enough.
        $signedOff = $this->signedOffSections($institutionId);

        $candidates = [];

        foreach ($records as $record) {
            $course  = $courses[(int) $record['course_id']] ?? null;
            $student = $students[(int) $record['student_id']] ?? null;

            // Only a finalized grade is handed over (and, with a dean, a signed-off one); a withdrawn student has
            // no final grade to give and an Incomplete is not settled yet. Whatever is left out is counted and said why.
            $skip = match (true) {
                $course === null || $student === null => SisGradePayload::NOT_IN_SIS,
                empty($record['finalized_at']) => SisGradePayload::NOT_FINAL,
                $signedOff !== null && ! isset($signedOff[(int) $record['course_offering_id']]) => SisGradePayload::NOT_SIGNED_OFF,
                $record['remarks'] === 'Withdrawn' => SisGradePayload::WITHDRAWN,
                $record['remarks'] === 'INC' => SisGradePayload::INCOMPLETE,
                default => null,
            };

            $candidates[] = [
                'offering_id'         => (int) $record['course_offering_id'],
                'student_id'          => (int) $record['student_id'],
                'course_external_id'  => $course ?? '',
                'student_external_id' => $student ?? '',
                'percentage'          => $skip === null ? SisGradePayload::finalPercentage($record) : null,
                'remarks'             => (string) $record['remarks'],
            ] + ($skip !== null ? ['skip' => $skip] : []);
        }

        return $candidates;
    }

    /**
     * The sections whose grades the dean has signed off, when the institution has a dean; null when it
     * has none, which means the gate does not apply and a finalized grade is enough, as it always was.
     *
     * A reopened sign-off is no longer one, so changes to that section wait until it is signed off again.
     *
     * @return array<int, true>|null offering id => true
     */
    private function signedOffSections(int $institutionId): ?array
    {
        $db = db_connect();

        $hasDean = $db->table('dean_assignments da')
            ->join('departments d', 'd.id = da.department_id')
            ->where('d.institution_id', $institutionId)
            ->where(['da.status' => 'active', 'da.active_marker' => 1])
            ->countAllResults() > 0;

        if (! $hasDean) {
            return null;
        }

        $ids = $db->table('grade_signoffs s')
            ->select('s.offering_id')
            ->join('course_offerings o', 'o.id = s.offering_id')
            ->join('courses c', 'c.id = o.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->where('s.open_marker', 1)
            ->get()->getResultArray();

        return array_fill_keys(array_map('intval', array_column($ids, 'offering_id')), true);
    }

    /**
     * The grades left out, in words. A section that is not finalized (or not
     * known to the SIS) is one line with a count; everyone else is named.
     * Grades that were already sent and did not change are not listed: that
     * is the normal case on a repeat send.
     *
     * @param list<array{offering_id: int, student_id: int, reason: string}> $leftOut
     *
     * @return array{0: list<array{dataset: string, item: string, reason: string}>, 1: bool} the lines and whether more were cut off
     */
    private function issues(array $leftOut): array
    {
        $db = db_connect();

        $perSection = [SisGradePayload::NOT_FINAL, SisGradePayload::NOT_SIGNED_OFF, SisGradePayload::NOT_IN_SIS];

        $sections = [];
        $named = [];

        foreach ($leftOut as $row) {

            if ($row['reason'] === SisGradePayload::UNCHANGED) {
                continue;
            }

            if (in_array($row['reason'], $perSection, true)) {
                $sections[$row['reason']][$row['offering_id']] = ($sections[$row['reason']][$row['offering_id']] ?? 0) + 1;
            } else {
                $named[] = $row;
            }

        }

        $offeringIds = array_unique(array_merge(array_map('intval', array_column($named, 'offering_id')), ...array_map(static fn (array $s): array => array_keys($s), array_values($sections) ?: [[]])));

        $labels = [];

        if ($offeringIds !== []) {
            foreach ($db->table('course_offerings o')->select("o.id, CONCAT(c.course_code, '-', o.section_code) AS label", false)->join('courses c', 'c.id = o.course_id')->whereIn('o.id', $offeringIds)->get()->getResultArray() as $row) {
                $labels[(int) $row['id']] = (string) $row['label'];
            }
        }

        $names = [];

        $studentIds = array_unique(array_map('intval', array_column($named, 'student_id')));

        if ($studentIds !== []) {
            foreach ($db->table('user_profiles')->select("user_id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name", false)->whereIn('user_id', $studentIds)->get()->getResultArray() as $row) {
                $names[(int) $row['user_id']] = (string) $row['name'];
            }
        }

        $issues = [];

        foreach ($sections as $reason => $perOffering) {
            foreach ($perOffering as $offeringId => $count) {
                $issues[] = [
                    'dataset' => 'grades',
                    'item'    => ($labels[$offeringId] ?? 'Section ' . $offeringId) . ' (' . $count . ($count === 1 ? ' student' : ' students') . ')',
                    'reason'  => SisGradePayload::REASONS[$reason],
                ];
            }
        }

        foreach ($named as $row) {
            $issues[] = [
                'dataset' => 'grades',
                'item'    => (($names[$row['student_id']] ?? '') !== '' ? $names[$row['student_id']] : 'Student ' . $row['student_id']) . ' · ' . ($labels[$row['offering_id']] ?? 'Section ' . $row['offering_id']),
                'reason'  => SisGradePayload::REASONS[$row['reason']] ?? $row['reason'],
            ];
        }

        return [array_slice($issues, 0, self::MAX_ISSUES), count($issues) > self::MAX_ISSUES];
    }

    /** @return array<string, array{percentage: float, remarks: string}> */
    private function alreadySent(int $institutionId): array
    {
        $sent = [];

        foreach (db_connect()->table('sis_grade_exports')->where('institution_id', $institutionId)->get()->getResultArray() as $row) {
            $sent["{$row['offering_id']}:{$row['student_id']}"] = ['percentage' => (float) $row['percentage'], 'remarks' => (string) $row['remarks']];
        }

        return $sent;
    }

    /**
     * @param array{created: int, updated: int, skipped: int, failed: int} $counts
     *
     * @return array<string, mixed>
     */
    private function audit(int $institutionId, array $counts, int $runId, ?string $batch): array
    {
        return [
            'target_type'    => 'institution',
            'target_id'      => $institutionId,
            'institution_id' => $institutionId,
            'new'            => $counts + ['run_id' => $runId, 'batch' => $batch],
        ];
    }

    /** @param array{created: int, updated: int, skipped: int, failed: int} $counts */
    private function summary(array $counts, bool $dryRun): string
    {
        return ($dryRun ? 'Preview, nothing was sent: ' : 'Grades sent: ') . "{$counts['created']} new, {$counts['updated']} changed, {$counts['skipped']} left out.";
    }

    /**
     * @param array<string, mixed> $institution
     */
    private function failed(SisRunLog $runs, int $runId, bool $dryRun, array $institution, string $message, DateTimeImmutable $now): SisImportResult
    {
        $runs->finish($runId, false, [], $message, $now, ['datasets' => [], 'issues' => [], 'truncated' => false]);

        if (! $dryRun) {
            (new NotificationTriggers())->syncFailed((int) $institution['id'], (string) $institution['name'], $message, $runId, $now, 'grade export');
        }

        service('auditLogger')->log('sis.grades_failed', null, [
            'actor_label'    => 'system',
            'target_type'    => 'institution',
            'target_id'      => (int) $institution['id'],
            'institution_id' => (int) $institution['id'],
            'new'            => ['dry_run' => $dryRun, 'run_id' => $runId, 'message' => $message],
        ]);

        return new SisImportResult($runId, false, $dryRun, [], [], $message);
    }
}
