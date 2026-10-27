<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Academic\InstitutionModel;
use App\Services\Users\UserExport;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;
use App\Services\Subscriptions\EffectiveStatus;

/**
 * An institution's full data export (SA13): everything the customer owns, as
 * a ZIP of CSV files they can keep after the subscription ends.
 *
 * Contents: the institution, terms, departments, sub-departments, programs,
 * courses, offerings, people (names, emails, roles; never passwords),
 * enrolments, final grades, assessments, submissions and announcements.
 * Uploaded files are not included (only what is in the database).
 *
 * Only an institution that is read-only or past can be exported this way:
 * that is when the customer is leaving or has stopped. Each export is
 * recorded, audited, and kept for a limited time.
 */
class InstitutionExport
{
    public const KEEP_DAYS = 7;

    /** Statuses an export can be taken in. */
    public const ELIGIBLE = [InstitutionModel::READ_ONLY, InstitutionModel::PAST];

    public static function folder(): string
    {
        return rtrim((string) env('export.path', WRITEPATH . 'exports'), '/\\');
    }

    public static function isEligible(string $status): bool
    {
        return in_array($status, self::ELIGIBLE, true);
    }

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $institutionId, int $limit = 20): array
    {
        return db_connect()->table('institution_exports')->where('institution_id', $institutionId)->orderBy('id', 'desc')->limit($limit)->get()->getResultArray();
    }

    /** What the second administrator to ask is told while one export is being prepared (12.3). */
    public const BUSY = "An export is already being prepared. You'll be told when it's ready.";

    /** A `running` export with no result after this long has stopped (the process died). */
    public const STALLED_MINUTES = 30;

    /**
     * Asks for an export (12.2): it is queued, and `exports:run` prepares it. One at a time per institution:
     * the database keeps a second one out (12.3).
     *
     * @return int the export's id
     *
     * @throws InvalidArgumentException when this institution cannot be exported yet, or one is being prepared
     */
    public function request(int $institutionId, User $actor, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();

        (new InstitutionModel())->find($institutionId)
            ?? throw new InvalidArgumentException('That institution does not exist.');

        if (! self::isEligible(EffectiveStatus::of($institutionId, $now))) {
            throw new InvalidArgumentException('A full export is available once the subscription has ended, while the institution is read-only or past.');
        }

        $db = db_connect();

        if ($db->table('institution_exports')->where(['institution_id' => $institutionId, 'open_marker' => 1])->countAllResults() > 0) {
            throw new InvalidArgumentException(self::BUSY);
        }

        // The request and its audit entry are kept together, or neither is.
        $db->transBegin();

        try {
            try {
                $added = $db->table('institution_exports')->insert([
                    'institution_id'       => $institutionId,
                    'status'               => 'queued',
                    'open_marker'          => 1,
                    'requested_by_user_id' => $actor->id,
                    'requested_by_label'   => $actor->email ?: $actor->username,
                    'created_at'           => $now->format('Y-m-d H:i:s'),
                ]);
            } catch (DatabaseException) {
                $added = false;
            }

            // Two administrators at the same moment: the unique key lets one in.
            if (! $added) {
                throw new InvalidArgumentException(self::BUSY);
            }

            $id = (int) $db->insertID();

            service('auditLogger')->log('institution.export_requested', $actor, [
                'target_type' => 'institution', 'target_id' => $institutionId, 'institution_id' => $institutionId, 'new' => ['export_id' => $id],
            ]);
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $db->transCommit();

        return $id;
    }

    /**
     * The job's side (`exports:run`): ends stalled runs, then prepares each queued export in turn, oldest
     * first. Only the person who asked is told how it went, in the app and by email (12.4).
     *
     * @return list<int> the exports prepared (ready or failed)
     */
    public function runQueued(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $db    = db_connect();

        // Stalled by the clock: started_at is refreshed after each file while an export is being made.
        $stalled = $db->table('institution_exports')->where('status', 'running')
            ->where('started_at <=', date('Y-m-d H:i:s', time() - self::STALLED_MINUTES * 60))
            ->get()->getResultArray();

        foreach ($stalled as $row) {
            $this->fail($row, 'The export stopped before it finished. Ask for a new one.');
        }

        $done = [];

        while (($row = $this->nextQueued()) !== null) {
            // The next minute's run may reach the same export: only the run that claims it makes it.
            if ($this->claim((int) $row['id'])) {
                $done[] = $this->prepare($row, $now);
            }
        }

        $this->prune($now);

        return $done;
    }

    /** The oldest queued export, as it reads now. @return array<string, mixed>|null */
    protected function nextQueued(): ?array
    {
        return db_connect()->table('institution_exports')->where('status', 'queued')->orderBy('id')->get()->getRowArray();
    }

    /** Takes a queued export for this run, in one step; false when another run took it first. */
    private function claim(int $id): bool
    {
        $db = db_connect();
        $db->table('institution_exports')->where(['id' => $id, 'status' => 'queued'])->update(['status' => 'running', 'started_at' => date('Y-m-d H:i:s')]);

        return $db->affectedRows() === 1;
    }

    /** A running export is still being made: its clock is refreshed, so the stall check leaves it alone. */
    protected function touch(int $id): void
    {
        db_connect()->table('institution_exports')->where(['id' => $id, 'status' => 'running'])->update(['started_at' => date('Y-m-d H:i:s')]);
    }

    /** Prepares one export this run claimed. @param array<string, mixed> $row */
    private function prepare(array $row, DateTimeImmutable $now): int
    {
        $db = db_connect();
        $id = (int) $row['id'];

        $path  = null;
        $began = false;

        try {
            $institution = (new InstitutionModel())->find((int) $row['institution_id'])
                ?? throw new RuntimeException('The institution no longer exists.');

            [$name, $path, $counts] = $this->makeZip($institution, $now, $id);

            $db->transBegin();
            $began = true;

            // Only while it is still this run's: the stall check may have ended it meanwhile.
            $db->table('institution_exports')->where(['id' => $id, 'status' => 'running'])->update([
                'status'      => 'ready',
                'open_marker' => null,
                'file_name'   => $name,
                'size_bytes'  => (int) filesize($path),
                'row_counts'  => json_encode($counts),
                'expires_at'  => $now->modify('+' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s'),
            ]);

            if ($db->affectedRows() !== 1) {
                $db->transRollback();
                $began = false;
                @unlink($path);

                return $id;
            }

            service('auditLogger')->log('institution.exported', $this->requester($row), [
                'target_type'    => 'institution',
                'target_id'      => (int) $row['institution_id'],
                'institution_id' => (int) $row['institution_id'],
                'new'            => ['export_id' => $id, 'files' => count($counts), 'rows' => array_sum($counts)],
            ]);

            $db->transCommit();
        } catch (Throwable $e) {
            if ($began) {
                $db->transRollback();
            }

            if ($path !== null) {
                @unlink($path);
            }

            log_message('error', "Institution export #{$id} failed: " . $e->getMessage());
            $this->fail($row, mb_substr($e->getMessage(), 0, 500));

            return $id;
        }

        $this->tell($row, 'Your data export is ready', 'The export of ' . $institution['name'] . ' is ready to download for ' . self::KEEP_DAYS . ' days.');

        return $id;
    }

    /**
     * The ZIP of one institution's data.
     *
     * @param array<string, mixed> $institution
     *
     * @return array{string, string, array<string, int>} file name, path, rows per file
     */
    private function makeZip(array $institution, DateTimeImmutable $now, int $exportId): array
    {
        $folder = self::folder();

        if (! is_dir($folder) && ! @mkdir($folder, 0770, true) && ! is_dir($folder)) {
            throw new RuntimeException('The export folder could not be created.');
        }

        $name = sprintf('%s-%s-%s.zip', strtolower((string) $institution['code']), $now->format('Ymd-His'), bin2hex(random_bytes(4)));
        $path = $folder . DIRECTORY_SEPARATOR . $name;

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export file could not be created.');
        }

        $counts = [];
        $temps  = [];

        try {
            foreach ($this->files() as $file => $spec) {
                $temps[]       = $temp = (string) tempnam(sys_get_temp_dir(), 'mentora-export-');
                $counts[$file] = $this->buildFile($temp, $spec, (int) $institution['id']);
                $zip->addFile($temp, $file);
                $this->touch($exportId);
            }

            $zip->addFromString('README.txt', $this->readme($institution, $counts, $now));

            if (! $zip->close()) {
                throw new RuntimeException('The export file could not be written.');
            }
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($path);

            throw $e;
        } finally {
            // The ZIP reads the files when it closes, so they go only after that.
            foreach ($temps as $temp) {
                @unlink($temp);
            }
        }

        return [$name, $path, $counts];
    }

    /** Rows per page: no table is ever read whole (12.1). */
    public const PAGE_ROWS = 5000;

    /**
     * Writes one CSV to $temp, a page of PAGE_ROWS at a time on its driving table's id (keyset paging), so memory
     * stays flat whatever the size. Returns the rows written.
     *
     * @param array{header: list<string>, key: string, sql: string} $spec sql ends in a WHERE clause on the institution
     */
    private function buildFile(string $temp, array $spec, int $institutionId): int
    {
        $db     = db_connect();
        $stream = fopen($temp, 'w');

        if ($stream === false) {
            throw new RuntimeException('A temporary export file could not be written.');
        }

        fputcsv($stream, $spec['header']);
        $rows  = 0;
        $after = 0;

        do {
            $result = $db->query("{$spec['sql']} AND {$spec['key']} > ? ORDER BY {$spec['key']} LIMIT " . self::PAGE_ROWS, [$institutionId, $after]);
            $page   = $result->getResultArray();
            $result->freeResult();

            foreach ($page as $row) {
                $after = (int) $row['_k'];
                unset($row['_k']);
                fputcsv($stream, array_map(UserExport::safeCell(...), array_values($row)));
                $rows++;
            }
        } while (count($page) === self::PAGE_ROWS);

        fclose($stream);

        return $rows;
    }

    /** Ends an export as failed, frees the institution's slot, and tells the requester. @param array<string, mixed> $row */
    private function fail(array $row, string $message): void
    {
        $db = db_connect();
        $db->table('institution_exports')->where('id', $row['id'])->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'open_marker' => null, 'message' => $message]);

        // Already ended (by another run): it was told then.
        if ($db->affectedRows() !== 1) {
            return;
        }

        $institution = (new InstitutionModel())->find((int) $row['institution_id']);

        $this->tell($row, 'Your data export failed', 'The export of ' . ($institution['name'] ?? 'your institution') . ' could not be made. Ask for a new one, or contact support.');
    }

    /** Only the person who asked (12.4), urgently so by email too. @param array<string, mixed> $row */
    private function tell(array $row, string $title, string $body): void
    {
        if ($row['requested_by_user_id'] === null) {
            return;
        }

        (new \App\Services\Notifications\NotificationService())->send((int) $row['requested_by_user_id'], 'institution.export', $title, $body, true, "export:{$row['id']}:" . md5($title), (int) $row['institution_id'], 'institution/export');
    }

    /** @param array<string, mixed> $row */
    private function requester(array $row): ?User
    {
        return $row['requested_by_user_id'] === null ? null : model(\CodeIgniter\Shield\Models\UserModel::class)->findById((int) $row['requested_by_user_id']);
    }

    /**
     * An export the institution can still download, or null.
     *
     * @return array<string, mixed>|null
     */
    public function downloadable(int $institutionId, int $exportId, ?DateTimeImmutable $now = null): ?array
    {
        $row = db_connect()->table('institution_exports')->where(['id' => $exportId, 'institution_id' => $institutionId, 'status' => 'ready'])->get()->getRowArray();

        if ($row === null || (string) $row['expires_at'] <= ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s')) {
            return null;
        }

        $path = self::folder() . DIRECTORY_SEPARATOR . $row['file_name'];

        return is_file($path) ? $row + ['path' => $path] : null;
    }

    /** Removes expired export files and their rows. */
    public function prune(?DateTimeImmutable $now = null): int
    {
        $db   = db_connect();
        $rows = $db->table('institution_exports')->where('expires_at <=', ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s'))->get()->getResultArray();

        foreach ($rows as $row) {
            if ($row['file_name'] !== null) {
                @unlink(self::folder() . DIRECTORY_SEPARATOR . $row['file_name']);
            }

            $db->table('institution_exports')->where('id', $row['id'])->delete();
        }

        return count($rows);
    }

    /**
     * What goes in each file: its header, the driving table's id the rows are paged on (in the order they were
     * recorded), and the query, which ends in a WHERE clause bound to the institution's id.
     *
     * @return array<string, array{header: list<string>, key: string, sql: string}>
     */
    private function files(): array
    {
        $tree = 'JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id';
        $email = static fn (string $user): string => "(SELECT ai.secret FROM auth_identities ai WHERE ai.user_id = {$user} AND ai.type = 'email_password' LIMIT 1)";

        return [
            'institution.csv'     => ['header' => ['code', 'name', 'status', 'created_at'], 'key' => 'i.id', 'sql' => 'SELECT i.id AS _k, i.code, i.name, i.status, i.created_at FROM institutions i WHERE i.id = ?'],
            'terms.csv'           => ['header' => ['name', 'academic_year', 'start_date', 'end_date', 'status'], 'key' => 't.id', 'sql' => 'SELECT t.id AS _k, t.name, t.academic_year, t.start_date, t.end_date, t.status FROM academic_terms t WHERE t.institution_id = ?'],
            'departments.csv'     => ['header' => ['code', 'name', 'location', 'established_year'], 'key' => 'd.id', 'sql' => 'SELECT d.id AS _k, d.code, d.name, d.location, d.established_year FROM departments d WHERE d.institution_id = ?'],
            'sub_departments.csv' => ['header' => ['department_code', 'code', 'name', 'active'], 'key' => 'sd.id', 'sql' => 'SELECT sd.id AS _k, d.code, sd.code, sd.name, sd.is_active FROM sub_departments sd JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?'],
            'programs.csv'        => ['header' => ['sub_department_code', 'code', 'name', 'status'], 'key' => 'p.id', 'sql' => 'SELECT p.id AS _k, sd.code, p.code, p.name, p.status FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?'],
            'courses.csv'         => ['header' => ['program_code', 'course_code', 'course_title', 'units', 'status'], 'key' => 'c.id', 'sql' => "SELECT c.id AS _k, p.code, c.course_code, c.course_title, c.units, c.status FROM courses c {$tree} WHERE d.institution_id = ?"],
            'offerings.csv'       => ['header' => ['course_code', 'term', 'section', 'capacity', 'status'], 'key' => 'o.id', 'sql' => "SELECT o.id AS _k, c.course_code, t.name, o.section_code, o.capacity, o.status FROM course_offerings o JOIN courses c ON c.id = o.course_id {$tree} LEFT JOIN academic_terms t ON t.id = o.term_id WHERE d.institution_id = ?"],
            'people.csv'          => ['header' => ['email', 'username', 'first_name', 'last_name', 'student_number', 'roles', 'active'], 'key' => 'u.id', 'sql' => 'SELECT u.id AS _k, ' . $email('u.id') . ", u.username, up.first_name, up.last_name, up.student_number, (SELECT GROUP_CONCAT(g.`group` ORDER BY g.`group` SEPARATOR ' ') FROM auth_groups_users g WHERE g.user_id = u.id), u.active FROM institution_users iu JOIN users u ON u.id = iu.user_id LEFT JOIN user_profiles up ON up.user_id = u.id WHERE u.deleted_at IS NULL AND iu.institution_id = ?"],
            'enrollments.csv'     => ['header' => ['course_code', 'section', 'student_email', 'status', 'enrolled_at'], 'key' => 'e.id', 'sql' => 'SELECT e.id AS _k, c.course_code, o.section_code, ' . $email('e.student_user_id') . ", e.status, e.enrolled_at FROM enrollments e JOIN course_offerings o ON o.id = e.offering_id JOIN courses c ON c.id = o.course_id {$tree} WHERE d.institution_id = ?"],
            'final_grades.csv'    => ['header' => ['course_code', 'section', 'student_email', 'percentage', 'overridden', 'overridden_percentage', 'override_reason', 'calculated_at'], 'key' => 'g.id', 'sql' => 'SELECT g.id AS _k, c.course_code, o.section_code, ' . $email('g.student_id') . ", CASE WHEN g.is_override = 1 THEN g.overridden_percentage ELSE g.calculated_percentage END, g.is_override, g.overridden_percentage, g.override_reason, g.last_calculated_at FROM gradebook_records g JOIN course_offerings o ON o.id = g.course_offering_id JOIN courses c ON c.id = o.course_id {$tree} WHERE d.institution_id = ?"],
            'assessments.csv'     => ['header' => ['course_code', 'title', 'status', 'total_points', 'weight_percentage', 'available_from', 'due_date'], 'key' => 'a.id', 'sql' => "SELECT a.id AS _k, c.course_code, a.title, a.status, a.total_points, a.weight_percentage, a.available_from, a.due_date FROM assessments a JOIN courses c ON c.id = a.course_id {$tree} WHERE d.institution_id = ?"],
            'submissions.csv'     => ['header' => ['course_code', 'assessment', 'student_email', 'attempt', 'status', 'score', 'percentage', 'submitted_at', 'graded_at', 'submission_text'], 'key' => 's.id', 'sql' => 'SELECT s.id AS _k, c.course_code, a.title, ' . $email('s.student_id') . ", s.attempt_number, s.status, s.score, s.percentage, s.submitted_at, s.graded_at, s.submission_text FROM assessment_submissions s JOIN assessments a ON a.id = s.assessment_id JOIN courses c ON c.id = a.course_id {$tree} WHERE d.institution_id = ?"],
            'announcements.csv'   => ['header' => ['course_code', 'section', 'subject', 'message', 'pinned', 'posted_at'], 'key' => 'n.id', 'sql' => "SELECT n.id AS _k, c.course_code, o.section_code, n.subject, n.message, n.is_pinned, n.created_at FROM announcements n JOIN course_offerings o ON o.id = n.offering_id JOIN courses c ON c.id = o.course_id {$tree} WHERE d.institution_id = ?"],
        ];
    }

    /**
     * @param array<string, mixed> $institution
     * @param array<string, int>   $counts
     */
    private function readme(array $institution, array $counts, DateTimeImmutable $now): string
    {
        $lines = [
            "Data export of {$institution['name']} ({$institution['code']})",
            'Taken ' . $now->format('Y-m-d H:i') . '.',
            '',
            'Each file is a CSV with a header row, in UTF-8. Cells that would run as a',
            "spreadsheet formula are prefixed with an apostrophe. Rows are in the order",
            'they were recorded; sort them in a spreadsheet as you need.',
            '',
            'Files and how many rows each holds:',
        ];

        foreach ($counts as $file => $n) {
            $lines[] = "  {$file}: {$n}";
        }

        $lines[] = '';
        $lines[] = 'Not included: uploaded files (documents, videos, submitted files) and passwords.';

        return implode("\n", $lines) . "\n";
    }
}
