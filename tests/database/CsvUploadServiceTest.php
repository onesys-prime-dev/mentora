<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
use App\Services\Institutions\ClassSectionService;
use App\Services\Institutions\CsvUploadService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * Bulk entry from CSV files: a preview that writes nothing and tells the
 * truth about what applying does, files refused as a whole, the SIS taking
 * precedence, kept previews that belong to one institution and expire, and
 * the templates.
 *
 * Like SisImporterRollbackTest this class does not run inside a rolled-back
 * test transaction: a preview's own transaction must be the outermost one for
 * its rollback (and so "writes nothing") to be real.
 *
 * @internal
 */
final class CsvUploadServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = false;

    /** Upload columns (no SIS ids). Two good students and one with a bad email. */
    private const STUDENTS = "student_number,email,first_name,middle_name,last_name,suffix\n2026-001,ann@example.com,Ann,Cruz,Lee,Jr.\n2026-002,ben@example.com,Ben,,Ng,\n,not-an-email,Cy,,Ho,\n";

    private const INSTRUCTORS = "email,first_name,middle_name,last_name,suffix\nivy@example.com,Ivy,,Tan,\n";

    /** One good course; one in a program the institution does not have. */
    private const COURSES = "program_code,course_code,course_title,units\nBSIT,IT101,Intro to Computing,3\nNOPE,IT102,No Program,3\n";

    /** By student number, by email (into a new section B), and one into a course that does not exist. */
    private const ENROLLMENTS = "student_number,email,program_code,course_code,section,status\n2026-001,,,IT101,,enrolled\n,ben@example.com,BSIT,IT101,B,dropped\n2026-001,,,IT999,,enrolled\n";

    /** Touched by these tests; emptied with foreign keys off. */
    private const TABLES = [
        'sis_runs', 'sis_links', 'sis_connections', 'feature_flag_overrides', 'enrollments', 'class_section_students', 'course_offerings', 'class_sections', 'courses',
        'academic_terms', 'programs', 'sub_departments', 'departments', 'institution_users', 'user_profiles',
        'auth_groups_users', 'auth_identities', 'users', 'institutions', 'audit_logs', 'notifications',
    ];

    private string $base;

    private int $institution;

    private int $other;

    private User $actor;

    private CsvUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipe();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->base        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-csv-' . bin2hex(random_bytes(4));
        $this->institution = SisFixture::institution('UPLA');
        $this->other       = SisFixture::institution('UPLB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@upload.test', $this->institution);
        $this->service     = new CsvUploadService($this->base);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
        $this->wipe();

        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function wipe(): void
    {
        $db = db_connect();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TABLES as $table) {
            $db->query('TRUNCATE TABLE `' . $table . '`');
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function removeTree(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }

        @rmdir($dir);
    }

    /** @return array<string, string> */
    private function files(): array
    {
        return [
            'students'    => self::STUDENTS,
            'instructors' => self::INSTRUCTORS,
            'courses'     => self::COURSES,
            'enrollments' => self::ENROLLMENTS,
        ];
    }

    private function refused(callable $do): StructureException
    {
        try {
            $do();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected the upload to be refused.');
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $counts = [];

        foreach (['users', 'auth_identities', 'user_profiles', 'institution_users', 'courses', 'course_offerings', 'enrollments', 'sis_links'] as $table) {
            $counts[$table] = db_connect()->table($table)->countAllResults();
        }

        return $counts;
    }

    public function testAPreviewWritesNothingAndItsCountsAreWhatApplyingThenDoes(): void
    {
        $before = $this->snapshot();

        $preview = $this->service->preview($this->institution, $this->files(), $this->actor);

        $this->assertSame($before, $this->snapshot(), 'a preview must change nothing');
        $this->assertTrue($preview['result']->dryRun);

        $kept = $this->service->kept($this->institution, $preview['token']);
        $this->assertSame(6, $kept['totals']['created']);
        $this->assertSame(['students', 'instructors', 'courses', 'enrollments'], $kept['files']);
        $this->assertNotSame([], $kept['issues'], 'the bad rows are listed');

        $applied = $this->service->apply($this->institution, $preview['token'], $this->actor);

        $this->assertTrue($applied->ok);
        $this->assertSame(array_diff_key($kept['totals'], ['ended' => 0, 'removed' => 0]), $applied->totals());
        $this->assertSame($kept['totals']['ended'], $applied->ended(), 'and how many it ends is said on its own');
        $this->assertSame($kept['totals']['removed'], $applied->removed(), 'as are the class times it removes');
        $this->assertGreaterThan($before['users'], $this->snapshot()['users']);
        $this->assertGreaterThan($before['enrollments'], $this->snapshot()['enrollments']);
    }

    public function testTheKeptFilesAreRemovedOnceApplied(): void
    {
        $preview = $this->service->preview($this->institution, $this->files(), $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $this->expectException(PageNotFoundException::class);
        $this->service->kept($this->institution, $preview['token']);
    }

    public function testOnlyTheFilesGivenAreUsed(): void
    {
        $preview = $this->service->preview($this->institution, ['students' => self::STUDENTS], $this->actor);

        $kept = $this->service->kept($this->institution, $preview['token']);

        $this->assertSame(['students'], $kept['files']);
        $this->assertSame(2, $kept['totals']['created']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadUploads(): iterable
    {
        yield 'no files' => [[], ''];
        yield 'only blanks' => [['students' => "  \n"], ''];
        yield 'a missing column' => [['students' => "email,first_name\na@b.test,Ann\n"], 'students'];
        yield 'the SIS columns instead of the upload columns' => [['enrollments' => "course_external_id,student_external_id\nC1,S1\n"], 'enrollments'];
        yield 'a header that is only spaces' => [['courses' => '   '], ''];
        yield 'a file this upload does not take' => [['grades' => "a,b\n1,2\n"], ''];
    }

    /**
     * @param array<string, string> $files
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadUploads')]
    public function testAnUnusableUploadIsRefusedAndNothingIsKept(array $files, string $field): void
    {
        $before = $this->snapshot();

        $e = $this->refused(fn () => $this->service->preview($this->institution, $files, $this->actor));

        if ($field !== '') {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(is_dir($this->base . DIRECTORY_SEPARATOR . $this->institution) && glob($this->base . DIRECTORY_SEPARATOR . $this->institution . DIRECTORY_SEPARATOR . '*') !== []);
    }

    public function testItIsRefusedWhileTheSisIsConnected(): void
    {
        SisFixture::connect($this->institution, $this->actor);

        $this->assertTrue($this->service->blockedBySis($this->institution));

        $e = $this->refused(fn () => $this->service->preview($this->institution, $this->files(), $this->actor));

        $this->assertStringContainsString('student information system', $e->getMessage());
    }

    public function testASisThatIsConfiguredButSwitchedOffDoesNotBlockIt(): void
    {
        SisFixture::connect($this->institution, $this->actor, false);

        $this->assertFalse($this->service->blockedBySis($this->institution));
    }

    public function testApplyingIsRefusedIfTheSisWasConnectedAfterThePreview(): void
    {
        $preview = $this->service->preview($this->institution, $this->files(), $this->actor);
        SisFixture::connect($this->institution, $this->actor);

        $this->refused(fn () => $this->service->apply($this->institution, $preview['token'], $this->actor));

        $this->assertSame(0, db_connect()->table('sis_links')->where('institution_id', $this->institution)->countAllResults());
    }

    public function testAKeptPreviewBelongsToOneInstitutionAndExpires(): void
    {
        $preview = $this->service->preview($this->institution, ['students' => self::STUDENTS], $this->actor);

        foreach ([
            fn () => $this->service->kept($this->other, $preview['token']),
            fn () => $this->service->apply($this->other, $preview['token'], $this->actor),
            fn () => $this->service->discard($this->other, $preview['token']),
            fn () => $this->service->kept($this->institution, str_repeat('0', 32)),
            fn () => $this->service->kept($this->institution, '../../etc'),
        ] as $do) {
            try {
                $do();
                $this->fail('An unknown token must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $file = $this->base . DIRECTORY_SEPARATOR . $this->institution . DIRECTORY_SEPARATOR . $preview['token'] . DIRECTORY_SEPARATOR . 'preview.json';
        $data = json_decode((string) file_get_contents($file), true);
        $data['at'] = time() - CsvUploadService::KEEP_SECONDS - 5;
        file_put_contents($file, json_encode($data));

        $this->expectException(PageNotFoundException::class);
        $this->service->kept($this->institution, $preview['token']);
    }

    private function age(int $institution, string $token, int $seconds): string
    {
        $folder = $this->base . DIRECTORY_SEPARATOR . $institution . DIRECTORY_SEPARATOR . $token;
        touch($folder, time() - $seconds);
        clearstatcache();

        return $folder;
    }

    /** A preview nobody applies or starts over from is removed within its hour, whatever else happens (QA AMB-INST-06). */
    public function testAnAbandonedPreviewIsRemovedOnceItsHourIsUpWithoutAnotherPreview(): void
    {
        $old   = $this->service->preview($this->institution, ['students' => self::STUDENTS], $this->actor);
        $other = $this->service->preview($this->other, ['students' => self::STUDENTS], $this->actor);
        $fresh = $this->service->preview($this->institution, ['students' => self::STUDENTS], $this->actor);

        $gone = $this->age($this->institution, $old['token'], CsvUploadService::KEEP_SECONDS + 5);
        $alsoGone = $this->age($this->other, $other['token'], CsvUploadService::KEEP_SECONDS + 5);

        $this->assertSame(2, $this->service->forgetExpired());

        $this->assertDirectoryDoesNotExist($gone);
        $this->assertDirectoryDoesNotExist($alsoGone);
        $this->assertIsArray($this->service->kept($this->institution, $fresh['token']), 'a preview within its hour stays');
        $this->assertDirectoryExists($this->base . DIRECTORY_SEPARATOR . $this->institution . DIRECTORY_SEPARATOR . $fresh['token']);
    }

    public function testTheEveryMinuteJobSweepsThem(): void
    {
        $preview = $this->service->preview($this->institution, ['students' => self::STUDENTS], $this->actor);
        $folder  = $this->age($this->institution, $preview['token'], CsvUploadService::KEEP_SECONDS + 5);

        putenv('upload.csvPath=' . $this->base);

        try {
            command('exports:run');
        } finally {
            putenv('upload.csvPath');
        }

        $this->assertDirectoryDoesNotExist($folder);
    }

    public function testStartingOverRemovesTheKeptFiles(): void
    {
        $preview = $this->service->preview($this->institution, ['students' => self::STUDENTS], $this->actor);

        $this->service->discard($this->institution, $preview['token']);

        $this->expectException(PageNotFoundException::class);
        $this->service->kept($this->institution, $preview['token']);
    }

    public function testRecordsMadeByAnUploadAreNotSisOwnedWhileNoConnectionIsOn(): void
    {
        $preview = $this->service->preview($this->institution, ['students' => self::STUDENTS], $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $this->assertFalse((new \App\Services\Sis\SisOwnership())->managesPerson($this->userId('ann@example.com')));
    }

    public function testAnUploadWritesNoSisLinks(): void
    {
        $preview = $this->service->preview($this->institution, $this->files(), $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $this->assertSame(0, db_connect()->table('sis_links')->countAllResults(), 'an upload names people and courses by what they are, not by SIS ids');
    }

    public function testEachTemplateIsItsHeaderRowWithNoSisIds(): void
    {
        $this->assertSame("student_number,email,first_name,middle_name,last_name,suffix\r\n", CsvUploadService::template('students'));
        $this->assertSame("email,first_name,middle_name,last_name,suffix\r\n", CsvUploadService::template('instructors'));
        $this->assertSame("program_code,course_code,course_title,units\r\n", CsvUploadService::template('courses'));
        $this->assertSame("student_number,email,program_code,course_code,section,status\r\n", CsvUploadService::template('enrollments'));
        $this->assertSame("role,email,department_code,sub_department_code,program_code,course_code,section\r\n", CsvUploadService::template('staff_assignments'));
        $this->assertSame("program_code,course_code,section,day_of_week,start_time,end_time,room,delivery_mode\r\n", CsvUploadService::template('class_schedules'));

        foreach (CsvUploadService::datasets() as $dataset) {
            $this->assertStringNotContainsString('external_id', CsvUploadService::template($dataset));
        }
    }

    public function testAStudentIsSavedWithTheirMiddleNameAndSuffix(): void
    {
        $preview = $this->service->preview($this->institution, ['students' => self::STUDENTS], $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $profile = db_connect()->table('user_profiles')->where('user_id', $this->userId('ann@example.com'))->get()->getRowArray();

        $this->assertSame(['Ann', 'Cruz', 'Lee', 'Jr.', '2026-001'], [$profile['first_name'], $profile['middle_name'], $profile['last_name'], $profile['suffix'], $profile['student_number']]);
        $this->assertNull(db_connect()->table('user_profiles')->where('user_id', $this->userId('ben@example.com'))->get()->getRowArray()['middle_name'], 'an empty middle name stays empty');
    }

    public function testAStudentAlreadyHereIsFoundByStudentNumberSoTheirEmailCanBeCorrected(): void
    {
        $preview = $this->service->preview($this->institution, ['students' => self::STUDENTS], $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);
        $ann = $this->userId('ann@example.com');

        $preview = $this->service->preview($this->institution, ['students' => "student_number,email,first_name,last_name\n2026-001,ann.lee@example.com,Ann,Lee\n"], $this->actor);
        $kept    = $this->service->kept($this->institution, $preview['token']);

        $this->assertSame(['created' => 0, 'updated' => 1], array_intersect_key($kept['totals'], ['created' => 0, 'updated' => 0]));

        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $this->assertSame($ann, $this->userId('ann.lee@example.com'), 'the same account, with the new address');
    }

    public function testAnInstructorAlreadyHereIsFoundByEmail(): void
    {
        $ivy = PeopleFactory::person('instructor', 'Ivy', 'Tan', 'ivy@example.com', $this->institution);

        $preview = $this->service->preview($this->institution, ['instructors' => "email,first_name,middle_name,last_name,suffix\nIVY@example.com,Ivy,Mae,Tan,\n"], $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $this->assertSame('Mae', db_connect()->table('user_profiles')->where('user_id', $ivy->id)->get()->getRowArray()['middle_name']);
        $this->assertSame(1, db_connect()->table('auth_identities')->where('secret', 'ivy@example.com')->countAllResults(), 'no second account');
    }

    public function testEnrolmentsNameTheStudentByNumberOrEmailAndTheCourseByCode(): void
    {
        $preview = $this->service->preview($this->institution, $this->files(), $this->actor);
        $kept    = $this->service->kept($this->institution, $preview['token']);

        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 1], $kept['datasets']['enrollments']);

        $refused = array_values(array_filter($kept['issues'], static fn (array $i): bool => $i['dataset'] === 'enrollments' && empty($i['note'])));
        $this->assertSame('There is no course IT999 in this institution; add it in courses.csv.', $refused[0]['reason']);

        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $rows = db_connect()->table('enrollments e')
            ->select('o.section_code, e.status, e.student_user_id')
            ->join('course_offerings o', 'o.id = e.offering_id')
            ->orderBy('o.section_code')
            ->get()->getResultArray();

        $this->assertSame([
            ['section_code' => 'A', 'status' => 'enrolled', 'student_user_id' => (string) $this->userId('ann@example.com')],
            ['section_code' => 'B', 'status' => 'dropped', 'student_user_id' => (string) $this->userId('ben@example.com')],
        ], $rows);
    }

    public function testASectionNotThereYetIsCreatedAndNotedOnceButNotRefused(): void
    {
        $files                = $this->files();
        $files['enrollments'] .= ",ann@example.com,,IT101,B,enrolled\n"; // a second row into the same new section

        $preview = $this->service->preview($this->institution, $files, $this->actor);
        $kept    = $this->service->kept($this->institution, $preview['token']);
        $notes   = array_values(array_filter($kept['issues'], static fn (array $i): bool => ! empty($i['note'])));

        $this->assertSame(['IT101 A', 'IT101 B'], array_column($notes, 'item'), 'each new section once');
        $this->assertSame('Section B of IT101 is not in the active term yet, so it is created.', $notes[1]['reason']);
        $this->assertSame(1, $kept['datasets']['enrollments']['failed'], 'a note is not a refusal');
    }

    public function testASectionAlreadyThereIsNotNoted(): void
    {
        $preview = $this->service->preview($this->institution, ['courses' => self::COURSES], $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $course = (int) db_connect()->table('courses')->where('course_code', 'IT101')->get()->getRowArray()['id'];
        $term   = (int) db_connect()->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        db_connect()->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => 'A']);

        $preview = $this->service->preview($this->institution, ['students' => self::STUDENTS, 'enrollments' => "student_number,email,program_code,course_code,section,status\n2026-001,,,IT101,A,\n"], $this->actor);
        $kept    = $this->service->kept($this->institution, $preview['token']);

        $this->assertSame([], array_values(array_filter($kept['issues'], static fn (array $i): bool => ! empty($i['note']))));
        $this->assertSame(1, $kept['datasets']['enrollments']['created']);
    }

    public function testACourseCodeInMoreThanOneProgramNeedsTheProgram(): void
    {
        $db  = db_connect();
        $sub = (int) $db->query('SELECT sd.id FROM sub_departments sd JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('programs')->insert(['sub_department_id' => $sub, 'name' => 'BS Computer Science', 'code' => 'BSCS']);

        $courses = "program_code,course_code,course_title,units\nBSIT,IT101,Intro to Computing,3\nBSCS,IT101,Intro to Computing (CS),3\n";
        $preview = $this->service->preview($this->institution, ['courses' => $courses], $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $enrol   = "student_number,email,program_code,course_code,section,status\n2026-001,,,IT101,A,\n2026-001,,BSCS,IT101,A,\n";
        $preview = $this->service->preview($this->institution, ['students' => self::STUDENTS, 'enrollments' => $enrol], $this->actor);
        $kept    = $this->service->kept($this->institution, $preview['token']);
        $refused = array_values(array_filter($kept['issues'], static fn (array $i): bool => $i['dataset'] === 'enrollments' && empty($i['note'])));

        $this->assertSame(['created' => 1, 'failed' => 1], array_intersect_key($kept['datasets']['enrollments'], ['created' => 0, 'failed' => 0]));
        $this->assertSame('Course IT101 is in more than one program (BSCS, BSIT); add program_code to say which.', $refused[0]['reason']);
    }

    public function testACourseIsUpdatedByItsProgramAndCode(): void
    {
        $preview = $this->service->preview($this->institution, ['courses' => self::COURSES], $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $preview = $this->service->preview($this->institution, ['courses' => "program_code,course_code,course_title,units\nBSIT,IT101,Introduction to Computing,3\n"], $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $this->assertSame(['Introduction to Computing'], array_column(db_connect()->table('courses')->where('course_code', 'IT101')->get()->getResultArray(), 'course_title'), 'updated, not added');
    }

    public function testAStudentNotHereIsRefusedInEnrolmentsWithAWayToAddThem(): void
    {
        $preview = $this->service->preview($this->institution, ['courses' => self::COURSES, 'enrollments' => "student_number,email,program_code,course_code,section,status\n2026-404,,,IT101,A,\n"], $this->actor);
        $kept    = $this->service->kept($this->institution, $preview['token']);
        $refused = array_values(array_filter($kept['issues'], static fn (array $i): bool => $i['dataset'] === 'enrollments'));

        $this->assertSame('2026-404 is not a student of this institution; add them in students.csv or on the People page.', $refused[0]['reason']);
    }

    public function testASectionNamingABlockJoinsTheCourseToItAndEnrolsItsStudents(): void
    {
        $setup = $this->service->preview($this->institution, ['courses' => self::COURSES, 'students' => self::STUDENTS], $this->actor);
        $this->service->apply($this->institution, $setup['token'], $this->actor);

        $db      = db_connect();
        $blocks  = new ClassSectionService();
        $program = (int) $db->table('programs')->where('code', 'BSIT')->get()->getRowArray()['id'];
        $term    = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $block   = $blocks->create($this->institution, ['term_id' => (string) $term, 'program_id' => (string) $program, 'year_level' => '1', 'code' => 'BSIT 1-A', 'capacity' => '5'], $this->actor);
        $blocks->addStudents($this->institution, $block, '2026-002', false, $this->actor); // Ben is in the block

        // Ann is enrolled in IT101 of the block by the upload, without being in the block.
        $enrol   = "student_number,email,program_code,course_code,section,status
2026-001,,,IT101,BSIT 1-A,
";
        $preview = $this->service->preview($this->institution, ['enrollments' => $enrol], $this->actor);
        $kept    = $this->service->kept($this->institution, $preview['token']);
        $notes   = array_values(array_filter($kept['issues'], static fn (array $i): bool => ! empty($i['note'])));

        $this->assertSame(['IT101 BSIT 1-A'], array_column($notes, 'item'));
        $this->assertSame('IT101 is added to the block section BSIT 1-A, and its 1 student is enrolled in it.', $notes[0]['reason']);
        $this->assertSame(0, $db->table('course_offerings')->where('class_section_id', $block)->countAllResults(), 'a preview writes nothing');

        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $offering = $db->table('course_offerings')->where(['class_section_id' => $block])->get()->getRowArray();

        $this->assertSame('BSIT 1-A', $offering['section_code']);
        $this->assertSame(
            ['enrolled', 'enrolled'],
            [
                $db->table('enrollments')->where(['offering_id' => $offering['id'], 'student_user_id' => $this->userId('ann@example.com')])->get()->getRowArray()['status'],
                $db->table('enrollments')->where(['offering_id' => $offering['id'], 'student_user_id' => $this->userId('ben@example.com')])->get()->getRowArray()['status'],
            ],
            'Ann by the file, Ben because he is in the block',
        );
        $this->assertSame(1, $db->table('class_section_students')->where('class_section_id', $block)->countAllResults(), 'Ann is not made a member');
    }

    private function userId(string $email): int
    {
        return (int) db_connect()->table('auth_identities')->where(['type' => 'email_password', 'secret' => $email])->get()->getRowArray()['user_id'];
    }

    public function testAnApplyThatFailsPartWayLeavesNothingAndKeepsTheFilesForANewTry(): void
    {
        $preview = $this->service->preview($this->institution, $this->files(), $this->actor);
        $before  = $this->snapshot();

        // The audit entry is the last thing a real run writes; making it fail
        // makes the run fail after every person, course and enrolment was written.
        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                if ($action === 'sis.import_run') {
                    throw new RuntimeException('audit down');
                }

                return parent::log($action, $actor, $details);
            }
        });

        $e = $this->refused(fn () => $this->service->apply($this->institution, $preview['token'], $this->actor));

        $this->assertStringContainsString('nothing was changed', $e->getMessage());
        $this->assertSame($before, $this->snapshot(), 'no partial data');
        $this->assertSame(6, $this->service->kept($this->institution, $preview['token'])['totals']['created'], 'the files are still there');

        Services::resetSingle('auditLogger');

        $this->assertTrue($this->service->apply($this->institution, $preview['token'], $this->actor)->ok);
    }
}
