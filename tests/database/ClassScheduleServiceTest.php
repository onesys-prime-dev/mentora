<?php

declare(strict_types=1);

use App\Services\Institutions\ClassScheduleService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * A section's weekly class times: saved and read back, validated, with clash warnings that never stop the
 * save (audit decisions 13.1, 13.3–13.5).
 *
 * @internal
 */
final class ClassScheduleServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $program;

    private int $term;

    private int $offering;

    private User $admin;

    private ClassScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $home              = DeanFactory::department('CS');
        $this->institution = $home['institution'];
        $this->program     = DeanFactory::program(DeanFactory::subDepartment($home['department'], 'CS'), 'CS');
        $this->term        = DeanFactory::term($this->institution);
        $this->offering    = DeanFactory::offering($this->program, $this->term, 'IT201', 'A', 'active', 'Data Structures');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->service     = new ClassScheduleService();
    }

    /** @return array{day: string, start: string, end: string, room: string, mode: string} */
    public function testAHandSaveHandsASectionTheSisSentBackToTheAdministrator(): void
    {
        // Sent by a file once, with no connection enabled now, so not locked.
        db_connect()->table('sis_class_schedules')->insert(['institution_id' => $this->institution, 'offering_id' => $this->offering, 'created_at' => '2026-10-10 09:00:00']);

        $this->service->save($this->institution, $this->offering, [$this->row('mon', '09:00', '10:00')], $this->admin);

        $this->assertSame(0, db_connect()->table('sis_class_schedules')->where('offering_id', $this->offering)->countAllResults(), 'a later file that leaves it out must not clear these');
    }

    public function testAClosedSectionIsRefused(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'completed']);

        $this->assertSame('This section is closed, so its class times cannot be changed.', $this->refusal([$this->row('mon', '09:00', '10:00')]));
    }

    private function row(string $day, string $start, string $end, string $room = '', string $mode = 'onsite'): array
    {
        return ['day' => $day, 'start' => $start, 'end' => $end, 'room' => $room, 'mode' => $mode];
    }

    private function refusal(array $rows): string
    {
        try {
            $this->service->save($this->institution, $this->offering, $rows, $this->admin);
        } catch (StructureException $e) {
            return $e->getMessage();
        }

        $this->fail('the rows were accepted');
    }

    public function testRowsAreSavedAndReadBackInWeekOrder(): void
    {
        $this->service->save($this->institution, $this->offering, [$this->row('wed', '13:00', '14:30', 'Rm 204'), $this->row('mon', '09:00', '10:30', 'Rm 204')], $this->admin);

        $read = $this->service->forOffering($this->institution, $this->offering);

        $this->assertFalse($read['sisOwned']);
        $this->assertSame([
            ['day' => 'mon', 'start' => '09:00', 'end' => '10:30', 'room' => 'Rm 204', 'mode' => 'onsite'],
            ['day' => 'wed', 'start' => '13:00', 'end' => '14:30', 'room' => 'Rm 204', 'mode' => 'onsite'],
        ], $read['rows']);
    }

    public function testSavingReplacesTheWeek(): void
    {
        $this->service->save($this->institution, $this->offering, [$this->row('mon', '09:00', '10:30')], $this->admin);
        $this->service->save($this->institution, $this->offering, [$this->row('tue', '08:00', '09:00')], $this->admin);

        $this->assertSame(['tue'], array_column($this->service->forOffering($this->institution, $this->offering)['rows'], 'day'));
    }

    public function testBlankRowsAreIgnored(): void
    {
        $this->service->save($this->institution, $this->offering, [$this->row('mon', '09:00', '10:00'), ['day' => '', 'start' => '', 'end' => '', 'room' => '', 'mode' => '']], $this->admin);

        $this->assertCount(1, $this->service->forOffering($this->institution, $this->offering)['rows']);
    }

    public function testAnEndNotAfterTheStartIsRefused(): void
    {
        $this->assertSame('Row 1: the end time must be after the start time.', $this->refusal([$this->row('mon', '09:00', '09:00')]));
        $this->assertSame('Row 2: the end time must be after the start time.', $this->refusal([$this->row('mon', '08:00', '09:00'), $this->row('fri', '23:00', '00:00')]), 'a class past midnight is not one row');
    }

    public function testTheSameStartTwiceOnADayIsRefused(): void
    {
        $this->assertSame('Row 2: the section already meets on Monday at 09:00.', $this->refusal([$this->row('mon', '09:00', '10:00'), $this->row('mon', '09:00', '11:00')]));
    }

    public function testAnUnknownDayOrModeIsRefused(): void
    {
        $this->assertSame('Row 1: choose a day.', $this->refusal([$this->row('funday', '09:00', '10:00')]));
        $this->assertSame('Row 1: choose onsite, online or hybrid.', $this->refusal([$this->row('mon', '09:00', '10:00', '', 'zoom')]));
    }

    public function testARoomClashWarnsButSaves(): void
    {
        $other = DeanFactory::offering($this->program, $this->term, 'IT202', 'B', 'active', 'Algorithms');
        $this->service->save($this->institution, $other, [$this->row('mon', '09:30', '11:00', 'rm 204')], $this->admin);

        $result = $this->service->save($this->institution, $this->offering, [$this->row('mon', '09:00', '10:00', 'Rm 204')], $this->admin);

        $this->assertSame(['Room Rm 204 is also used by IT202 B on Monday 09:30–11:00.'], $result['warnings']);
        $this->assertCount(1, $this->service->forOffering($this->institution, $this->offering)['rows'], 'saved anyway');
    }

    public function testAnOnlineClassNeverClashesOnRoom(): void
    {
        $other = DeanFactory::offering($this->program, $this->term, 'IT202', 'B');
        $this->service->save($this->institution, $other, [$this->row('mon', '09:30', '11:00', 'Rm 204')], $this->admin);

        $this->assertSame([], $this->service->save($this->institution, $this->offering, [$this->row('mon', '09:00', '10:00', 'Rm 204', 'online')], $this->admin)['warnings']);
    }

    public function testAnInstructorClashWarnsButSaves(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $other   = DeanFactory::offering($this->program, $this->term, 'IT202', 'B');
        CourseFactory::assignInstructor($this->offering, $teacher);
        CourseFactory::assignInstructor($other, $teacher);
        $this->service->save($this->institution, $other, [$this->row('tue', '10:00', '12:00', 'Lab 1')], $this->admin);

        $result = $this->service->save($this->institution, $this->offering, [$this->row('tue', '11:00', '12:30', 'Rm 101')], $this->admin);

        $this->assertSame(['Tess Teacher also teaches IT202 B on Tuesday 10:00–12:00.'], $result['warnings']);
    }

    public function testAnotherTermNeverClashes(): void
    {
        $later = DeanFactory::offering($this->program, DeanFactory::term($this->institution, 'Second', '2027-01-10'), 'IT202', 'A');
        $this->service->save($this->institution, $later, [$this->row('mon', '09:00', '10:00', 'Rm 204')], $this->admin);

        $this->assertSame([], $this->service->save($this->institution, $this->offering, [$this->row('mon', '09:00', '10:00', 'Rm 204')], $this->admin)['warnings']);
    }

    public function testAnotherInstitutionsOfferingIsNotFound(): void
    {
        $elsewhere = DeanFactory::department('CT');
        $foreign   = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'CT'), 'CT'), DeanFactory::term($elsewhere['institution']), 'ZZ100');

        $this->expectException(PageNotFoundException::class);

        $this->service->save($this->institution, $foreign, [$this->row('mon', '09:00', '10:00')], $this->admin);
    }

    public function testTheChangeIsAudited(): void
    {
        $this->service->save($this->institution, $this->offering, [$this->row('mon', '09:00', '10:00')], $this->admin);

        $entry = db_connect()->table('audit_logs')->where('action', 'structure.offering_schedule_changed')->get()->getRowArray();
        $this->assertNotNull($entry);
        $this->assertSame($this->offering, (int) $entry['target_id']);
    }
}
