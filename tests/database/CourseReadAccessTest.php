<?php

declare(strict_types=1);

use App\Services\Auth\ActiveRoleService;
use App\Services\Courses\CourseReadAccess;
use App\Services\Courses\CourseViewer;
use App\Services\Institutions\DeanAssignmentService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Who may look at a course without teaching it, and which: the dean (their
 * department, only while acting as dean) and the institution's administrator
 * (their institution). Everyone else has no read-only view.
 *
 * @internal
 */
final class CourseReadAccessTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private CourseReadAccess $access;
    private int $institution;
    private int $department;
    private int $own;
    private int $sibling;
    private int $foreign;
    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        session()->destroy();

        $home              = DeanFactory::department('H');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $term              = DeanFactory::term($this->institution);
        $next              = DeanFactory::department('N', $this->institution);
        $elsewhere         = DeanFactory::department('X');

        $this->own     = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($this->department, 'H'), 'H'), $term, 'IT101');
        $this->sibling = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($next['department'], 'N'), 'N'), $term, 'LW100');
        $this->foreign = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X'), DeanFactory::term($elsewhere['institution']), 'ZZ900');

        $this->dean   = DeanFactory::dean($this->institution, $this->department);
        $this->access = new CourseReadAccess();
    }

    private function teachingDean(): User
    {
        $person = PeopleFactory::person('instructor', 'Dora', 'Dean', null, $this->institution);
        (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $person->id);

        return model(CodeIgniter\Shield\Models\UserModel::class)->findById($person->id);
    }

    public function testADeanReachesTheirDepartmentsOfferingsAndNoOthers(): void
    {
        $this->assertTrue($this->access->canAccess($this->dean, $this->own));
        $this->assertFalse($this->access->canAccess($this->dean, $this->sibling), 'another department of the same institution');
        $this->assertFalse($this->access->canAccess($this->dean, $this->foreign), 'another institution');
        $this->assertFalse($this->access->canAccess($this->dean, 999999));
    }

    public function testTheInstitutionsAdministratorReachesEveryOfferingOfTheirInstitution(): void
    {
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        $this->assertTrue($this->access->canAccess($admin, $this->own));
        $this->assertTrue($this->access->canAccess($admin, $this->sibling));
        $this->assertFalse($this->access->canAccess($admin, $this->foreign));
    }

    public function testNobodyElseHasAReadOnlyView(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'parent'] as $role) {
            $this->assertFalse($this->access->canAccess(PeopleFactory::person($role, 'Some', 'One', null, $this->institution), $this->own), $role);
        }
    }

    public function testADeanWithoutAnAssignmentOrWithAnEndedOneReachesNothing(): void
    {
        $this->assertFalse($this->access->canAccess(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution), $this->own));

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertFalse($this->access->canAccess($this->dean, $this->own));
    }

    public function testOfferingIsTheSharedRowWithTheDepartmentNameOrNull(): void
    {
        $row = $this->access->offering($this->dean, $this->own);

        $this->assertSame($this->own, (int) $row['offering_id']);
        $this->assertSame('IT101', $row['course_code']);
        $this->assertSame('College H', $row['department_name']);
        $this->assertNull($this->access->offering($this->dean, $this->sibling));
        $this->assertNull($this->access->offering($this->dean, $this->foreign));
    }

    public function testASharedFileIsReadableOnlyThroughACourseThePersonMayLookAt(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Tom', 'Teacher', null, $this->institution);
        $shared  = CourseFactory::file($teacher, 'notes');
        $mine    = CourseFactory::file($teacher, 'sibling notes');
        $loose   = CourseFactory::file($teacher, 'unshared');
        db_connect()->table('files')->whereIn('id', [$shared['id'], $mine['id'], $loose['id']])->update(['status' => 'active']);

        [$item]    = CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->own)), [['title' => 'Notes']]);
        [$sibItem] = CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->sibling)), [['title' => 'Notes']]);
        CourseFactory::resource($item, 'pdf', null, $shared['id']);
        CourseFactory::resource($sibItem, 'pdf', null, $mine['id']);

        $this->assertTrue($this->access->canReadFile($this->dean, $shared['id']));
        $this->assertFalse($this->access->canReadFile($this->dean, $mine['id']), 'shared by a course of another department');
        $this->assertFalse($this->access->canReadFile($this->dean, $loose['id']), 'shared by no course');
    }

    public function testADeanActingAsDeanIsAViewerWithTheDeanPrefix(): void
    {
        $this->assertTrue(CourseViewer::isDeanViewer($this->dean));
        $this->assertTrue(CourseViewer::isReadOnlyViewer($this->dean));
        $this->assertFalse(CourseViewer::isInstitutionViewer($this->dean));
        $this->assertSame('dean', CourseViewer::urlPrefix($this->dean, 'dean'));
        $this->assertTrue(CourseViewer::seesInstructorView($this->dean));
    }

    public function testADeanWhoAlsoTeachesIsAViewerOnlyWhileActingAsDean(): void
    {
        $dean    = $this->teachingDean();
        $service = new ActiveRoleService();

        $this->assertSame('dean', $service->resolve($dean));
        $this->assertTrue(CourseViewer::isReadOnlyViewer($dean));
        $this->assertSame('dean', CourseViewer::urlPrefix($dean, 'instructor'));

        $service->switchTo($dean, 'instructor');

        $this->assertFalse(CourseViewer::isDeanViewer($dean));
        $this->assertFalse(CourseViewer::isReadOnlyViewer($dean));
        $this->assertSame('instructor', CourseViewer::urlPrefix($dean, 'instructor'));
        $this->assertTrue(CourseViewer::seesInstructorView($dean), 'as an instructor they see their courses as one');
    }

    public function testTheInstitutionsAdministratorKeepsTheirOwnViewAndPrefix(): void
    {
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        $this->assertTrue(CourseViewer::isInstitutionViewer($admin));
        $this->assertFalse(CourseViewer::isDeanViewer($admin));
        $this->assertSame('institution', CourseViewer::urlPrefix($admin, 'institutional_admin'));

        $teachingAdmin = PeopleFactory::person('institutional_admin', 'Ira', 'Admin', null, $this->institution);
        $teachingAdmin->addGroup('instructor');
        $this->assertFalse(CourseViewer::isReadOnlyViewer($teachingAdmin), 'an administrator who also teaches sees courses as an instructor');
    }

    public function testStudentsInstructorsAndChairpersonsAreNotViewers(): void
    {
        foreach (['student', 'instructor', 'chairperson'] as $role) {
            $person = PeopleFactory::person($role, 'Some', 'One', null, $this->institution);

            $this->assertFalse(CourseViewer::isReadOnlyViewer($person), $role);
            $this->assertSame($role, CourseViewer::urlPrefix($person, $role), $role);
        }
    }
}
