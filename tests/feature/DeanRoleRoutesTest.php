<?php

declare(strict_types=1);

use App\Services\Institutions\DeanAssignmentService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's area: who lands there, who is turned away, and what a dean
 * with no department sees.
 *
 * @internal
 */
final class DeanRoleRoutesTest extends RouteTestCase
{
    private int $institution;
    private int $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = PeopleFactory::institution('DRR');
        db_connect()->table('departments')->insert(['institution_id' => $this->institution, 'code' => 'CC', 'name' => 'College of Computing']);
        $this->department = (int) db_connect()->insertID();
    }

    private function assignedDean(string $role = 'instructor'): User
    {
        $person = PeopleFactory::person($role, 'Dana', 'Dean', null, $this->institution);
        (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $person->id);

        return model(CodeIgniter\Shield\Models\UserModel::class)->findById($person->id);
    }

    public function testASignedInDeanIsSentToTheDeanDashboard(): void
    {
        $dean = $this->assignedDean();
        $dean->removeGroup('instructor');
        $this->loggedInAs($dean);

        $response = $this->call('GET', '/')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/dean/dashboard', $response->getHeaderLine('Location'));
    }

    public function testADeanSeesTheirDepartmentOnTheDashboard(): void
    {
        $dean = $this->assignedDean();
        $dean->removeGroup('instructor');
        $this->loggedInAs($dean);

        $response = $this->call('GET', '/dean/dashboard')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('College of Computing', $response->getBody());
    }

    public function testADeanWithoutAnAssignmentSeesAnExplanationAndNoData(): void
    {
        $dean = CourseFactory::user('dean');
        $this->loggedInAs($dean);

        $response = $this->call('GET', '/dean/dashboard')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('not assigned to a department yet', $response->getBody());
        $this->assertStringNotContainsString('College of Computing', $response->getBody());
    }

    public function testADeanWhoAlsoTeachesCanSwitchToInstructorAndBack(): void
    {
        $dean = $this->assignedDean();
        $this->loggedInAs($dean);

        $toInstructor = $this->post('role/switch', ['role' => 'instructor'])->response();
        $this->assertSame(200, $toInstructor->getStatusCode());
        $this->assertStringEndsWith('/instructor/dashboard', json_decode((string) $toInstructor->getJSON(), true)['redirect']);

        $toDean = $this->post('role/switch', ['role' => 'dean'])->response();
        $this->assertSame(200, $toDean->getStatusCode());
        $this->assertStringEndsWith('/dean/dashboard', json_decode((string) $toDean->getJSON(), true)['redirect']);
        $this->assertSame('dean', session('active_role'));
    }

    public function testAnInstructorCannotSwitchToDean(): void
    {
        $this->loggedInAs(CourseFactory::user('instructor'));

        $response = $this->post('role/switch', ['role' => 'dean'])->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull(session('active_role'));
    }

    public function testAForgedDeanRoleInTheSessionDoesNotOpenTheDeanArea(): void
    {
        $this->loggedInAs(CourseFactory::user('instructor'));
        $_SESSION['active_role'] = 'dean';

        $response = $this->call('GET', '/dean/dashboard')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringNotContainsString('/dean/dashboard', $response->getHeaderLine('Location'));
    }

    public function testADeanActingAsInstructorIsTurnedAwayFromTheDeanArea(): void
    {
        $dean = $this->assignedDean();
        $this->loggedInAs($dean);
        $this->post('role/switch', ['role' => 'instructor']);

        $response = $this->call('GET', '/dean/dashboard')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringNotContainsString('/dean/dashboard', $response->getHeaderLine('Location'));
    }
}
