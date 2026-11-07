<?php

declare(strict_types=1);

use App\Services\Institutions\ClassSectionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The Sections pages and what they post: access, the list and its filters,
 * adding a section through the dialog's JSON (which goes on to the new
 * section), its courses and students, a foreign id answering 404, and a
 * read-only institution.
 *
 * @internal
 */
final class InstitutionSectionsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private int $term;

    private int $program;

    private int $it101;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('SECFEA');
        $this->other       = SisFixture::institution('SECFEB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@sections.example', $this->institution);

        $db = db_connect();

        $this->program = (int) $db->query('SELECT p.id FROM programs p JOIN departments sd ON sd.id = p.department_id JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $this->term    = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $db->table('courses')->insert(['program_id' => $this->program, 'course_code' => 'IT101', 'course_title' => 'Intro to Computing', 'units' => 3]);
        $this->it101 = (int) $db->insertID();

        $db->table('audit_logs')->emptyTable();

        $this->signIn($this->admin);
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function student(string $first, string $number): User
    {
        $user = PeopleFactory::person('student', $first, 'Student', strtolower($first) . '@sections.example', $this->institution);
        db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['student_number' => $number]);

        return $user;
    }

    private function section(string $code = 'BSIT 1-A', string $capacity = '3'): int
    {
        return (new ClassSectionService())->create($this->institution, ['term_id' => (string) $this->term, 'program_id' => (string) $this->program, 'year_level' => '1', 'code' => $code, 'capacity' => $capacity], $this->admin);
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseIt(string $role): void
    {
        $id = $this->section();
        $this->student('Ann', '2026-001');
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@sections.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/sections')->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', "institution/sections/{$id}")->response()->getStatusCode());

        $this->step('POST', "institution/sections/{$id}/students", ['students' => '2026-001']);
        $this->step('POST', 'institution/sections', ['term_id' => (string) $this->term, 'program_id' => (string) $this->program, 'year_level' => '1', 'code' => 'NEW', 'capacity' => '5']);

        $this->assertSame(0, db_connect()->table('class_section_students')->countAllResults());
        $this->assertSame(1, db_connect()->table('class_sections')->countAllResults(), 'no new section');
    }

    public function testTheEmptyListOffersToAddASection(): void
    {
        $body = $this->step('GET', 'institution/sections')->response()->getBody();

        $this->assertStringContainsString('No sections yet', $body);
        $this->assertStringContainsString('id="section-add"', $body);
        $this->assertStringContainsString('Add a section', $body);
    }

    public function testTheMenuAndTheAppsGridLeadToSections(): void
    {
        helper('navigation');

        $this->assertSame(site_url('institution/sections'), array_column(global_apps('institutional_admin'), 'url', 'key')['sections']);
        $this->assertStringContainsString('institution/sections', html_entity_decode($this->step('GET', 'institution/offerings')->response()->getBody()));
    }

    public function testASectionIsAddedThroughTheDialogAndTheScriptGoesOnToIt(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/sections', ['term_id' => (string) $this->term, 'program_id' => (string) $this->program, 'year_level' => '2', 'code' => 'bsit 2-a', 'capacity' => '30']);

        $this->assertTrue($result->isOK());
        $payload = json_decode($result->getJSON(), true);
        $id      = (int) db_connect()->table('class_sections')->get()->getRowArray()['id'];

        $this->assertStringEndsWith("institution/sections/{$id}", $payload['redirect']);
        $this->assertStringContainsString('The section was added', $payload['message']);

        $this->withHeaders(['Accept' => 'text/html']);
        $list = $this->step('GET', 'institution/sections')->response()->getBody();

        $this->assertStringContainsString('BSIT 2-A', $list);
        $this->assertStringContainsString('Year 2', $list);
    }

    public function testABadSectionAnswers422OnTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/sections', ['term_id' => (string) $this->term, 'program_id' => (string) $this->program, 'year_level' => '1', 'code' => '', 'capacity' => '5']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('code', json_decode($result->getJSON(), true)['errors']);
    }

    public function testTheListFiltersBySearchAndStatus(): void
    {
        $this->section('BSIT 1-A');
        $b = $this->section('BSCS 1-A');
        (new ClassSectionService())->setArchived($this->institution, $b, true, $this->admin);

        // The row's own link names it (the page's description also mentions "BSIT 1-A").
        $open = static fn (string $code): string => 'aria-label="Open ' . $code . '"';

        $searched = $this->step('GET', 'institution/sections?q=BSCS')->response()->getBody();
        $this->assertStringContainsString($open('BSCS 1-A'), $searched);
        $this->assertStringNotContainsString($open('BSIT 1-A'), $searched);

        $archived = $this->step('GET', 'institution/sections?status=archived')->response()->getBody();
        $this->assertStringContainsString($open('BSCS 1-A'), $archived);
        $this->assertStringNotContainsString($open('BSIT 1-A'), $archived);

        $active = $this->step('GET', 'institution/sections?status=active')->response()->getBody();
        $this->assertStringContainsString($open('BSIT 1-A'), $active);
        $this->assertStringNotContainsString($open('BSCS 1-A'), $active);
    }

    public function testACourseAndStudentsAreAddedAndListedOnTheSectionPage(): void
    {
        $id  = $this->section();
        $ann = $this->student('Ann', '2026-001');
        $this->withHeaders(['Accept' => 'application/json']);

        $course = json_decode($this->step('POST', "institution/sections/{$id}/courses", ['course_id' => (string) $this->it101])->getJSON(), true);
        $this->assertStringContainsString('The course was added to the section', $course['message']);

        $students = json_decode($this->step('POST', "institution/sections/{$id}/students", ['students' => "2026-001\nghost@sections.example"])->getJSON(), true);
        $this->assertStringContainsString('1 student was added', $students['message']);
        $this->assertStringContainsString('ghost@sections.example', $students['message']);

        $this->assertSame('enrolled', db_connect()->table('enrollments')->where('student_user_id', $ann->id)->get()->getRowArray()['status']);

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->step('GET', "institution/sections/{$id}")->response()->getBody();

        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString('Ann Student', $body);
        $this->assertStringContainsString('1 of 1', $body);
        $this->assertStringContainsString('id="section-students"', $body);
        $this->assertMatchesRegularExpression('/Students in the section.*1.*3/s', $body);
    }

    public function testTheAddStudentsBoxAsksForSuggestionsLeavingOutThoseAlreadyInTheSection(): void
    {
        $id   = $this->section();
        $body = html_entity_decode($this->step('GET', "institution/sections/{$id}")->response()->getBody());
        $box  = substr($body, (int) strpos($body, 'id="section-students"'));
        $box  = substr($box, 0, (int) strpos($box, 'id="section-confirm"'));

        $this->assertMatchesRegularExpression('/<textarea[^>]*name="students"[^>]*data-student-autocomplete="[^"]*institution\/enrolments\/suggest\?section=' . $id . '"/s', $box);
        $this->assertStringContainsString('autocomplete="off"', $box);
    }

    public function testTakingAStudentOutDropsTheirPlaceAndKeepsTheRow(): void
    {
        $id  = $this->section();
        $ann = $this->student('Ann', '2026-001');
        $svc = new ClassSectionService();
        $svc->addCourse($this->institution, $id, $this->it101, $this->admin);
        $svc->addStudents($this->institution, $id, '2026-001', false, $this->admin);

        $this->step('POST', "institution/sections/{$id}/students/{$ann->id}/remove");

        $this->assertSame('dropped', db_connect()->table('enrollments')->where('student_user_id', $ann->id)->get()->getRowArray()['status']);
        $this->assertStringNotContainsString('Ann Student', $this->step('GET', "institution/sections/{$id}")->response()->getBody());
    }

    public function testGoingOverCapacityNeedsTheTickBox(): void
    {
        $id = $this->section('BSIT 1-A', '1');
        $this->student('Ann', '2026-001');
        $this->student('Ben', '2026-002');
        $this->withHeaders(['Accept' => 'application/json']);

        $refused = $this->step('POST', "institution/sections/{$id}/students", ['students' => "2026-001\n2026-002"]);

        $this->assertSame(422, $refused->response()->getStatusCode());
        $this->assertArrayHasKey('over_capacity', json_decode($refused->getJSON(), true)['errors']);

        $this->assertTrue($this->step('POST', "institution/sections/{$id}/students", ['students' => "2026-001\n2026-002", 'over_capacity' => '1'])->isOK());

        $this->withHeaders(['Accept' => 'text/html']);
        $this->assertStringContainsString('Over capacity by 1', $this->step('GET', "institution/sections/{$id}")->response()->getBody());
    }

    public function testASectionIsEditedArchivedAndReactivated(): void
    {
        $id = $this->section();

        $this->step('POST', "institution/sections/{$id}", ['code' => 'BSIT 1-B', 'year_level' => '1', 'capacity' => '10']);
        $this->assertSame('BSIT 1-B', db_connect()->table('class_sections')->where('id', $id)->get()->getRowArray()['code']);

        $this->step('POST', "institution/sections/{$id}/archive");
        $this->assertSame('archived', db_connect()->table('class_sections')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertStringContainsString('Reactivate this section', $this->step('GET', "institution/sections/{$id}")->response()->getBody());

        $this->step('POST', "institution/sections/{$id}/reactivate");
        $this->assertSame('active', db_connect()->table('class_sections')->where('id', $id)->get()->getRowArray()['status']);
    }

    public function testAnotherInstitutionsSectionIs404OnEveryRoute(): void
    {
        $id = $this->section();
        $this->signIn(PeopleFactory::person('institutional_admin', 'Olga', 'Other', 'olga@sections.example', $this->other));

        foreach ([
            ['GET', "institution/sections/{$id}"],
            ['POST', "institution/sections/{$id}"],
            ['POST', "institution/sections/{$id}/archive"],
            ['POST', "institution/sections/{$id}/courses"],
            ['POST', "institution/sections/{$id}/students"],
            ['POST', "institution/sections/{$id}/students/1/remove"],
            ['POST', "institution/sections/{$id}/courses/1/remove"],
        ] as [$verb, $uri]) {
            try {
                $status = $this->step($verb, $uri, ['code' => 'X', 'year_level' => '1', 'capacity' => '5', 'students' => 'x', 'course_id' => (string) $this->it101])->response()->getStatusCode();
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $status = 404;
            }

            $this->assertSame(404, $status, "{$verb} {$uri}");
        }

        $this->assertSame('BSIT 1-A', db_connect()->table('class_sections')->where('id', $id)->get()->getRowArray()['code'], 'nothing changed');
    }

    public function testASectionOfAClosedTermCanBeLookedAtButNotChanged(): void
    {
        $id = $this->section();
        db_connect()->table('academic_terms')->where('id', $this->term)->update(['status' => 'completed']);

        $body = $this->step('GET', "institution/sections/{$id}")->response()->getBody();

        $this->assertStringContainsString('is closed, so this section cannot be changed', $body);
        $this->assertStringNotContainsString('id="section-students"', $body, 'no dialog to add students');
        $this->assertStringNotContainsString('Archive this section', $body);
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        $id = $this->section();
        $this->student('Ann', '2026-001');
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertStringContainsString('data-lock="read_only"', $this->step('GET', "institution/sections/{$id}")->response()->getBody());

        $this->step('POST', "institution/sections/{$id}/students", ['students' => '2026-001']);

        $this->assertSame(0, db_connect()->table('class_section_students')->countAllResults());
    }
}
