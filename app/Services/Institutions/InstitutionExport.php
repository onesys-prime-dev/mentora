<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Academic\InstitutionModel;
use App\Services\Users\UserExport;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

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

    /**
     * Takes an export now.
     *
     * @return int the export's id
     *
     * @throws InvalidArgumentException when this institution cannot be exported yet
     * @throws Throwable                when the file cannot be made or the audit entry written (nothing is kept then)
     */
    public function build(int $institutionId, User $actor, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();

        $institution = (new InstitutionModel())->find($institutionId)
            ?? throw new InvalidArgumentException('That institution does not exist.');

        if (! self::isEligible((string) $institution['status'])) {
            throw new InvalidArgumentException('A full export is available once the subscription has ended, while the institution is read-only or past.');
        }

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

        try {
            foreach ($this->files($institutionId) as $file => [$header, $rows]) {
                $stream = fopen('php://temp', 'r+');
                fputcsv($stream, $header);
                $n = 0;

                foreach ($rows as $row) {
                    fputcsv($stream, array_map(UserExport::safeCell(...), $row));
                    $n++;
                }

                rewind($stream);
                $zip->addFromString($file, (string) stream_get_contents($stream));
                fclose($stream);

                $counts[$file] = $n;
            }

            $zip->addFromString('README.txt', $this->readme($institution, $counts, $now));
            $zip->close();
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($path);

            throw $e;
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $db->table('institution_exports')->insert([
                'institution_id'       => $institutionId,
                'status'               => 'ready',
                'file_name'            => $name,
                'size_bytes'           => (int) filesize($path),
                'row_counts'           => json_encode($counts),
                'requested_by_user_id' => $actor->id,
                'requested_by_label'   => $actor->email ?: $actor->username,
                'created_at'           => $now->format('Y-m-d H:i:s'),
                'expires_at'           => $now->modify('+' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s'),
            ]);

            $id = (int) $db->insertID();

            service('auditLogger')->log('institution.exported', $actor, [
                'target_type'    => 'institution',
                'target_id'      => $institutionId,
                'institution_id' => $institutionId,
                'new'            => ['export_id' => $id, 'files' => count($counts), 'rows' => array_sum($counts)],
            ]);
        } catch (Throwable $e) {
            $db->transRollback();
            @unlink($path);

            throw $e;
        }

        $db->transCommit();

        $this->prune($now);

        return $id;
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
     * file name => [header, rows], each rows a generator.
     *
     * @return array<string, array{list<string>, iterable<list<mixed>>}>
     */
    private function files(int $id): array
    {
        $db = db_connect();

        $rows = static function (string $sql, array $binds) use ($db): \Generator {
            foreach ($db->query($sql, $binds)->getResultArray() as $row) {
                yield array_values($row);
            }
        };

        $tree = 'FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id';
        $own  = 'd.institution_id = ?';

        return [
            'institution.csv' => [['code', 'name', 'status', 'created_at'], $rows('SELECT code, name, status, created_at FROM institutions WHERE id = ?', [$id])],
            'terms.csv'       => [['name', 'academic_year', 'start_date', 'end_date', 'status'], $rows('SELECT name, academic_year, start_date, end_date, status FROM academic_terms WHERE institution_id = ? ORDER BY start_date', [$id])],
            'departments.csv' => [['code', 'name', 'location', 'established_year'], $rows('SELECT code, name, location, established_year FROM departments WHERE institution_id = ? ORDER BY code', [$id])],
            'sub_departments.csv' => [['department_code', 'code', 'name', 'active'], $rows('SELECT d.code, sd.code, sd.name, sd.is_active FROM sub_departments sd JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ? ORDER BY d.code, sd.code', [$id])],
            'programs.csv'    => [['sub_department_code', 'code', 'name', 'status'], $rows("SELECT sd.code, p.code, p.name, p.status {$tree} WHERE {$own} ORDER BY sd.code, p.code", [$id])],
            'courses.csv'     => [['program_code', 'course_code', 'course_title', 'units', 'status'], $rows("SELECT p.code, c.course_code, c.course_title, c.units, c.status FROM courses c JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE {$own} ORDER BY p.code, c.course_code", [$id])],
            'offerings.csv'   => [['course_code', 'term', 'section', 'capacity', 'status'], $rows("SELECT c.course_code, t.name, o.section_code, o.capacity, o.status FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id LEFT JOIN academic_terms t ON t.id = o.term_id WHERE {$own} ORDER BY c.course_code, o.section_code", [$id])],
            'people.csv'      => [['email', 'username', 'first_name', 'last_name', 'student_number', 'roles', 'active'], $rows("SELECT (SELECT ai.secret FROM auth_identities ai WHERE ai.user_id = u.id AND ai.type = 'email_password' LIMIT 1), u.username, up.first_name, up.last_name, up.student_number, (SELECT GROUP_CONCAT(g.`group` ORDER BY g.`group` SEPARATOR ' ') FROM auth_groups_users g WHERE g.user_id = u.id), u.active FROM institution_users iu JOIN users u ON u.id = iu.user_id LEFT JOIN user_profiles up ON up.user_id = u.id WHERE iu.institution_id = ? AND u.deleted_at IS NULL ORDER BY up.last_name, u.id", [$id])],
            'enrollments.csv' => [['course_code', 'section', 'student_email', 'status', 'enrolled_at'], $rows("SELECT c.course_code, o.section_code, (SELECT ai.secret FROM auth_identities ai WHERE ai.user_id = e.student_user_id AND ai.type = 'email_password' LIMIT 1), e.status, e.enrolled_at FROM enrollments e JOIN course_offerings o ON o.id = e.offering_id JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE {$own} ORDER BY c.course_code, o.section_code, e.id", [$id])],
            'final_grades.csv' => [['course_code', 'section', 'student_email', 'percentage', 'overridden', 'overridden_percentage', 'override_reason', 'calculated_at'], $rows("SELECT c.course_code, o.section_code, (SELECT ai.secret FROM auth_identities ai WHERE ai.user_id = g.student_id AND ai.type = 'email_password' LIMIT 1), g.calculated_percentage, g.is_override, g.overridden_percentage, g.override_reason, g.last_calculated_at FROM gradebook_records g JOIN course_offerings o ON o.id = g.course_offering_id JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE {$own} ORDER BY c.course_code, o.section_code, g.id", [$id])],
            'assessments.csv' => [['course_code', 'title', 'status', 'total_points', 'weight_percentage', 'available_from', 'due_date'], $rows("SELECT c.course_code, a.title, a.status, a.total_points, a.weight_percentage, a.available_from, a.due_date FROM assessments a JOIN courses c ON c.id = a.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE {$own} ORDER BY c.course_code, a.id", [$id])],
            'submissions.csv' => [['course_code', 'assessment', 'student_email', 'attempt', 'status', 'score', 'percentage', 'submitted_at', 'graded_at', 'submission_text'], $rows("SELECT c.course_code, a.title, (SELECT ai.secret FROM auth_identities ai WHERE ai.user_id = s.student_id AND ai.type = 'email_password' LIMIT 1), s.attempt_number, s.status, s.score, s.percentage, s.submitted_at, s.graded_at, s.submission_text FROM assessment_submissions s JOIN assessments a ON a.id = s.assessment_id JOIN courses c ON c.id = a.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE {$own} ORDER BY c.course_code, a.id, s.id", [$id])],
            'announcements.csv' => [['course_code', 'section', 'subject', 'message', 'pinned', 'posted_at'], $rows("SELECT c.course_code, o.section_code, n.subject, n.message, n.is_pinned, n.created_at FROM announcements n JOIN course_offerings o ON o.id = n.offering_id JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE {$own} ORDER BY c.course_code, n.id", [$id])],
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
            "spreadsheet formula are prefixed with an apostrophe.",
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
