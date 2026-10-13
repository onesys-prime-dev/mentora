<?php

declare(strict_types=1);

use App\Services\Institutions\OfferingService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The roster page and what it posts: access, the list and its filters,
 * adding students through the dialog's JSON, the capacity override, status
 * changes, foreign ids answering 404, and a read-only institution.
 *
 * @internal
 */
final class InstitutionEnrolmentsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private int $offering;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('ENRFEA');
        $this->other       = SisFixture::institution('ENRFEB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@enrol.example', $this->institution);

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro to Computing', 'units' => 3]);

        $course         = (int) $db->insertID();
        $term           = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $this->offering = (new OfferingService())->create($this->institution, ['course_id' => $course, 'term_id' => $term, 'section_code' => 'A', 'capacity' => '2'], $this->admin);

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

    private function student(string $first, string $number, ?int $institution = null): User
    {
        $user = PeopleFactory::person('student', $first, 'Student', strtolower($first) . '@enrol.example', $institution ?? $this->institution);
        db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['student_number' => $number]);

        return $user;
    }

    private function url(string $suffix = ''): string
    {
        return 'institution/enrolments/offering/' . $this->offering . $suffix;
    }

    private function page(string $query = ''): string
    {
        return $this->step('GET', $this->url() . $query)->response()->getBody();
    }

    private function placeOf(User $student): ?string
    {
        return db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $student->id])->get()->getRowArray()['status'] ?? null;
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
        $ann = $this->student('Ann', '2026-001');
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@enrol.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', $this->url())->response()->getStatusCode());

        $this->step('POST', $this->url(), ['students' => '2026-001']);

        $this->assertNull($this->placeOf($ann));
    }

    public function testTheEmptyRosterOffersToAddStudents(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('IT101 A roster', $body);
        $this->assertStringContainsString('No students enrolled yet', $body);
        $this->assertStringContainsString('id="roster-add"', $body);
        $this->assertStringContainsString('data-usage', $body);
    }

    public function testStudentsAreAddedThroughTheDialogAndListed(): void
    {
        $ann = $this->student('Ann', '2026-001');

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', $this->url(), ['students' => "2026-001\nghost@enrol.example"]);

        $this->assertTrue($result->isOK());
        $payload = json_decode($result->getJSON(), true);
        $this->assertStringContainsString('1 student was enrolled', $payload['message']);
        $this->assertStringContainsString('ghost@enrol.example', $payload['message']);
        $this->assertSame('enrolled', $this->placeOf($ann));

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->page();

        $this->assertStringContainsString('Ann Student', $body);
        $this->assertStringContainsString('2026-001', $body);
        $this->assertStringContainsString('1 / 2', $this->step('GET', 'institution/offerings')->response()->getBody());
    }

    public function testNobodyEnrolledAnswers422OnTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', $this->url(), ['students' => 'ghost@enrol.example']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('students', json_decode($result->getJSON(), true)['errors']);
    }

    public function testGoingOverCapacityNeedsTheTickBox(): void
    {
        $this->student('Ann', '2026-001');
        $this->student('Ben', '2026-002');
        $cy = $this->student('Cy', '2026-003');

        $this->withHeaders(['Accept' => 'application/json']);
        $refused = $this->step('POST', $this->url(), ['students' => "2026-001\n2026-002\n2026-003"]);

        $this->assertSame(422, $refused->response()->getStatusCode());
        $this->assertArrayHasKey('over_capacity', json_decode($refused->getJSON(), true)['errors']);
        $this->assertNull($this->placeOf($cy));

        $allowed = $this->step('POST', $this->url(), ['students' => "2026-001\n2026-002\n2026-003", 'over_capacity' => '1']);

        $this->assertTrue($allowed->isOK());
        $this->assertSame('enrolled', $this->placeOf($cy));

        $this->withHeaders(['Accept' => 'text/html']);
        $this->assertStringContainsString('Over capacity by 1', $this->page());
    }

    public function testAStatusIsChangedAndTheRowStaysListed(): void
    {
        $ann = $this->student('Ann', '2026-001');
        CourseFactory::enroll($this->offering, $ann);
        $id = (int) db_connect()->table('enrollments')->where('student_user_id', $ann->id)->get()->getRowArray()['id'];

        $this->step('POST', "institution/enrolments/{$id}/status", ['status' => 'dropped']);

        $this->assertSame('dropped', $this->placeOf($ann));
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'enrolment.status_changed')->countAllResults());

        $body = $this->page();
        $this->assertStringContainsString('Ann Student', $body);
        $this->assertStringContainsString('Dropped', $body);
    }

    public function testTheRosterIsFilteredByStatusAndSearch(): void
    {
        $ann = $this->student('Ann', '2026-001');
        $ben = $this->student('Ben', '2026-002');
        CourseFactory::enroll($this->offering, $ann);
        CourseFactory::enroll($this->offering, $ben, 'dropped');

        $dropped = $this->page('?status=dropped');
        $this->assertStringContainsString('Ben Student', $dropped);
        $this->assertStringNotContainsString('Ann Student', $dropped);
        $this->assertMatchesRegularExpression('/1 student\s+match/', $dropped);

        $this->assertStringContainsString('No students match these filters', $this->page('?q=nobody-like-this'));
        $this->assertStringContainsString('aria-sort="descending"', $this->page('?sort=number&dir=desc'));
    }

    public function testAnotherInstitutionsRosterAndPlacesAreNeverShownOrChanged(): void
    {
        $theirs  = SisFixture::course($this->other, 'C9', 'IT900');
        $student = $this->student('Zed', '2026-900', $this->other);
        CourseFactory::enroll((int) $theirs['offering'], $student);
        $id = (int) db_connect()->table('enrollments')->where('student_user_id', $student->id)->get()->getRowArray()['id'];

        foreach ([
            ['GET', 'institution/enrolments/offering/' . $theirs['offering'], []],
            ['POST', 'institution/enrolments/offering/' . $theirs['offering'], ['students' => '2026-900']],
            ['POST', "institution/enrolments/{$id}/status", ['status' => 'dropped']],
        ] as [$method, $uri, $data]) {
            try {
                $this->call($method, $uri, $data);
                $this->fail("{$uri} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('enrolled', db_connect()->table('enrollments')->where('id', $id)->get()->getRowArray()['status']);
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        $ann = $this->student('Ann', '2026-001');
        CourseFactory::enroll($this->offering, $ann);
        $id = (int) db_connect()->table('enrollments')->where('student_user_id', $ann->id)->get()->getRowArray()['id'];
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $body = $this->page();

        $this->assertStringContainsString('Ann Student', $body);
        $this->assertStringContainsString('data-lock="read_only"', $body);
        $this->assertStringNotContainsString('id="roster-add"', $body);

        $this->step('POST', "institution/enrolments/{$id}/status", ['status' => 'dropped']);
        $this->assertSame('enrolled', $this->placeOf($ann));
    }

    public function testAnArchivedOfferingShowsItsRosterWithAClosedNote(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'archived']);

        $body = $this->page();

        $this->assertStringContainsString('data-lock="closed"', $body);
        $this->assertStringNotContainsString('id="roster-add"', $body);
    }
}
