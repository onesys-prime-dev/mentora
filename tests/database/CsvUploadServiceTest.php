<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
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

    /** Touched by these tests; emptied with foreign keys off. */
    private const TABLES = [
        'sis_runs', 'sis_links', 'sis_connections', 'feature_flag_overrides', 'enrollments', 'course_offerings', 'courses',
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
            'students'    => SisFixture::STUDENTS,
            'instructors' => SisFixture::INSTRUCTORS,
            'courses'     => SisFixture::COURSES,
            'enrollments' => SisFixture::ENROLLMENTS,
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
        $preview = $this->service->preview($this->institution, ['students' => SisFixture::STUDENTS], $this->actor);

        $kept = $this->service->kept($this->institution, $preview['token']);

        $this->assertSame(['students'], $kept['files']);
        $this->assertSame(2, $kept['totals']['created']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadUploads(): iterable
    {
        yield 'no files' => [[], ''];
        yield 'only blanks' => [['students' => "  \n"], ''];
        yield 'a missing column' => [['students' => "external_id,email\nS1,a@b.test\n"], 'students'];
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
        $preview = $this->service->preview($this->institution, ['students' => SisFixture::STUDENTS], $this->actor);

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

    public function testStartingOverRemovesTheKeptFiles(): void
    {
        $preview = $this->service->preview($this->institution, ['students' => SisFixture::STUDENTS], $this->actor);

        $this->service->discard($this->institution, $preview['token']);

        $this->expectException(PageNotFoundException::class);
        $this->service->kept($this->institution, $preview['token']);
    }

    public function testRecordsMadeByAnUploadAreNotSisOwnedWhileNoConnectionIsOn(): void
    {
        $preview = $this->service->preview($this->institution, ['students' => SisFixture::STUDENTS], $this->actor);
        $this->service->apply($this->institution, $preview['token'], $this->actor);

        $id = (int) db_connect()->table('sis_links')->where(['institution_id' => $this->institution, 'entity' => 'person'])->get()->getRowArray()['local_id'];

        $this->assertFalse((new \App\Services\Sis\SisOwnership())->managesPerson($id));
    }

    public function testEachTemplateIsItsHeaderRow(): void
    {
        $this->assertSame("external_id,email,first_name,last_name,student_number\r\n", CsvUploadService::template('students'));
        $this->assertSame("course_external_id,student_external_id,section,status\r\n", CsvUploadService::template('enrollments'));

        foreach (CsvUploadService::datasets() as $dataset) {
            $this->assertStringContainsString('external_id', CsvUploadService::template($dataset));
        }
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
