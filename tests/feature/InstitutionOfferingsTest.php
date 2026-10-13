<?php

declare(strict_types=1);

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

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
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
        $body = $this->page();

        $this->assertStringContainsString('No offerings yet', $body);
        $this->assertStringContainsString('Add an offering', $body);
        $this->assertStringContainsString('id="offering-add"', $body);
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
