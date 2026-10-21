<?php

declare(strict_types=1);

use App\Services\Flags\FeatureFlags;
use App\Services\Parents\ParentPortal;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The parent's area: who lands there, what each state shows, who is turned
 * away, and that a parent can change nothing.
 *
 * @internal
 */
final class ParentRoleRoutesTest extends RouteTestCase
{
    private int $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = PeopleFactory::institution('PRR');
    }

    private function parent(): User
    {
        return PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
    }

    private function linkChild(User $parent, string $first): User
    {
        $student = PeopleFactory::person('student', $first, 'Lee', null, $this->institution);

        db_connect()->table('parent_student_links')->insert([
            'institution_id'  => $this->institution,
            'parent_user_id'  => $parent->id,
            'student_user_id' => $student->id,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        return $student;
    }

    private function portalOffGlobally(): void
    {
        db_connect()->table('feature_flag_overrides')->insert(['flag_key' => 'parent.portal', 'institution_id' => null, 'is_global' => 1, 'enabled' => 0]);
    }

    public function testASignedInParentIsSentToTheParentDashboard(): void
    {
        $this->loggedInAs($this->parent());

        $response = $this->call('GET', '/')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/parent/dashboard', $response->getHeaderLine('Location'));
    }

    public function testAParentSeesTheirLinkedChildren(): void
    {
        $parent = $this->parent();
        $this->linkChild($parent, 'Ann');
        $this->linkChild($parent, 'Ben');
        $this->loggedInAs($parent);

        $response = $this->call('GET', '/parent/dashboard')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Ann Lee', $response->getBody());
        $this->assertStringContainsString('Ben Lee', $response->getBody());
    }

    public function testTheParentRootOpensTheSamePage(): void
    {
        $parent = $this->parent();
        $this->linkChild($parent, 'Ann');
        $this->loggedInAs($parent);

        $response = $this->call('GET', '/parent')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Ann Lee', $response->getBody());
    }

    public function testAParentWithNoChildrenSeesAnExplanation(): void
    {
        $this->loggedInAs($this->parent());

        $response = $this->call('GET', '/parent/dashboard')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('not linked to a student yet', $response->getBody());
    }

    public function testAChildOfAnotherParentIsNeverShown(): void
    {
        $other = $this->parent();
        $this->linkChild($other, 'Zed');
        $this->loggedInAs($this->parent());

        $response = $this->call('GET', '/parent/dashboard')->response();

        $this->assertStringNotContainsString('Zed Lee', $response->getBody());
        $this->assertStringContainsString('not linked to a student yet', $response->getBody());
    }

    public function testASwitchedOffPortalShowsNoChildData(): void
    {
        $parent = $this->parent();
        $this->linkChild($parent, 'Ann');
        $this->portalOffGlobally();
        $this->loggedInAs($parent);

        $response = $this->call('GET', '/parent/dashboard')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('parent portal is switched off', $response->getBody());
        $this->assertStringNotContainsString('Ann Lee', $response->getBody());
    }

    public function testAnInstitutionThatSwitchedItOffShowsTheSameThing(): void
    {
        $parent = $this->parent();
        $this->linkChild($parent, 'Ann');
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($parent);

        $response = $this->call('GET', '/parent')->response();

        $this->assertStringContainsString('parent portal is switched off', $response->getBody());
        $this->assertStringNotContainsString('Ann Lee', $response->getBody());
    }

    public function testEveryOtherRoleIsTurnedAway(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'admin'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            $response = $this->call('GET', '/parent/dashboard')->response();

            $this->assertSame(302, $response->getStatusCode(), $role);
            $this->assertStringNotContainsString('/parent/dashboard', $response->getHeaderLine('Location'), $role);
        }
    }

    public function testASignedOutVisitorIsSentToTheLogin(): void
    {
        $response = $this->call('GET', '/parent/dashboard')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('login', $response->getHeaderLine('Location'));
    }

    public function testAParentActingAsSomethingElseIsTurnedAway(): void
    {
        $person = $this->parent();
        $this->linkChild($person, 'Ann');
        $person->addGroup('instructor');
        $this->loggedInAs($person);
        $this->post('role/switch', ['role' => 'instructor']);

        $response = $this->call('GET', '/parent/dashboard')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringNotContainsString('/parent/dashboard', $response->getHeaderLine('Location'));
    }

    public function testAParentCannotChangeAnything(): void
    {
        $parent = $this->parent();
        $this->linkChild($parent, 'Ann');
        $this->loggedInAs($parent);

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            foreach (['/parent/dashboard', '/parent'] as $uri) {
                try {
                    $status = $this->call($verb, $uri)->response()->getStatusCode();
                } catch (PageNotFoundException) {
                    $status = 404; // no such route: the 404 a browser gets
                }

                $this->assertContains($status, [403, 404, 405], strtoupper($verb) . ' ' . $uri);
            }
        }
    }

    public function testAReadOnlyInstitutionStillServesTheParentPage(): void
    {
        $parent = $this->parent();
        $this->linkChild($parent, 'Ann');
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);
        $this->loggedInAs($parent);

        $response = $this->call('GET', '/parent/dashboard')->response();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTheFlagIsRegistered(): void
    {
        $this->assertTrue((new FeatureFlags())->exists('parent.portal'));
    }
}
