<?php

declare(strict_types=1);

use App\Services\Institutions\ClassSectionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The Offerings page and what it posts: access, the list with filters and
 * the "no instructor" flag, create / edit / archive through the dialog's
 * JSON, instructor assignment, foreign ids answering 404, and a read-only
 * institution.
 *
 * @internal
 */
final class InstitutionOfferingsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private int $course;

    private int $term;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('OFFERIA');
        $this->other       = SisFixture::institution('OFFEROT');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@offer.example', $this->institution);

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN departments sd ON sd.id = p.department_id JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro to Computing', 'units' => 3]);

        $this->course = (int) $db->insertID();
        $this->term   = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

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

    private function page(string $query = ''): string
    {
        return $this->step('GET', 'institution/offerings' . $query)->response()->getBody();
    }

    private function add(string $section = 'A'): int
    {
        $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'term_id' => (string) $this->term, 'section_code' => $section, 'capacity' => '40']);

        return (int) db_connect()->table('course_offerings')->where(['course_id' => $this->course, 'section_code' => strtoupper($section)])->get()->getRowArray()['id'];
    }

    /** A block section of the institution's term (the Sections page's table), which an offering is added to. */
    private function section(string $code = 'BSIT 1-A', string $capacity = '30', ?int $institution = null): int
    {
        $institution ??= $this->institution;
        $db           = db_connect();
        $program      = (int) $db->query('SELECT p.id FROM programs p JOIN departments sd ON sd.id = p.department_id JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$institution])->getRow()->id;
        $term         = (int) $db->table('academic_terms')->where('institution_id', $institution)->get()->getRowArray()['id'];

        return (new ClassSectionService())->create($institution, ['term_id' => (string) $term, 'program_id' => (string) $program, 'year_level' => '1', 'code' => $code, 'capacity' => $capacity], $this->admin);
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
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@offer.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/offerings')->response()->getStatusCode());

        $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'term_id' => (string) $this->term, 'section_code' => 'HACK', 'capacity' => '40']);

        $this->assertSame(0, db_connect()->table('course_offerings')->where('section_code', 'HACK')->countAllResults());
    }

    public function testTheEmptyPageOffersTheFirstOffering(): void
    {
        $this->section();

        $body = $this->page();

        $this->assertStringContainsString('No offerings yet', $body);
        $this->assertStringContainsString('Add an offering', $body);
        $this->assertStringContainsString('id="offering-add"', $body);
    }

    public function testTheAddDialogChoosesASectionFromTheSectionsTableAndNoTermOrCapacity(): void
    {
        $id = $this->section('BSIT 1-A');
        $this->section('BSIT 2-A');
        (new ClassSectionService())->setArchived($this->institution, $this->section('BSIT 3-A'), true, $this->admin);
        $this->section('THEIRS', '30', $this->other);

        $body = html_entity_decode($this->page());
        $add  = substr($body, (int) strpos($body, 'id="offering-add"'));
        $add  = substr($add, 0, (int) strpos($add, 'id="offering-edit"'));

        $this->assertMatchesRegularExpression('/<select[^>]*name="class_section_id"[^>]*required/s', $add);
        $this->assertStringContainsString("value=\"{$id}\"", $add);
        $this->assertStringContainsString('BSIT 1-A · BSIT year 1 · First Term 2026-2027', $add);
        $this->assertStringContainsString('BSIT 2-A', $add);
        $this->assertStringNotContainsString('BSIT 3-A', $add, 'an archived section is not offered');
        $this->assertStringNotContainsString('THEIRS', $add, 'another institution has its own sections');
        $this->assertStringNotContainsString('name="section_code"', $add, 'the section is chosen, not typed');
        $this->assertStringNotContainsString('name="term_id"', $add, 'its term is the section term');
        $this->assertStringNotContainsString('name="capacity"', $add, 'and so is its capacity');
    }

    public function testWithNoSectionThePageSaysToAddOneFirst(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('Add a section first', $body);
        $this->assertStringNotContainsString('id="offering-add"', $body);
    }

    public function testAnOfferingIsAddedToASectionAndTakesItsTermNameAndCapacity(): void
    {
        $section = $this->section('BSIT 1-A', '30');
        $ann     = PeopleFactory::person('student', 'Ann', 'Student', 'ann@offer.example', $this->institution);
        db_connect()->table('user_profiles')->where('user_id', $ann->id)->update(['student_number' => '2026-001']);
        (new ClassSectionService())->addStudents($this->institution, $section, '2026-001', false, $this->admin);

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'class_section_id' => (string) $section, 'description' => 'Mornings.']);

        $this->assertTrue($result->isOK());
        $this->assertSame('The offering was added and 1 student of the section was enrolled in it.', json_decode($result->getJSON(), true)['message']);

        $offering = db_connect()->table('course_offerings')->where(['course_id' => $this->course])->get()->getRowArray();

        $this->assertSame(['BSIT 1-A', (string) $section, (string) $this->term, '30', 'Mornings.'], [$offering['section_code'], $offering['class_section_id'], $offering['term_id'], $offering['capacity'], $offering['description']]);
        $this->assertSame('enrolled', db_connect()->table('enrollments')->where(['offering_id' => $offering['id'], 'student_user_id' => $ann->id])->get()->getRowArray()['status']);

        $this->withHeaders(['Accept' => 'text/html']);
        $this->assertStringContainsString('BSIT 1-A', $this->page());
    }

    public function testASectionIsRequiredAndAForeignOneIs404(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $none = $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'class_section_id' => '']);
        $this->assertSame(422, $none->response()->getStatusCode());
        $this->assertArrayHasKey('class_section_id', json_decode($none->getJSON(), true)['errors']);

        $theirs = $this->section('THEIRS', '30', $this->other);

        try {
            $status = $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'class_section_id' => (string) $theirs])->response()->getStatusCode();
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            $status = 404;
        }

        $this->assertSame(404, $status);
        $this->assertSame(0, db_connect()->table('course_offerings')->countAllResults());
    }

    public function testACourseAlreadyInTheSectionIsRefusedOnTheCourseField(): void
    {
        $section = $this->section();
        $this->withHeaders(['Accept' => 'application/json']);

        $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'class_section_id' => (string) $section]);
        $again = $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'class_section_id' => (string) $section]);

        $this->assertSame(422, $again->response()->getStatusCode());
        $this->assertArrayHasKey('course_id', json_decode($again->getJSON(), true)['errors']);
    }

    public function testAnOfferingOfASectionCannotBeRenamedHereButItsCapacityCanChange(): void
    {
        $section = $this->section();
        $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'class_section_id' => (string) $section]);
        $id = (int) db_connect()->table('course_offerings')->where('course_id', $this->course)->get()->getRowArray()['id'];
        $this->withHeaders(['Accept' => 'application/json']);

        $renamed = $this->step('POST', "institution/offerings/{$id}", ['section_code' => 'OTHER', 'capacity' => '30']);
        $this->assertSame(422, $renamed->response()->getStatusCode());
        $this->assertStringContainsString('Rename the section on the Sections page', json_decode($renamed->getJSON(), true)['errors']['section_code']);

        $this->assertTrue($this->step('POST', "institution/offerings/{$id}", ['section_code' => 'BSIT 1-A', 'capacity' => '25'])->isOK());
        $this->assertSame('25', db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray()['capacity']);
    }

    public function testWithNoCourseOrTermThePageSaysWhatToAddFirst(): void
    {
        db_connect()->table('courses')->where('id', $this->course)->update(['status' => 'archived']);

        $body = $this->page();

        $this->assertStringContainsString('Add a course first', $body);
        $this->assertStringNotContainsString('id="offering-add"', $body);
    }

    public function testAnOfferingIsAddedThroughTheDialogAndListedWithNoInstructorFlag(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'term_id' => (string) $this->term, 'section_code' => 'a', 'capacity' => '35', 'description' => '']);

        $this->assertTrue($result->isOK());
        $this->assertSame('The offering was added.', json_decode($result->getJSON(), true)['message']);

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->page();

        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString('Intro to Computing', $body);
        $this->assertStringContainsString('data-no-instructor', $body);
        $this->assertMatchesRegularExpression('/0 \/ 35/', $body);
    }

    public function testARefusedOfferingAnswers422WithTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'term_id' => (string) $this->term, 'section_code' => 'A', 'capacity' => '0']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('capacity', json_decode($result->getJSON(), true)['errors']);
    }

    public function testADuplicateSectionIsRefusedOnThePage(): void
    {
        $this->add('A');
        $this->step('POST', 'institution/offerings', ['course_id' => (string) $this->course, 'term_id' => (string) $this->term, 'section_code' => 'A', 'capacity' => '40']);

        $this->assertStringContainsString('already has a section A', $this->page());
        $this->assertSame(1, db_connect()->table('course_offerings')->where('course_id', $this->course)->countAllResults());
    }

    public function testAnOfferingCanBeEditedArchivedAndBroughtBack(): void
    {
        $id = $this->add('A');

        $this->step('POST', "institution/offerings/{$id}", ['section_code' => 'A', 'capacity' => '25', 'description' => 'Evening.']);
        $this->assertSame(25, (int) db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray()['capacity']);

        $this->step('POST', "institution/offerings/{$id}/archive");
        $this->assertSame('archived', db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertStringContainsString('Reactivate', $this->page());

        $this->step('POST', "institution/offerings/{$id}/reactivate");
        $this->assertSame('active', db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray()['status']);

        foreach (['created', 'updated', 'archived', 'reactivated'] as $what) {
            $this->assertSame(1, db_connect()->table('audit_logs')->where('action', "structure.offering_{$what}")->countAllResults(), $what);
        }
    }

    public function testAnOfferingWithStudentsCannotBeArchivedAndTheReasonIsShown(): void
    {
        $id = $this->add('A');
        CourseFactory::enroll($id, PeopleFactory::person('student', 'Stu', 'One', null, $this->institution));

        $this->step('POST', "institution/offerings/{$id}/archive");

        $this->assertStringContainsString('1 student enrolled', $this->page());
        $this->assertSame('active', db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray()['status']);
    }

    public function testAnInstructorIsAssignedShownAndRemoved(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', 'ivy@offer.example', $this->institution);
        $id      = $this->add('A');

        $this->assertStringContainsString('Ivy Tan (ivy@offer.example)', $this->page(), 'offered in the dialog');

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', "institution/offerings/{$id}/instructors", ['user_id' => (string) $teacher->id]);
        $this->assertSame('The instructor was assigned.', json_decode($result->getJSON(), true)['message']);
        $this->withHeaders(['Accept' => 'text/html']);

        $body = $this->page();
        $this->assertStringContainsString('data-instructor=', $body);
        $this->assertStringNotContainsString('data-no-instructor', $body);

        $assignment = (int) db_connect()->table('instructor_assignments')->where('offering_id', $id)->get()->getRowArray()['id'];
        $this->step('POST', "institution/offerings/instructors/{$assignment}/remove");

        $this->assertStringContainsString('data-no-instructor', $this->page());
    }

    public function testSomeoneWhoIsNotAnInstructorIsRefused(): void
    {
        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $id      = $this->add('A');

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', "institution/offerings/{$id}/instructors", ['user_id' => (string) $student->id]);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('user_id', json_decode($result->getJSON(), true)['errors']);
    }

    public function testTheListIsFilteredByInstructorAndSearch(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', null, $this->institution);
        $taught  = $this->add('A');
        $this->add('B');
        $this->step('POST', "institution/offerings/{$taught}/instructors", ['user_id' => (string) $teacher->id]);

        $none = $this->page('?instructor=none');
        $this->assertMatchesRegularExpression('/1 offering\s+match/', $none);
        $this->assertStringContainsString('data-no-instructor', $none);

        $mine = $this->page('?instructor=' . $teacher->id);
        $this->assertMatchesRegularExpression('/1 offering\s+match/', $mine);
        $this->assertStringNotContainsString('data-no-instructor', $mine);

        $this->assertStringContainsString('No offerings match these filters', $this->page('?q=nothing-like-this'));
        $this->assertStringContainsString('aria-sort="descending"', $this->page('?sort=section&dir=desc'));
    }

    public function testAnotherInstitutionsOfferingsAreNeverShownOrChanged(): void
    {
        $theirs = SisFixture::course($this->other, 'C9', 'IT900');

        $this->assertStringNotContainsString('IT900', $this->page());

        foreach ([
            ["institution/offerings/{$theirs['offering']}", ['section_code' => 'Z', 'capacity' => '10']],
            ["institution/offerings/{$theirs['offering']}/archive", []],
            ["institution/offerings/{$theirs['offering']}/instructors", ['user_id' => '1']],
        ] as [$uri, $data]) {
            try {
                $this->call('POST', $uri, $data);
                $this->fail("{$uri} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('active', db_connect()->table('course_offerings')->where('id', $theirs['offering'])->get()->getRowArray()['status']);
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        $id = $this->add('A');
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $body = $this->page();

        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString('data-lock="read_only"', $body);
        $this->assertStringNotContainsString('Add an offering', $body);
        $this->assertStringNotContainsString('id="offering-add"', $body);

        $this->step('POST', "institution/offerings/{$id}/archive");
        $this->assertSame('active', db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray()['status']);
    }

    public function testTheAppsTileAndTheAcademicsTabsReachTheOfferings(): void
    {
        $dashboard = html_entity_decode($this->step('GET', 'institution/dashboard')->response()->getBody());

        $this->assertSame(1, preg_match('/<a\s[^>]*href="[^"]*institution\/offerings"[^>]*data-app-tile="offerings"/s', $dashboard));

        foreach (['structure', 'courses', 'terms'] as $tab) {
            $this->assertStringContainsString('institution/offerings', html_entity_decode($this->step('GET', "institution/{$tab}")->response()->getBody()), $tab);
        }
    }
}
