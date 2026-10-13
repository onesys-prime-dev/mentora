<?php

declare(strict_types=1);

use App\Services\Flags\FeatureFlagAdmin;
use App\Services\Sis\SisConnectionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The course catalog page and what it posts, and the chairperson controls on
 * the structure page: access, the list with filters and paging, create / edit
 * / switch-off through the dialog's JSON, the SIS lock, foreign ids answering
 * 404, a read-only institution, and chairperson assignment.
 *
 * @internal
 */
final class InstitutionCoursesTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private int $program;

    private int $sub;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('COURSEIA');
        $this->other       = SisFixture::institution('COURSEOT');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@courses.example', $this->institution);

        $db = db_connect();

        $this->sub     = (int) $db->query('SELECT sd.id FROM sub_departments sd JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $this->program = (int) $db->table('programs')->where('sub_department_id', $this->sub)->get()->getRowArray()['id'];

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
        return $this->step('GET', 'institution/courses' . $query)->response()->getBody();
    }

    private function add(string $code = 'IT101', string $title = 'Introduction to Computing'): int
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $this->step('POST', 'institution/courses', ['program_id' => (string) $this->program, 'course_code' => $code, 'course_title' => $title, 'units' => '3']);
        $this->withHeaders(['Accept' => 'text/html']);

        return (int) db_connect()->table('courses')->where('course_code', strtoupper($code))->where('program_id', $this->program)->get()->getRowArray()['id'];
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
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@courses.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/courses')->response()->getStatusCode());

        $this->step('POST', 'institution/courses', ['program_id' => (string) $this->program, 'course_code' => 'HACK1', 'course_title' => 'Hacked course', 'units' => '3']);

        $this->assertSame(0, db_connect()->table('courses')->where('course_code', 'HACK1')->countAllResults());
    }

    public function testTheEmptyCatalogOffersTheFirstCourse(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('No courses yet', $body);
        $this->assertStringContainsString('Add a course', $body);
        $this->assertStringContainsString('id="course-add"', $body);
    }

    public function testAJsonDialogPostCreatesACourseAndTheListShowsIt(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/courses', ['program_id' => (string) $this->program, 'course_code' => 'it101', 'course_title' => 'Introduction to Computing', 'units' => '3', 'description' => '']);

        $this->assertTrue($result->isOK());
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('The course was added.', $json['message']);

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->page();

        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString('Introduction to Computing', $body);
        $this->assertStringContainsString('The course was added.', $body, 'the message waits on the list');
        $this->assertMatchesRegularExpression('/1 course\b/', $body);
    }

    public function testARefusedPostAnswers422WithTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/courses', ['program_id' => (string) $this->program, 'course_code' => 'IT101', 'course_title' => 'ab', 'units' => '3']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('course_title', json_decode($result->getJSON(), true)['errors']);
    }

    public function testASecondCourseWithTheSameCodeIsRefusedOnThePage(): void
    {
        $this->add('IT101');

        $this->step('POST', 'institution/courses', ['program_id' => (string) $this->program, 'course_code' => 'IT101', 'course_title' => 'Another course', 'units' => '3']);

        $this->assertStringContainsString('already uses the code IT101', $this->page());
        $this->assertSame(1, db_connect()->table('courses')->where('course_code', 'IT101')->countAllResults());
    }

    public function testACourseCanBeEditedSwitchedOffAndBroughtBack(): void
    {
        $id = $this->add();

        $this->step('POST', "institution/courses/{$id}", ['course_code' => 'IT101', 'course_title' => 'Computing, second edition', 'units' => '4', 'description' => 'Revised.']);
        $this->assertSame('Computing, second edition', db_connect()->table('courses')->where('id', $id)->get()->getRowArray()['course_title']);

        $this->step('POST', "institution/courses/{$id}/deactivate");
        $this->assertSame('inactive', db_connect()->table('courses')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertStringContainsString('Reactivate', $this->page());

        $this->step('POST', "institution/courses/{$id}/archive");
        $this->assertSame('archived', db_connect()->table('courses')->where('id', $id)->get()->getRowArray()['status']);

        $this->step('POST', "institution/courses/{$id}/reactivate");
        $this->assertSame('active', db_connect()->table('courses')->where('id', $id)->get()->getRowArray()['status']);

        foreach (['created', 'updated', 'deactivated', 'archived', 'reactivated'] as $what) {
            $this->assertSame(1, db_connect()->table('audit_logs')->where('action', "structure.course_{$what}")->countAllResults(), $what);
        }
    }

    public function testASisManagedCourseShowsTheLockAndRefusesItsLockedFields(): void
    {
        $id = $this->add();

        (new FeatureFlagAdmin())->setForInstitution($this->institution, 'sis.sync', 'on', 'Pilot', null, $this->admin);
        (new SisConnectionService())->save($this->institution, true, [], false, $this->admin);
        db_connect()->table('sis_links')->insert(['institution_id' => $this->institution, 'entity' => 'course', 'external_id' => 'C1', 'local_id' => $id, 'created_at' => date('Y-m-d H:i:s')]);

        $body = $this->page();
        $this->assertStringContainsString('data-dialog-locked=', $body);
        $this->assertStringContainsString('Managed by the SIS', $body);

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', "institution/courses/{$id}", ['course_code' => 'IT101', 'course_title' => 'Typed over the SIS', 'units' => '3']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertSame('Introduction to Computing', db_connect()->table('courses')->where('id', $id)->get()->getRowArray()['course_title']);
    }

    public function testTheListIsFilteredSortedAndPaged(): void
    {
        $this->add('AAA100', 'Alpha course');
        $this->add('ZZZ900', 'Omega course');

        $this->assertStringContainsString('Omega course', $this->page('?q=omega'));
        $this->assertStringNotContainsString('Alpha course', $this->page('?q=omega'));
        $this->assertMatchesRegularExpression('/1 course\s+match/', $this->page('?q=omega'));
        $this->assertStringContainsString('No courses match these filters', $this->page('?q=nothing-like-this'));
        $this->assertStringContainsString('aria-sort="descending"', $this->page('?sort=code&dir=desc'));
        $this->assertStringContainsString('Omega course', $this->page('?status=active'));
        $this->assertStringNotContainsString('Omega course', $this->page('?status=archived'));
    }

    public function testAnotherInstitutionsCoursesAreNeverShownOrChanged(): void
    {
        $db = db_connect();
        $db->table('courses')->insert(['program_id' => (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->other])->getRow()->id, 'course_code' => 'THEIRS1', 'course_title' => 'Their private course', 'units' => 3]);
        $foreign = (int) $db->insertID();

        $this->assertStringNotContainsString('Their private course', $this->page());

        foreach (["institution/courses/{$foreign}" => ['course_code' => 'X1', 'course_title' => 'Hacked title', 'units' => '3'], "institution/courses/{$foreign}/archive" => []] as $uri => $data) {
            try {
                $this->call('POST', $uri, $data);
                $this->fail("{$uri} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('active', $db->table('courses')->where('id', $foreign)->get()->getRowArray()['status']);
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        $id = $this->add();
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $body = $this->page();

        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString('data-lock="read_only"', $body);
        $this->assertStringNotContainsString('Add a course', $body);
        $this->assertStringNotContainsString('id="course-add"', $body);

        $this->step('POST', "institution/courses/{$id}/archive");
        $this->assertSame('active', db_connect()->table('courses')->where('id', $id)->get()->getRowArray()['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | Chairpersons on the structure page
    |--------------------------------------------------------------------------
    */

    public function testAChairpersonIsAssignedFromTheStructurePageAndShownThere(): void
    {
        $chair = PeopleFactory::person('chairperson', 'Cho', 'Chair', 'cho@courses.example', $this->institution);

        $this->assertStringContainsString('Cho Chair (cho@courses.example)', $this->step('GET', 'institution/structure')->response()->getBody(), 'offered in the dialog');

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', "institution/structure/sub-departments/{$this->sub}/chairpersons", ['user_id' => (string) $chair->id]);
        $this->assertSame('The chairperson was assigned.', json_decode($result->getJSON(), true)['message']);
        $this->withHeaders(['Accept' => 'text/html']);

        $body = $this->step('GET', 'institution/structure')->response()->getBody();
        $this->assertStringContainsString('data-chair=', $body);
        $this->assertStringContainsString('Cho Chair', $body);

        $assignment = (int) db_connect()->table('chairperson_assignments')->get()->getRowArray()['id'];
        $this->step('POST', "institution/structure/chairpersons/{$assignment}/remove");

        $this->assertStringContainsString('data-chairs-empty', $this->step('GET', 'institution/structure')->response()->getBody());
    }

    public function testSomeoneWhoIsNotAChairpersonIsRefused(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Teacher', null, $this->institution);

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', "institution/structure/sub-departments/{$this->sub}/chairpersons", ['user_id' => (string) $teacher->id]);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('user_id', json_decode($result->getJSON(), true)['errors']);
    }

    public function testTheCoursesTabIsOnTheStructurePageAndTheOtherWayRound(): void
    {
        $this->assertStringContainsString('institution/courses', html_entity_decode($this->step('GET', 'institution/structure')->response()->getBody()));
        $this->assertStringContainsString('institution/structure', html_entity_decode($this->page()));
    }
}
