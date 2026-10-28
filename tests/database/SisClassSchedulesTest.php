<?php

declare(strict_types=1);

use App\Services\Institutions\ClassScheduleService;
use App\Services\Institutions\StructureException;
use App\Services\Sis\SisImporter;
use App\Services\Sis\SisImportResult;
use App\Services\Sis\SisOwnership;
use App\Services\Sis\SisRunLog;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The SIS-supplied class times (class_schedules.csv, D8, D9): a section in the file gets exactly its rows and
 * the SIS owns it; a SIS section left out of the file loses its SIS times; a section the SIS never sent is
 * untouched; no file changes nothing. Clashes are warnings, and the lock holds only while the connection is
 * enabled.
 *
 * @internal
 */
final class SisClassSchedulesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const HEADER = "course_external_id,section,day_of_week,start_time,end_time,room,delivery_mode\n";

    private string $folder;

    private int $institution;

    /** @var array{course: int, offering: int} */
    private array $c1;

    /** @var array{course: int, offering: int} */
    private array $c2;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->folder      = SisFixture::useFolder();
        $this->institution = SisFixture::institution('SCS');
        $this->actor       = PeopleFactory::person('admin', 'Root', 'Admin', 'root@scs.example');

        SisFixture::connect($this->institution, $this->actor);
        SisFixture::priorSuccess($this->institution);

        $this->c1 = SisFixture::course($this->institution, 'C1', 'IT101');
        $this->c2 = SisFixture::course($this->institution, 'C2', 'IT102');
    }

    protected function tearDown(): void
    {
        SisFixture::cleanup($this->folder);

        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function run_(bool $dry = false): SisImportResult
    {
        return (new SisImporter())->run($this->institution, $this->actor, $dry, SisRunLog::MANUAL, new DateTimeImmutable('2026-10-10 09:00:00'));
    }

    /** Drops class_schedules.csv with these rows (each "course,section,day,start,end,room,mode") and runs. */
    private function sync(array $rows, bool $dry = false): SisImportResult
    {
        SisFixture::drop($this->institution, 'class_schedules', self::HEADER . implode("\n", $rows) . ($rows === [] ? '' : "\n"));

        return $this->run_($dry);
    }

    /** @return list<string> the section's meetings, as "day start-end room" */
    private function meetings(int $offering): array
    {
        $rows = db_connect()->table('course_schedules')->where('offering_id', $offering)
            ->orderBy("FIELD(day_of_week, 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'), start_time", '', false)->get()->getResultArray();

        return array_map(static fn (array $r): string => trim($r['day_of_week'] . ' ' . substr($r['start_time'], 0, 5) . '-' . substr($r['end_time'], 0, 5) . ' ' . $r['room']), $rows);
    }

    private function byHand(int $offering, string $day, string $start, string $end, ?string $room = null): void
    {
        db_connect()->table('course_schedules')->insert(['offering_id' => $offering, 'day_of_week' => $day, 'start_time' => $start . ':00', 'end_time' => $end . ':00', 'room' => $room, 'delivery_mode' => 'onsite']);
    }

    public function testASectionInTheFileGetsExactlyItsRowsAndIsLocked(): void
    {
        $this->byHand($this->c1['offering'], 'tue', '08:00', '09:00');

        $result = $this->sync(['C1,,mon,09:00,10:30,Rm 204,', 'C1,A,Wednesday,09:00,10:30,,online']);

        $this->assertSame(['mon 09:00-10:30 Rm 204', 'wed 09:00-10:30'], $this->meetings($this->c1['offering']), 'the hand-entered row is replaced');
        $this->assertSame('online', db_connect()->table('course_schedules')->where(['offering_id' => $this->c1['offering'], 'day_of_week' => 'wed'])->get()->getRow('delivery_mode'));
        $this->assertSame(1, $result->datasets['class_schedules']['updated']);
        $this->assertSame(0, $result->datasets['class_schedules']['failed']);
        $this->assertTrue((new SisOwnership())->managesSchedule($this->c1['offering']));

        $this->expectException(StructureException::class);
        $this->expectExceptionMessage(ClassScheduleService::SIS_LOCK);

        (new ClassScheduleService())->save($this->institution, $this->c1['offering'], [['day' => 'fri', 'start' => '10:00', 'end' => '11:00']], $this->actor);
    }

    public function testASisSectionLeftOutLosesItsSisTimes(): void
    {
        $this->sync(['C1,A,mon,09:00,10:00,,', 'C2,A,tue,09:00,10:00,,']);

        $result = $this->sync(['C2,A,tue,09:00,10:00,,']);

        $this->assertSame([], $this->meetings($this->c1['offering']));
        $this->assertFalse((new SisOwnership())->managesSchedule($this->c1['offering']), 'handed back to the administrator');
        $this->assertSame(1, $result->datasets['class_schedules']['removed']);
        $this->assertSame(1, $result->datasets['class_schedules']['skipped'], 'C2 is unchanged');
        $this->assertStringContainsString('removed 1', $result->message);
    }

    public function testASectionTheSisNeverSentIsUntouched(): void
    {
        $this->byHand($this->c2['offering'], 'fri', '13:00', '14:00', 'Lab 1');

        $result = $this->sync(['C1,A,mon,09:00,10:00,,']);

        $this->assertSame(['fri 13:00-14:00 Lab 1'], $this->meetings($this->c2['offering']));
        $this->assertFalse((new SisOwnership())->managesSchedule($this->c2['offering']));
        $this->assertSame(0, $result->datasets['class_schedules']['removed']);
        $this->assertSame(1, $result->datasets['class_schedules']['created']);
    }

    public function testNoFileChangesNothing(): void
    {
        $this->sync(['C1,A,mon,09:00,10:00,,']);
        unlink(\App\Services\Sis\SisConnectors::folders($this->institution)['inbound'] . DIRECTORY_SEPARATOR . 'class_schedules.csv');
        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name\n");

        $result = $this->run_();

        $this->assertArrayNotHasKey('class_schedules', $result->datasets);
        $this->assertSame(['mon 09:00-10:00'], $this->meetings($this->c1['offering']));
        $this->assertTrue((new SisOwnership())->managesSchedule($this->c1['offering']));
    }

    public function testASecondRowAtTheSameDayAndStartFailsAlone(): void
    {
        $this->sync(['C2,A,thu,09:00,10:00,,']);

        // "Monday" and "mon" are the same day, so the parser's own check does not see the repeat.
        $result = $this->sync(['C1,A,mon,09:00,10:00,,', 'C1,A,Monday,09:00,11:00,,', 'C1,A,tue,09:00,10:00,,']);

        $this->assertSame(['mon 09:00-10:00', 'tue 09:00-10:00'], $this->meetings($this->c1['offering']), 'the other rows still apply');
        $this->assertSame(1, $result->datasets['class_schedules']['failed']);
        $this->assertStringContainsString('already meets on Monday at 09:00', $result->issues[0]['reason']);
        $this->assertSame([], $this->meetings($this->c2['offering']), 'a failed row does not stop the file being whole: C2, left out, is cleared');
    }

    public function testARowThatCouldNotBeReadStopsTheRemovals(): void
    {
        $this->sync(['C2,A,thu,09:00,10:00,,']);

        $result = $this->sync(['C1,A,mon,09:00,10:00,,', 'C1,A,mon,09:00,10:00,,']);

        $this->assertSame(['thu 09:00-10:00'], $this->meetings($this->c2['offering']), 'the unreadable row might have listed C2');
        $this->assertSame(0, $result->datasets['class_schedules']['removed']);
        $this->assertSame(['mon 09:00-10:00'], $this->meetings($this->c1['offering']));
    }

    /** That a preview writes nothing is in SisImporterRollbackTest: inside this class's own transaction it cannot be seen. */
    public function testAPreviewCountsTheRemovals(): void
    {
        $this->sync(['C1,A,mon,09:00,10:00,,']);

        $result = $this->sync(['C2,A,tue,09:00,10:00,,'], true);

        $this->assertTrue($result->dryRun);
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'removed' => 1], $result->datasets['class_schedules']);
        $this->assertStringStartsWith('Preview, nothing was written', $result->message);
    }

    public function testClashesAreReportedAsWarnings(): void
    {
        $this->byHand($this->c2['offering'], 'mon', '09:00', '10:00', 'Rm 1');

        $result = $this->sync(['C1,A,mon,09:30,10:30,rm 1,onsite']);

        $this->assertSame(['mon 09:30-10:30 rm 1'], $this->meetings($this->c1['offering']), 'a clash does not stop the import');
        $this->assertSame(0, $result->datasets['class_schedules']['failed']);
        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('Room rm 1 is also used by IT102 A on Monday 09:00–10:00.', $result->issues[0]['reason']);
    }

    public function testTheLockEndsWhenTheConnectionIsDisabled(): void
    {
        $this->sync(['C1,A,mon,09:00,10:00,,']);

        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->update(['enabled' => 0]);

        $this->assertFalse((new SisOwnership())->managesSchedule($this->c1['offering']));
        (new ClassScheduleService())->save($this->institution, $this->c1['offering'], [['day' => 'fri', 'start' => '10:00', 'end' => '11:00']], $this->actor);
        $this->assertSame(['fri 10:00-11:00'], $this->meetings($this->c1['offering']));
    }
}
