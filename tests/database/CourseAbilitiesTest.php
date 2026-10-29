<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use App\Services\Courses\CourseAbilities;
use CodeIgniter\Config\Services;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * Which parts of a course a person may change (audit decisions 7.4, D6).
 *
 * @internal
 */
final class CourseAbilitiesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    public function testAnInstructorMayChangeEveryPartByDefault(): void
    {
        $teacher = PeopleFactory::person('instructor', institutionId: PeopleFactory::institution('ABLA'));

        $this->assertSame(['content' => true, 'grading' => true, 'announcements' => true, 'discussions' => true], CourseAbilities::for($teacher));
    }

    public function testAPartTheInstitutionTookAwayIsViewOnly(): void
    {
        $institution = PeopleFactory::institution('ABLB');
        db_connect()->table('role_permissions')->insert(['institution_id' => $institution, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);
        PermissionMatrix::flush();

        $teacher = PeopleFactory::person('instructor', institutionId: $institution);

        $this->assertTrue(CourseAbilities::viewOnly($teacher, 'grading'));
        $this->assertFalse(CourseAbilities::viewOnly($teacher, 'content'));
    }

    public function testSomeoneWithTwoRolesIsJudgedByTheOneTheyAreActingAs(): void
    {
        $institution = PeopleFactory::institution('ABLD');
        db_connect()->table('role_permissions')->insert(['institution_id' => $institution, 'role' => 'chairperson', 'permission' => 'courses.manage', 'granted' => 0]);
        PermissionMatrix::flush();

        $both = PeopleFactory::person('chairperson', institutionId: $institution);
        $both->addGroup('instructor');

        session()->set('active_role', 'chairperson');
        $this->assertTrue(CourseAbilities::viewOnly($both, 'content'));

        session()->set('active_role', 'instructor');
        $this->assertFalse(CourseAbilities::viewOnly($both, 'content'));

        session()->remove('active_role');
    }

    public function testAReadOnlyViewerAndNobodyChangeNothing(): void
    {
        $admin = PeopleFactory::person('institutional_admin', institutionId: PeopleFactory::institution('ABLC'));

        $this->assertSame(['content' => false, 'grading' => false, 'announcements' => false, 'discussions' => false], CourseAbilities::for($admin));
        $this->assertTrue(CourseAbilities::viewOnly(null, 'content'));
    }

    public function testAStudentChangesNothing(): void
    {
        $this->assertSame(['content' => false, 'grading' => false, 'announcements' => false, 'discussions' => false], CourseAbilities::for(CourseFactory::user('student')));
    }
}
