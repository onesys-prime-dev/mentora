<?php

declare(strict_types=1);

use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The student's own profile page: their name, email and student number, and
 * which parents can see their progress. Read only, names only, and never
 * anyone else's.
 *
 * @internal
 */
final class StudentProfilePageTest extends RouteTestCase
{
    private int $institution;

    private User $ann;

    private User $pam;

    private User $olga;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('SPP');
        $this->ann         = PeopleFactory::person('student', 'Ann', 'Lee', 'ann@school.example', $this->institution);
        $this->pam         = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@family.example', $this->institution);
        $this->olga        = PeopleFactory::person('parent', 'Olga', 'Another', 'olga@family.example', $this->institution);

        db_connect()->table('user_profiles')->where('user_id', $this->ann->id)->update(['student_number' => '2026-001']);

        $this->link($this->pam, $this->ann);
        $this->link($this->olga, $this->ann);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function link(User $parent, User $student): void
    {
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{int, string} */
    private function profile(): array
    {
        try {
            $response = $this->call('GET', '/student/profile')->response();

            return [$response->getStatusCode(), (string) $response->getBody()];
        } catch (PageNotFoundException) {
            return [404, ''];
        }
    }

    public function testTheStudentSeesTheirOwnDetailsAndTheirParentsByName(): void
    {
        $this->loggedInAs($this->ann);

        [$status, $body] = $this->profile();

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertStringContainsString('ann@school.example', $body);
        $this->assertStringContainsString('2026-001', $body);
        $this->assertStringContainsString('Parents who can see your progress', $body);
        $this->assertStringContainsString('Olga Another', $body);
        $this->assertStringContainsString('Pam Parent', $body);
        $this->assertLessThan(strpos($body, 'Pam Parent'), strpos($body, 'Olga Another'), 'in name order');
    }

    public function testOnlyNamesAreShownNeverAParentsEmail(): void
    {
        $this->loggedInAs($this->ann);

        [, $body] = $this->profile();

        $this->assertStringNotContainsString('pam@family.example', $body);
        $this->assertStringNotContainsString('olga@family.example', $body);
    }

    public function testWithNoParentTheStudentIsToldSo(): void
    {
        db_connect()->table('parent_student_links')->emptyTable();
        $this->loggedInAs($this->ann);

        [, $body] = $this->profile();

        $this->assertStringContainsString('No parent can see your progress', $body);
        $this->assertStringNotContainsString('Pam Parent', $body);
    }

    public function testAParentWhoCannotSeeAnythingIsNotListed(): void
    {
        db_connect()->table('users')->where('id', $this->olga->id)->update(['active' => 0]);
        $this->loggedInAs($this->ann);

        [, $body] = $this->profile();

        $this->assertStringContainsString('Pam Parent', $body);
        $this->assertStringNotContainsString('Olga Another', $body);
    }

    public function testWhilePortalIsOffNoParentIsListed(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($this->ann);

        [, $body] = $this->profile();

        $this->assertStringContainsString('No parent can see your progress', $body);
        $this->assertStringNotContainsString('Pam Parent', $body);
    }

    public function testAnotherStudentsParentsAreNeverListed(): void
    {
        $zed = PeopleFactory::person('student', 'Zed', 'Other', null, $this->institution);
        $una = PeopleFactory::person('parent', 'Una', 'Unrelated', null, $this->institution);
        $this->link($una, $zed);
        $this->loggedInAs($this->ann);

        [, $body] = $this->profile();

        $this->assertStringNotContainsString('Una Unrelated', $body);
    }

    public function testAStudentWithoutANumberSaysNotSet(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->ann->id)->update(['student_number' => null]);
        $this->loggedInAs($this->ann);

        [, $body] = $this->profile();

        $this->assertStringContainsString('Not set', $body);
    }

    public function testEveryOtherRoleIsTurnedAway(): void
    {
        foreach (['instructor', 'chairperson', 'institutional_admin', 'admin', 'parent'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            $this->assertSame(302, $this->profile()[0], $role);
        }
    }

    public function testThereIsNothingToChangeOnIt(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $routes = array_filter(array_keys(service('routes')->loadRoutes()->getRoutes($verb)), static fn (string $route): bool => str_starts_with($route, 'student/profile'));

            $this->assertSame([], array_values($routes), $verb);
        }
    }

    public function testTheMenuLinksStudentsAndParentsToTheirOwnProfile(): void
    {
        $this->loggedInAs($this->ann);
        $student = (string) $this->call('GET', '/student/profile')->response()->getBody();

        $this->assertMatchesRegularExpression('#href="[^"]*student/profile"[^>]*>(?:(?!</a>).)*My Profile#s', $student);

        $this->loggedInAs($this->pam);
        $parent = (string) $this->call('GET', '/parent/profile')->response()->getBody();

        $this->assertMatchesRegularExpression('#href="[^"]*parent/profile"[^>]*>(?:(?!</a>).)*My Profile#s', $parent);
    }

    public function testOtherRolesKeepTheInertMenuItem(): void
    {
        $this->loggedInAs(CourseFactory::user('instructor'));

        $body = (string) $this->call('GET', '/instructor/dashboard')->response()->getBody();

        $this->assertDoesNotMatchRegularExpression('#href="[^"]*/profile"[^>]*>(?:(?!</a>).)*My Profile#s', $body);
    }
}
