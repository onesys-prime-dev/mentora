<?php

declare(strict_types=1);

use App\Services\Courses\CourseViewer;
use App\Services\Institutions\InstitutionCourseAccess;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * Who may look at which course read-only, and who sees the instructor's view:
 * the institutional administrator, for their own institution's offerings and
 * no other; and what they are never given (writing, a student's work).
 *
 * @internal
 */
final class InstitutionCourseAccessTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private function offering(int $institution): int
    {
        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'C' . $institution, 'course_title' => 'Course', 'units' => 3]);
        $course = (int) $db->insertID();
        $term   = (int) $db->table('academic_terms')->where('institution_id', $institution)->get()->getRowArray()['id'];
        $db->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => 'A', 'status' => 'active']);

        return (int) $db->insertID();
    }

    public function testTheInstitutionalAdministratorSeesTheInstructorsViewReadOnly(): void
    {
        $admin = PeopleFactory::person('institutional_admin');

        $this->assertTrue(CourseViewer::seesInstructorView($admin));
        $this->assertTrue(CourseViewer::isReadOnlyViewer($admin));
        $this->assertSame('institution', CourseViewer::urlPrefix($admin, 'institutional_admin'));
    }

    public function testAnInstructorIsTheInstructorAndNeverTheReadOnlyViewer(): void
    {
        $teacher = PeopleFactory::person('instructor');

        $this->assertTrue(CourseViewer::seesInstructorView($teacher));
        $this->assertFalse(CourseViewer::isReadOnlyViewer($teacher));
        $this->assertSame('instructor', CourseViewer::urlPrefix($teacher, 'instructor'));
    }

    public function testStudentsAndChairpersonsAreNotGivenTheInstructorsView(): void
    {
        foreach (['student', 'parent'] as $role) {
            $user = PeopleFactory::person($role);

            $this->assertFalse(CourseViewer::seesInstructorView($user), $role);
            $this->assertFalse(CourseViewer::isReadOnlyViewer($user), $role);
            $this->assertSame($role, CourseViewer::urlPrefix($user, $role));
        }
    }

    public function testSomeoneWhoIsBothAdministratorAndInstructorKeepsTheirInstructorRole(): void
    {
        $both = PeopleFactory::person('institutional_admin');
        db_connect()->table('auth_groups_users')->insert(['user_id' => $both->id, 'group' => 'instructor', 'created_at' => date('Y-m-d H:i:s')]);
        $both = model(\CodeIgniter\Shield\Models\UserModel::class)->findById((int) $both->id);

        $this->assertTrue(CourseViewer::seesInstructorView($both));
        $this->assertFalse(CourseViewer::isReadOnlyViewer($both), 'as an instructor they work in the instructor area');
    }

    public function testOnlyTheInstitutionsOwnOfferingsAreReachable(): void
    {
        $mine  = SisFixture::institution('ACCA');
        $other = SisFixture::institution('ACCB');
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $mine);

        $own     = $this->offering($mine);
        $foreign = $this->offering($other);
        $access  = new InstitutionCourseAccess();

        $this->assertTrue($access->canAccess($admin, $own));
        $this->assertFalse($access->canAccess($admin, $foreign));
        $this->assertFalse($access->canAccess($admin, 999999));

        $row = $access->offering($admin, $own);
        $this->assertSame($own, (int) $row['offering_id']);
        $this->assertSame($mine, (int) $row['institution_id']);
        $this->assertNull($access->offering($admin, $foreign));
    }

    public function testAnOfferingOfAnyStatusIsReachableToLookAt(): void
    {
        $mine  = SisFixture::institution('ACCA');
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $mine);
        $id    = $this->offering($mine);

        foreach (['completed', 'archived'] as $status) {
            db_connect()->table('course_offerings')->where('id', $id)->update(['status' => $status]);

            $this->assertTrue((new InstitutionCourseAccess())->canAccess($admin, $id), $status);
        }
    }

    public function testNobodyButTheAdministratorIsGivenThisReach(): void
    {
        $mine = SisFixture::institution('ACCA');
        $id   = $this->offering($mine);

        foreach (['student', 'instructor', 'chairperson', 'parent'] as $role) {
            $this->assertFalse((new InstitutionCourseAccess())->canAccess(PeopleFactory::person($role, 'Some', 'One', null, $mine), $id), $role);
        }
    }

    public function testACourseFileOpensOnlyForTheInstitutionAndNeverAStudentsWork(): void
    {
        $mine  = SisFixture::institution('ACCA');
        $other = SisFixture::institution('ACCB');
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $mine);
        $out   = PeopleFactory::person('institutional_admin', 'Eve', 'Elsewhere', null, $other);
        $id    = $this->offering($mine);
        $db    = db_connect();
        $now   = date('Y-m-d H:i:s');

        $db->table('files')->insert(['owner_user_id' => $admin->id, 'original_filename' => 'a.pdf', 'stored_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1, 'storage_key' => 'tests/a.pdf', 'status' => 'active', 'uploaded_at' => $now]);
        $shared = (int) $db->insertID();
        $db->table('files')->insert(['owner_user_id' => $admin->id, 'original_filename' => 'w.pdf', 'stored_filename' => 'w.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1, 'storage_key' => 'tests/w.pdf', 'status' => 'active', 'uploaded_at' => $now]);
        $work = (int) $db->insertID();

        $db->table('course_modules')->insert(['offering_id' => $id, 'title' => 'M', 'order_index' => 1, 'status' => 'published', 'created_at' => $now]);
        $module = (int) $db->insertID();
        $db->table('learning_units')->insert(['module_id' => $module, 'title' => 'U', 'order_index' => 1, 'status' => 'published']);
        $unit = (int) $db->insertID();
        $db->table('learning_items')->insert(['learning_unit_id' => $unit, 'title' => 'I', 'item_type' => 'pdf', 'order_index' => 1, 'status' => 'published']);
        $item = (int) $db->insertID();
        $db->table('learning_resources')->insert(['learning_item_id' => $item, 'file_id' => $shared, 'title' => 'Notes', 'resource_type' => 'pdf']);

        $access = new InstitutionCourseAccess();

        $this->assertTrue($access->canReadFile($admin, $shared));
        $this->assertFalse($access->canReadFile($out, $shared), 'another institution');
        $this->assertFalse($access->canReadFile($admin, $work), 'a file no course shares');
    }
}
