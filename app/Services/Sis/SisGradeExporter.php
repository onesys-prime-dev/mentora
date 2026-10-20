<?php

declare(strict_types=1);

namespace App\Services\Sis;

use App\Models\Academic\InstitutionModel;
use App\Services\Notifications\NotificationTriggers;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * The outbound sync (SA10): final grades from Mentora to the SIS. Mentora is
 * the source of truth here, and only final grades go out (course, student,
 * percentage, remarks): never assessment scores or feedback.
 *
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

            if ($institution['status'] !== InstitutionModel::ACTIVE) {
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

        if ($dryRun || $plan['rows'] === []) {
            $message = $plan['rows'] === [] ? 'No new or changed final grades to send.' : $this->summary($counts, true);
            $runs->finish($runId, true, $counts, $message, $now, ['datasets' => ['grades' => $counts], 'issues' => [], 'truncated' => false]);

            if ($dryRun) {
                service('auditLogger')->log('sis.grades_previewed', $actor, $this->audit($institutionId, $counts, $runId, null));
            }

            return new SisImportResult($runId, true, $dryRun, ['grades' => $counts], [], $message);
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
        $runs->finish($runId, true, $counts, $message, $now, ['datasets' => ['grades' => $counts], 'issues' => [], 'truncated' => false, 'batch' => $key]);

        return new SisImportResult($runId, true, false, ['grades' => $counts], [], $message);
    }

    /**
     * Every final grade of the institution's SIS-known courses and students.
     * Unknown ones are counted by the caller as skipped, never listed.
     *
     * @return list<array{offering_id: int, student_id: int, course_external_id: string, student_external_id: string, percentage: ?float, remarks: string}>
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
            ->select('g.course_offering_id, g.student_id, g.calculated_percentage, g.is_override, g.overridden_percentage, g.remarks, o.course_id')
            ->join('course_offerings o', 'o.id = g.course_offering_id')
            ->join('courses c', 'c.id = o.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->orderBy('g.id')
            ->get()->getResultArray();

        $candidates = [];

        foreach ($records as $record) {
            $course  = $courses[(int) $record['course_id']] ?? null;
            $student = $students[(int) $record['student_id']] ?? null;

            if ($course === null || $student === null) {
                continue; // the SIS does not know it
            }

            // A withdrawn student has no final grade to hand over, and an Incomplete is not settled yet:
            // both are counted as skipped rather than sent.
            $settled = ! in_array($record['remarks'], ['Withdrawn', 'INC'], true);

            $candidates[] = [
                'offering_id'         => (int) $record['course_offering_id'],
                'student_id'          => (int) $record['student_id'],
                'course_external_id'  => $course,
                'student_external_id' => $student,
                'percentage'          => $settled ? SisGradePayload::finalPercentage($record) : null,
                'remarks'             => (string) $record['remarks'],
            ];
        }

        return $candidates;
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
