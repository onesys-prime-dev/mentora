<?php

declare(strict_types=1);

use App\Services\Sis\SisConnectors;
use App\Services\Sis\SisGradeExporter;
use App\Services\Sis\SisRunLog;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\FakeSisConnector;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The outbound grade export: what is sent (final grades only, new or changed),
 * that a retry or a repeat never duplicates, and what happens when delivery
 * fails or is not allowed. (A crash between sending and recording is in
 * SisGradeExporterRollbackTest, which needs a real outer transaction.)
 *
 * @internal
 */
final class SisGradeExporterTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    protected bool $isolateTests = true;

    private string $folder;

    private int $institution;

    private User $actor;

    private int $offering;

    private User $ann;

    private User $ben;

    private FakeSisConnector $sis;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->folder      = SisFixture::useFolder();
        $this->institution = SisFixture::institution('GRADES');
        $this->actor       = PeopleFactory::person('admin', 'Root', 'Admin', 'root@example.com');
        $this->now         = new DateTimeImmutable('2026-10-10 09:00:00');
        $this->sis         = new FakeSisConnector();

        SisFixture::connect($this->institution, $this->actor);

        $this->offering = SisFixture::course($this->institution)['offering'];
        $this->ann      = SisFixture::student($this->institution, 'S1', 'ann@example.com');
        $this->ben      = SisFixture::student($this->institution, 'S2', 'ben@example.com');

        SisFixture::grade($this->offering, $this->ann, 88.5);
        SisFixture::grade($this->offering, $this->ben, 70.0, 76.0);
    }

    protected function tearDown(): void
    {
        SisFixture::cleanup($this->folder);

        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function export(bool $dry = false, ?FakeSisConnector $connector = null, int $attempts = 3): \App\Services\Sis\SisImportResult
    {
        return (new SisGradeExporter(null, null, $connector ?? $this->sis))->run($this->institution, $this->actor, $dry, SisRunLog::MANUAL, $this->now, $attempts);
    }

    private function command(string $line): string
    {
        $this->setUpStreamFilterTrait();
        $this->resetStreamFilterBuffer();

        try {
            command($line);

            return $this->getStreamFilterBuffer();
        } finally {
            $this->tearDownStreamFilterTrait();
        }
    }

    /** @return list<array<string, string>> the rows of the only delivery */
    private function theDelivery(): array
    {
        $this->assertCount(1, $this->sis->deliveries);

        return array_values($this->sis->deliveries)[0];
    }

    public function testFinalGradesAreSentWithTheOverrideWinningAndNothingElse(): void
    {
        $result = $this->export();

        $this->assertTrue($result->ok);
        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 0], $result->datasets['grades']);

        $rows = $this->theDelivery();

        $this->assertSame([
            ['course_external_id' => 'C1', 'student_external_id' => 'S1', 'percentage' => '88.50', 'remarks' => 'Passed'],
            ['course_external_id' => 'C1', 'student_external_id' => 'S2', 'percentage' => '76.00', 'remarks' => 'Passed'],
        ], $rows);
    }

    public function testWhatWasSentIsRecordedAndTheRunIsLogged(): void
    {
        $result = $this->export();

        $sent = db_connect()->table('sis_grade_exports')->orderBy('student_id')->get()->getResultArray();
        $this->assertCount(2, $sent);
        $this->assertSame('88.50', $sent[0]['percentage']);
        $this->assertSame(array_key_first($this->sis->deliveries), $sent[0]['batch_key']);
        $this->assertSame('2026-10-10 09:00:00', $sent[0]['sent_at']);

        $run = db_connect()->table('sis_runs')->where('id', $result->runId)->get()->getRowArray();
        $this->assertSame('outbound', $run['direction']);
        $this->assertSame('succeeded', $run['status']);
        $this->assertSame([2, 0, 0, 0], [(int) $run['created_count'], (int) $run['updated_count'], (int) $run['skipped_count'], (int) $run['failed_count']]);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'sis.grades_sent')->countAllResults());
    }

    public function testASecondRunWithNothingNewSendsNothing(): void
    {
        $this->export();
        $this->sis->calls = 0;

        $again = $this->export();

        $this->assertTrue($again->ok);
        $this->assertSame(0, $this->sis->calls);
        $this->assertStringContainsString('No new or changed', $again->message);
        $this->assertSame(1, count($this->sis->deliveries));
    }

    public function testOnlyAChangedGradeIsSentAgainAndTheRestAreLeftOut(): void
    {
        $this->export();

        db_connect()->table('gradebook_records')->where('student_id', $this->ann->id)->update(['calculated_percentage' => 91.0, 'final_percentage' => 91.0]);

        $result = $this->export();

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 1, 'failed' => 0], $result->datasets['grades']);
        $this->assertCount(2, $this->sis->deliveries, 'a different delivery');
        $this->assertSame([['course_external_id' => 'C1', 'student_external_id' => 'S1', 'percentage' => '91.00', 'remarks' => 'Passed']], array_values(array_slice($this->sis->deliveries, -1))[0]);
    }

    public function testAFailedDeliveryIsRetriedAndDeliveredOnce(): void
    {
        $this->sis->failTimes = 2;

        $result = $this->export();

        $this->assertTrue($result->ok);
        $this->assertSame(3, $this->sis->calls);
        $this->assertCount(1, $this->sis->deliveries);
        $this->assertSame(2, db_connect()->table('sis_grade_exports')->countAllResults());
    }

    public function testADeliveryThatKeepsFailingSendsNothingMarksNothingAndTellsTheAdministrators(): void
    {
        $this->sis->failTimes = 99;

        $result = $this->export();

        $this->assertFalse($result->ok);
        $this->assertSame(3, $this->sis->calls);
        $this->assertStringContainsString('after 3 attempt', $result->message);
        $this->assertSame([], $this->sis->deliveries);
        $this->assertSame(0, db_connect()->table('sis_grade_exports')->countAllResults());
        $this->assertSame('failed', db_connect()->table('sis_runs')->where('id', $result->runId)->get()->getRowArray()['status']);
        $this->assertSame(1, db_connect()->table('notifications')->where('type', 'sis.sync_failed')->where('user_id', $this->actor->id)->countAllResults());

        $title = db_connect()->table('notifications')->where('type', 'sis.sync_failed')->get()->getRowArray()['title'];
        $this->assertStringContainsString('grade export failed', $title);
    }

    public function testTheNextRunAfterAFailureSendsTheSameDeliveryKey(): void
    {
        $failing            = new FakeSisConnector();
        $failing->failTimes = 99;
        $this->export(false, $failing, 1);

        $this->export();
        $key = array_key_first($this->sis->deliveries);

        $other = new FakeSisConnector();
        db_connect()->table('sis_grade_exports')->emptyTable();
        $this->export(false, $other);

        $this->assertSame($key, array_key_first($other->deliveries), 'the same grades, the same key: a re-send replaces, it does not add');
    }

    public function testAPreviewCountsAndSendsOrRecordsNothing(): void
    {
        $result = $this->export(true);

        $this->assertTrue($result->ok);
        $this->assertTrue($result->dryRun);
        $this->assertSame(2, $result->datasets['grades']['created']);
        $this->assertSame(0, $this->sis->calls);
        $this->assertSame(0, db_connect()->table('sis_grade_exports')->countAllResults());
        $this->assertSame(1, (int) db_connect()->table('sis_runs')->where('id', $result->runId)->get()->getRowArray()['dry_run']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'sis.grades_previewed')->countAllResults());
    }

    public function testGradesNobodyHasGivenYetAreNotSent(): void
    {
        $cy = SisFixture::student($this->institution, 'S3', 'cy@example.com');
        SisFixture::grade($this->offering, $cy, null, null, 'Incomplete');

        $result = $this->export();

        $this->assertSame(2, $result->datasets['grades']['created']);
        $this->assertSame(1, $result->datasets['grades']['skipped']);
        $this->assertCount(2, $this->theDelivery());
    }

    public function testGradesThatAreNotFinalizedYetAreNotSent(): void
    {
        $open = SisFixture::student($this->institution, 'S6', 'open2@example.com');
        SisFixture::grade($this->offering, $open, 72.0, null, 'Passed', false);

        $result = $this->export();

        $this->assertSame(2, $result->datasets['grades']['created'], 'only the finalized grades go');
        $this->assertCount(2, $this->theDelivery());
    }

    public function testTheFrozenFinalGradeIsWhatIsSentNotTheLiveCalculation(): void
    {
        db_connect()->table('gradebook_records')->where('student_id', $this->ann->id)->update(['calculated_percentage' => 99.0, 'final_percentage' => 60.0]);

        $this->export();

        $sent = array_column($this->theDelivery(), 'percentage', 'student_external_id');
        $this->assertSame('60.00', $sent['S1']);
    }

    public function testAWithdrawnStudentAndAnIncompleteAreNotSent(): void
    {
        $gone = SisFixture::student($this->institution, 'S4', 'gone@example.com');
        $open = SisFixture::student($this->institution, 'S5', 'open@example.com');
        SisFixture::grade($this->offering, $gone, 60.0, null, 'Withdrawn');
        SisFixture::grade($this->offering, $open, 55.0, null, 'INC');

        $result = $this->export();

        $this->assertSame(2, $result->datasets['grades']['created']);
        $this->assertSame(2, $result->datasets['grades']['skipped']);
        $this->assertCount(2, $this->theDelivery());
    }

    /** @return array<string, string> record => reason of the latest run's left-out grades */
    private function leftOut(\App\Services\Sis\SisImportResult $result): array
    {
        return array_column($result->issues, 'reason', 'item');
    }

    public function testAPreviewSaysWhoIsLeftOutAndWhy(): void
    {
        $gone = SisFixture::student($this->institution, 'S4', 'gone@example.com');
        $open = SisFixture::student($this->institution, 'S5', 'open@example.com');
        $late = SisFixture::student($this->institution, 'S6', 'late@example.com');
        SisFixture::grade($this->offering, $gone, 60.0, null, 'Withdrawn');
        SisFixture::grade($this->offering, $open, 55.0, null, 'INC');
        SisFixture::grade($this->offering, $late, 72.0, null, 'Passed', false);

        $result = $this->export(true);

        $left = $this->leftOut($result);

        $this->assertSame(3, $result->datasets['grades']['skipped']);
        $this->assertSame('The student withdrew, so there is no final grade', $left['Stu S4 · IT101-A'] ?? null);
        $this->assertSame('Marked Incomplete (INC), not settled yet', $left['Stu S5 · IT101-A'] ?? null);
        $this->assertSame('The grades are not finalized yet', $left['IT101-A (1 student)'] ?? null, 'a section that is not final is one line with a count');
    }

    public function testGradesAlreadySentAndUnchangedAreNotListedAsProblems(): void
    {
        $this->export();

        $result = $this->export(true);

        $this->assertSame([], $result->issues);
        $this->assertSame(2, $result->datasets['grades']['skipped'], 'still counted as left out');
    }

    public function testWhatWasLeftOutIsKeptWithTheRun(): void
    {
        $gone = SisFixture::student($this->institution, 'S4', 'gone@example.com');
        SisFixture::grade($this->offering, $gone, 60.0, null, 'Withdrawn');

        $result = $this->export(true);

        $details = json_decode((string) db_connect()->table('sis_runs')->where('id', $result->runId)->get()->getRowArray()['details'], true);
        $this->assertCount(1, $details['issues']);
        $this->assertFalse($details['truncated']);
    }

    public function testAStudentTheSisDoesNotKnowIsListedToo(): void
    {
        $local = PeopleFactory::person('student', 'Local', 'Person', 'local2@example.com', $this->institution);
        SisFixture::grade($this->offering, $local, 99.0);

        $result = $this->export(true);

        $this->assertContains('The course or student is not known to the SIS', array_column($result->issues, 'reason'));
    }

    public function testAStudentOrCourseTheSisDoesNotKnowIsNotSent(): void
    {
        $local = PeopleFactory::person('student', 'Local', 'Person', 'local@example.com', $this->institution);
        SisFixture::grade($this->offering, $local, 99.0);

        $unknownCourse = SisFixture::course($this->institution, 'C2', 'IT102');
        db_connect()->table('sis_links')->where('external_id', 'C2')->delete();
        SisFixture::grade($unknownCourse['offering'], $this->ann, 60.0);

        $this->export();

        $this->assertCount(2, $this->theDelivery());
    }

    public function testAnotherInstitutionsGradesAreNeverSent(): void
    {
        $other = SisFixture::institution('OTHERG');
        SisFixture::connect($other, $this->actor);
        $theirs  = SisFixture::course($other, 'C1', 'IT101');
        $student = SisFixture::student($other, 'S1', 'theirs@example.com');
        SisFixture::grade($theirs['offering'], $student, 50.0);

        $this->export();

        $this->assertCount(2, $this->theDelivery());
        $this->assertSame(0, db_connect()->table('sis_grade_exports')->where('institution_id', $other)->countAllResults());
    }

    public function testItWritesASignedFileThroughTheFileConnectorAndARepeatReplacesIt(): void
    {
        $exporter = new SisGradeExporter();

        $first = $exporter->run($this->institution, $this->actor, false, SisRunLog::MANUAL, $this->now);
        $this->assertTrue($first->ok);

        $outbound = SisConnectors::folders($this->institution)['outbound'];
        $files    = glob($outbound . DIRECTORY_SEPARATOR . 'grades-*.csv') ?: [];

        $this->assertCount(1, $files);
        $this->assertStringContainsString('C1,S1,88.50,Passed', (string) file_get_contents($files[0]));

        // Forget what was sent: the same grades give the same file name again.
        db_connect()->table('sis_grade_exports')->emptyTable();
        $exporter->run($this->institution, $this->actor, false, SisRunLog::MANUAL, $this->now);

        $this->assertSame($files, glob($outbound . DIRECTORY_SEPARATOR . 'grades-*.csv'));
    }

    /*
    |--------------------------------------------------------------------------
    | When it is allowed
    |--------------------------------------------------------------------------
    */

    public function testItNeedsTheFeature(): void
    {
        db_connect()->table('feature_flag_overrides')->where('institution_id', $this->institution)->delete();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('feature is off');

        $this->export();
    }

    public function testItNeedsSavedSettings(): void
    {
        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->delete();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Save the connection settings');

        $this->export(true);
    }

    public function testSendingNeedsTheConnectionEnabledButAPreviewDoesNot(): void
    {
        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->update(['enabled' => 0]);

        $this->assertTrue($this->export(true)->ok);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not enabled');

        $this->export();
    }

    public function testOnlyAnActiveInstitutionSends(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('active institution');

        $this->export();
    }

    public function testAnUnknownInstitutionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SisGradeExporter())->run(999999, $this->actor, true);
    }

    public function testTheCommandSendsForEveryEnabledInstitution(): void
    {
        $output = $this->command('sis:grades');

        $this->assertStringContainsString("Institution {$this->institution}: Grades sent: 2 new", $output);
        $this->assertSame('scheduled', db_connect()->table('sis_runs')->where('institution_id', $this->institution)->orderBy('id', 'desc')->get()->getRowArray()['trigger']);
    }

    public function testTheCommandCanBeLimitedAndPreviewed(): void
    {
        $output = $this->command('sis:grades --institution ' . $this->institution . ' --dry-run');

        $this->assertStringContainsString('Preview, nothing was sent', $output);
        $this->assertSame(0, db_connect()->table('sis_grade_exports')->countAllResults());
    }

    public function testTheCommandSkipsAnInstitutionThatCannotSendAndSaysSoWhenNothingIsEnabled(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertStringContainsString("Institution {$this->institution}: skipped", $this->command('sis:grades'));

        db_connect()->table('sis_connections')->update(['enabled' => 0]);

        $this->assertStringContainsString('No institution has an enabled', $this->command('sis:grades'));
    }

    public function testTheCommandReportsAFailedExportAsAnError(): void
    {
        // The real file connector cannot write: a file sits where the folder should be.
        $outbound = SisConnectors::folders($this->institution)['outbound'];
        mkdir(dirname($outbound), 0770, true);
        file_put_contents($outbound, 'a file, not a folder');

        $output = $this->command('sis:grades');

        $this->assertStringContainsString("Institution {$this->institution}: failed", $output);
    }
}
