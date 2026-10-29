<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionExport;
use CodeIgniter\Config\Services;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * The export's memory stays flat however much data there is, so it holds at the 12.1 target (20,000
 * students, 5 years, about 50 graded items per course: a few million submissions).
 *
 * @internal
 */
final class InstitutionExportScaleTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        $this->folder        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-export-' . bin2hex(random_bytes(4));
        \Tests\Support\TestEnv::set('export.path', $this->folder);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);
        \Tests\Support\TestEnv::restore();

        parent::tearDown();
    }

    /** One institution whose single quiz has $attempts submissions; returns [institution, admin]. */
    private function institutionWithSubmissions(int $attempts): array
    {
        $db       = db_connect();
        $offering = CourseFactory::offering();
        $id       = (int) $db->query('SELECT d.institution_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE o.id = ?', [$offering])->getRow()->institution_id;
        $db->table('institutions')->where('id', $id)->update(['status' => 'read_only']);
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $id);
        $student = PeopleFactory::person('student', 'Sam', 'Student', null, $id);
        $quiz    = CourseFactory::assessment($offering, $teacher);

        // $attempts rows in one statement: the digits 0-9 crossed five times numbers them.
        $digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $db->query(
            "INSERT INTO assessment_submissions (assessment_id, student_id, attempt_number, status, score, submission_text, started_at)
             SELECT ?, ?, n + 1, 'Graded', 7, 'An answer long enough to weigh something in memory, as real ones do.', '2026-10-01 08:00:00'
             FROM (SELECT a.d + b.d * 10 + c.d * 100 + e.d * 1000 + f.d * 10000 AS n FROM {$digits} a, {$digits} b, {$digits} c, {$digits} e, {$digits} f) numbers
             WHERE n < ?",
            [$quiz, $student->id, $attempts],
        );

        return [$id, PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $id)];
    }

    /** Peak memory (bytes) the job used to prepare this export, and the submissions.csv row count. @return array{int, int} */
    private function export(int $institution, $admin): array
    {
        $export = new InstitutionExport();
        $id     = $export->request($institution, $admin);

        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $export->runQueued();
        $peak = memory_get_peak_usage() - $before;

        $row = db_connect()->table('institution_exports')->where('id', $id)->get()->getRowArray();
        $zip = new ZipArchive();
        $zip->open($this->folder . DIRECTORY_SEPARATOR . $row['file_name']);
        $lines = substr_count((string) $zip->getFromName('submissions.csv'), "\n") - 1;
        $zip->close();

        return [$peak, $lines];
    }

    public function testMemoryDoesNotGrowWithTheData(): void
    {
        [$small, $smallAdmin] = $this->institutionWithSubmissions(20_000);
        [$smallPeak, $smallRows] = $this->export($small, $smallAdmin);

        [$large, $largeAdmin] = $this->institutionWithSubmissions(80_000);
        [$largePeak, $largeRows] = $this->export($large, $largeAdmin);

        $this->assertSame(20_000, $smallRows);
        $this->assertSame(80_000, $largeRows, 'every row is in the file');
        $this->assertLessThan(8 * 1024 * 1024, $largePeak - $smallPeak, sprintf('memory grew by %.1f MB for four times the rows', ($largePeak - $smallPeak) / 1048576));
    }
}
