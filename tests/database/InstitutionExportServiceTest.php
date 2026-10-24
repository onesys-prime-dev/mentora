<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionExport;
use CodeIgniter\Config\Services;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * What an institution's full export holds, who it is for, how long it is
 * kept, and that it stays inside one institution.
 *
 * @internal
 */
final class InstitutionExportServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private string $folder;

    private DateTimeImmutable $now;

    private InstitutionExport $export;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-export-' . bin2hex(random_bytes(4));
        $_ENV['export.path'] = $this->folder;

        $this->now    = new DateTimeImmutable('2026-10-10 12:00:00');
        $this->export = new InstitutionExport();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);
        unset($_ENV['export.path']);
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    /** An institution (read-only) with one course, one student, one grade, one submission and one announcement. */
    private function institutionWithData(string $status = 'read_only'): array
    {
        $db       = db_connect();
        $offering = CourseFactory::offering();
        $id       = (int) $db->query('SELECT d.institution_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE o.id = ?', [$offering])->getRow()->institution_id;

        $db->table('institutions')->where('id', $id)->update(['status' => $status]);

        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', 'tess@example.com', $id);
        $student = PeopleFactory::person('student', 'Sam', 'Student', 'sam@example.com', $id);
        $admin   = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@example.com', $id);

        CourseFactory::enroll($offering, $student);

        $db->table('gradebook_records')->insert(['course_offering_id' => $offering, 'student_id' => $student->id, 'calculated_percentage' => 88.5, 'remarks' => 'Passed']);

        $assessment = CourseFactory::assessment($offering, $teacher);
        $db->table('assessment_submissions')->insert([
            'assessment_id' => $assessment, 'student_id' => $student->id, 'attempt_number' => 1, 'score' => 9,
            'is_graded' => 1, 'status' => 'Graded', 'graded_by' => $teacher->id, 'graded_at' => '2026-10-01 10:00:00',
            'submission_text' => '=HYPERLINK("http://evil.example")',
        ]);
        $db->table('announcements')->insert(['offering_id' => $offering, 'author_id' => $teacher->id, 'subject' => 'Welcome', 'message' => '{}', 'created_at' => '2026-09-01 08:00:00']);

        return [$id, $admin, $student];
    }

    /** @return array<string, list<list<string>>> file name => rows (header first) */
    private function open(int $exportId): array
    {
        $row = db_connect()->table('institution_exports')->where('id', $exportId)->get()->getRowArray();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->folder . DIRECTORY_SEPARATOR . $row['file_name']) === true);

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $text = (string) $zip->getFromIndex($i);

            $files[$name] = $name === 'README.txt' ? [[$text]] : array_map('str_getcsv', array_filter(explode("\n", $text), static fn (string $l): bool => $l !== ''));
        }

        $zip->close();

        return $files;
    }

    public function testOnlyAReadOnlyOrPastInstitutionCanBeExported(): void
    {
        $this->assertTrue(InstitutionExport::isEligible('read_only'));
        $this->assertTrue(InstitutionExport::isEligible('past'));
        $this->assertFalse(InstitutionExport::isEligible('active'));
        $this->assertFalse(InstitutionExport::isEligible('pending'));

        [$id, $admin] = $this->institutionWithData('active');

        $this->expectException(InvalidArgumentException::class);
        $this->export->build($id, $admin, $this->now);
    }

    public function testAnInstitutionWhoseSubscriptionRanOutCanExportEvenBeforeTheNightlyJob(): void
    {
        [$id, $admin] = $this->institutionWithData('active');

        $db = db_connect();
        $db->table('plans')->insert(['name' => 'Lapsed plan', 'status' => 'active']);
        $db->table('subscriptions')->insert(['institution_id' => $id, 'plan_id' => (int) $db->insertID(), 'start_date' => '2025-01-01', 'end_date' => '2026-07-01']);

        $this->export->build($id, $admin, $this->now);

        $this->assertSame(1, $db->table('institution_exports')->where(['institution_id' => $id, 'status' => 'ready'])->countAllResults());
    }

    public function testAnUnknownInstitutionIsRefused(): void
    {
        [, $admin] = $this->institutionWithData();

        $this->expectException(InvalidArgumentException::class);
        $this->export->build(999999, $admin, $this->now);
    }

    public function testTheZipHoldsEveryDatasetWithItsRows(): void
    {
        [$id, $admin] = $this->institutionWithData();

        $files = $this->open($this->export->build($id, $admin, $this->now));

        foreach (['institution', 'terms', 'departments', 'sub_departments', 'programs', 'courses', 'offerings', 'people', 'enrollments', 'final_grades', 'assessments', 'submissions', 'announcements'] as $name) {
            $this->assertArrayHasKey("{$name}.csv", $files);
            $this->assertCount(2, $files["{$name}.csv"] === [] ? [] : array_slice($files["{$name}.csv"], 0, 2), "{$name}.csv should have a header and a row");
        }

        $this->assertArrayHasKey('README.txt', $files);
        $this->assertCount(4, $files['people.csv'], 'header + teacher + student + admin');
        $this->assertSame('88.50', $files['final_grades.csv'][1][3]);
        $this->assertSame('Welcome', $files['announcements.csv'][1][2]);
    }

    public function testPeopleAreListedWithEmailAndRolesButNoPasswordHash(): void
    {
        [$id, $admin] = $this->institutionWithData();

        $export = $this->open($this->export->build($id, $admin, $this->now));
        $people = $export['people.csv'];

        $this->assertSame(['email', 'username', 'first_name', 'last_name', 'student_number', 'roles', 'active'], $people[0]);

        $emails = array_column($people, 0);
        $this->assertContains('sam@example.com', $emails);

        $text = implode("\n", array_map(static fn (array $r): string => implode(',', $r), $people));
        $this->assertStringNotContainsString('$2y$', $text);
    }

    public function testASpreadsheetFormulaIsDefusedOnTheWayOut(): void
    {
        [$id, $admin] = $this->institutionWithData();

        $files = $this->open($this->export->build($id, $admin, $this->now));

        $cell = $files['submissions.csv'][1][9];

        $this->assertStringStartsWith("'=", $cell);
    }

    public function testAnotherInstitutionsDataIsNotIncluded(): void
    {
        [$id, $admin]  = $this->institutionWithData();
        [$otherId]     = $this->institutionWithData();

        $files = $this->open($this->export->build($id, $admin, $this->now));

        $this->assertNotSame($id, $otherId);
        $this->assertCount(2, $files['courses.csv']);
        $this->assertCount(2, $files['enrollments.csv']);
        $this->assertCount(4, $files['people.csv']);
    }

    public function testTheExportIsRecordedAndAudited(): void
    {
        [$id, $admin] = $this->institutionWithData();

        $exportId = $this->export->build($id, $admin, $this->now);
        $row      = db_connect()->table('institution_exports')->where('id', $exportId)->get()->getRowArray();

        $this->assertSame('ready', $row['status']);
        $this->assertSame('ada@example.com', $row['requested_by_label']);
        $this->assertSame('2026-10-17 12:00:00', $row['expires_at']);
        $this->assertGreaterThan(0, (int) $row['size_bytes']);
        $this->assertGreaterThan(0, (int) (json_decode((string) $row['row_counts'], true)['people.csv'] ?? 0));

        $log = db_connect()->table('audit_logs')->where('action', 'institution.exported')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame($id, (int) $log['institution_id']);
    }

    public function testADownloadIsOnlyForTheOwningInstitutionAndOnlyUntilItExpires(): void
    {
        [$id, $admin] = $this->institutionWithData();
        [$otherId]    = $this->institutionWithData();

        $exportId = $this->export->build($id, $admin, $this->now);

        $this->assertNotNull($this->export->downloadable($id, $exportId, $this->now));
        $this->assertNull($this->export->downloadable($otherId, $exportId, $this->now), 'someone else\'s export');
        $this->assertNull($this->export->downloadable($id, $exportId, $this->now->modify('+8 days')), 'expired');
        $this->assertNull($this->export->downloadable($id, 999999, $this->now));
    }

    public function testAMissingFileIsNotDownloadable(): void
    {
        [$id, $admin] = $this->institutionWithData();

        $exportId = $this->export->build($id, $admin, $this->now);
        $row      = db_connect()->table('institution_exports')->where('id', $exportId)->get()->getRowArray();
        unlink($this->folder . DIRECTORY_SEPARATOR . $row['file_name']);

        $this->assertNull($this->export->downloadable($id, $exportId, $this->now));
    }

    public function testExpiredExportsAreRemovedWithTheirFiles(): void
    {
        [$id, $admin] = $this->institutionWithData();

        $old = $this->export->build($id, $admin, $this->now->modify('-10 days'));
        $row = db_connect()->table('institution_exports')->where('id', $old)->get()->getRowArray();

        // Building a new one prunes the old one.
        $new = $this->export->build($id, $admin, $this->now);

        $this->assertNull(db_connect()->table('institution_exports')->where('id', $old)->get()->getRowArray());
        $this->assertFileDoesNotExist($this->folder . DIRECTORY_SEPARATOR . $row['file_name']);
        $this->assertSame([$new], array_map(static fn (array $r): int => (int) $r['id'], $this->export->history($id)));
    }

    public function testNothingIsKeptWhenTheFolderCannotBeMade(): void
    {
        [$id, $admin] = $this->institutionWithData();

        // A file where the folder should be.
        file_put_contents($this->folder, 'x');
        $this->assertTrue(is_file($this->folder));

        try {
            $this->export->build($id, $admin, $this->now);
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('folder', $e->getMessage());
        } finally {
            unlink($this->folder);
        }

        $this->assertSame([], $this->export->history($id));
    }
}
