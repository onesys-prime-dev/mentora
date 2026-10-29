<?php

declare(strict_types=1);

use App\Services\Institutions\OfferingService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The Class times page of a section: the administrator enters its weekly meetings, sees clash warnings, and
 * cannot change times the SIS sends (audit decisions 13.1, 13.2, 13.4).
 *
 * @internal
 */
final class OfferingSchedulePageTest extends RouteTestCase
{
    private int $institution;

    private int $offering;

    private int $neighbour;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('SCHEDA');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@sched.example', $this->institution);

        $db      = db_connect();
        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro to Computing', 'units' => 3]);
        $course = (int) $db->insertID();
        $term   = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $offerings       = new OfferingService();
        $this->offering  = $offerings->create($this->institution, ['course_id' => $course, 'term_id' => $term, 'section_code' => 'A', 'capacity' => '40'], $this->admin);
        $this->neighbour = $offerings->create($this->institution, ['course_id' => $course, 'term_id' => $term, 'section_code' => 'B', 'capacity' => '40'], $this->admin);

        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($this->admin);
        $this->withSession();
    }

    private function url(?int $offering = null): string
    {
        return 'institution/offerings/' . ($offering ?? $this->offering) . '/schedule';
    }

    /** @return array{rows: list<array<string, string>>} the form's fields for these rows, as PHP parses rows[i][day] */
    private function form(array $rows): array
    {
        $fields = [];

        foreach ($rows as [$day, $start, $end, $room, $mode]) {
            $fields[] = ['day' => $day, 'start' => $start, 'end' => $end, 'room' => $room, 'mode' => $mode];
        }

        return ['rows' => $fields];
    }

    private function meetings(int $offering): int
    {
        return db_connect()->table('course_schedules')->where('offering_id', $offering)->countAllResults();
    }

    public function testTheAdministratorSavesClassTimesAndSeesTheWarnings(): void
    {
        $this->step('POST', $this->url($this->neighbour), $this->form([['mon', '09:30', '11:00', 'Rm 204', 'onsite']]));

        $this->step('POST', $this->url(), $this->form([['mon', '09:00', '10:30', 'Rm 204', 'onsite'], ['wed', '09:00', '10:30', 'Rm 204', 'onsite']]));
        $page = (string) $this->step('GET', $this->url())->response()->getBody();

        $this->assertSame(2, $this->meetings($this->offering));
        $this->assertStringContainsString('Room Rm 204 is also used by IT101 B on Monday 09:30–11:00.', $page);
        $this->assertStringContainsString('value="10:30"', $page, 'the saved times are on the form');
    }

    public function testAnErrorKeepsWhatWasTyped(): void
    {
        $this->step('POST', $this->url(), $this->form([['tue', '14:00', '13:00', 'Lab 9', 'onsite']]));
        $page = (string) $this->step('GET', $this->url())->response()->getBody();

        $this->assertSame(0, $this->meetings($this->offering));
        $this->assertStringContainsString('Row 1: the end time must be after the start time.', $page);
        $this->assertStringContainsString('value="Lab 9"', $page);
    }

    public function testASisOwnedSectionIsReadOnlyWithTheLock(): void
    {
        SisFixture::connect($this->institution, PeopleFactory::person('admin', 'Root', 'Admin', 'root@sched.example'));
        db_connect()->table('course_schedules')->insert(['offering_id' => $this->offering, 'day_of_week' => 'fri', 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'room' => 'Rm 1', 'delivery_mode' => 'onsite']);
        db_connect()->table('sis_class_schedules')->insert(['institution_id' => $this->institution, 'offering_id' => $this->offering, 'created_at' => date('Y-m-d H:i:s')]);

        $page = (string) $this->step('GET', $this->url())->response()->getBody();
        $this->step('POST', $this->url(), $this->form([['mon', '09:00', '10:00', '', 'onsite']]));

        $this->assertStringContainsString('From the SIS', $page);
        $this->assertStringContainsString('These class times come from your student information system.', $page);
        $this->assertStringNotContainsString('Save class times', $page);
        $this->assertSame('fri', db_connect()->table('course_schedules')->where('offering_id', $this->offering)->get()->getRow('day_of_week'), 'unchanged');
    }

    public function testAnotherInstitutionsSectionIsNotFound(): void
    {
        $other   = SisFixture::institution('SCHEDB');
        $program = (int) db_connect()->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$other])->getRow()->id;
        db_connect()->table('courses')->insert(['program_id' => $program, 'course_code' => 'ZZ1', 'course_title' => 'Elsewhere', 'units' => 3]);
        db_connect()->table('course_offerings')->insert(['course_id' => (int) db_connect()->insertID(), 'term_id' => (int) db_connect()->table('academic_terms')->where('institution_id', $other)->get()->getRow('id'), 'section_code' => 'A', 'status' => 'active']);
        $foreign = (int) db_connect()->insertID();

        try {
            $status = $this->step('GET', $this->url($foreign))->response()->getStatusCode();
            $this->assertSame(404, $status);
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAClosedSectionCannotBeSavedEvenByADirectPost(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'archived']);

        $this->step('POST', $this->url(), $this->form([['mon', '09:00', '10:00', '', 'onsite']]));
        $page = (string) $this->step('GET', $this->url())->response()->getBody();

        $this->assertSame(0, $this->meetings($this->offering));
        $this->assertStringContainsString('This section is closed, so its class times cannot be changed.', $page);
        $this->assertStringNotContainsString('Save class times', $page);
    }

    public function testARefusedSaveDoesNotPileUpBlankRows(): void
    {
        $this->step('POST', $this->url(), $this->form([['tue', '14:00', '13:00', 'Lab 9', 'onsite'], ['', '', '', '', 'onsite'], ['', '', '', '', 'onsite'], ['', '', '', '', 'onsite']]));
        $page = (string) $this->step('GET', $this->url())->response()->getBody();

        $this->assertSame(4, substr_count($page, 'data-schedule-row'), 'the typed row and three blank ones');
    }

    public function testAReadOnlyInstitutionCannotSave(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->step('POST', $this->url(), $this->form([['mon', '09:00', '10:00', '', 'onsite']]));

        $this->assertSame(0, $this->meetings($this->offering));
    }

    public function testTheOfferingsListShowsTheMeetingsAndLinksThePage(): void
    {
        $this->step('POST', $this->url(), $this->form([['mon', '09:00', '10:30', 'Rm 204', 'onsite']]));

        $list = (string) $this->step('GET', 'institution/offerings')->response()->getBody();

        $this->assertStringContainsString('Mon 09:00–10:30 · Rm 204', $list);
        $this->assertStringContainsString(site_url($this->url()), $list);
    }
}
