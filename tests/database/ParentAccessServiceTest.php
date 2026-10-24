<?php

declare(strict_types=1);

use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * Which children a parent may reach, and which of their courses: only
 * students linked to the parent in the parent's own institution, derived
 * live from the links.
 *
 * @internal
 */
final class ParentAccessServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private User $parent;

    private ParentAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();

        // The settings service remembers values in memory; a switch a test sets must not outlive it.
        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PAS');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->access      = new ParentAccessService();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function child(string $first, ?int $institution = null, ?User $parent = null): User
    {
        $institution ??= $this->institution;
        $student       = PeopleFactory::person('student', $first, 'Lee', null, $institution);

        $this->link($parent ?? $this->parent, $student, $institution);

        return $student;
    }

    private function link(User $parent, User $student, ?int $institution = null): void
    {
        db_connect()->table('parent_student_links')->insert([
            'institution_id'  => $institution ?? $this->institution,
            'parent_user_id'  => $parent->id,
            'student_user_id' => $student->id,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    public function testAParentSeesTheirLinkedChildrenByName(): void
    {
        $this->child('Ben');
        $this->child('Ann');

        $children = $this->access->children($this->parent);

        $this->assertSame(['Ann Lee', 'Ben Lee'], array_column($children, 'name'), 'sorted by name');
        $this->assertSame($this->institution, $children[0]['institution_id']);
    }

    public function testAStudentWhoIsNotLinkedIsNotAChild(): void
    {
        $this->child('Ann');
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);

        $this->assertFalse($this->access->canViewChild($this->parent, (int) $stranger->id));
        $this->assertCount(1, $this->access->children($this->parent));
    }

    public function testAnotherParentsChildIsNotMine(): void
    {
        $otherParent = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        $theirs      = $this->child('Tom', null, $otherParent);

        $this->assertFalse($this->access->canViewChild($this->parent, (int) $theirs->id));
        $this->assertTrue($this->access->canViewChild($otherParent, (int) $theirs->id));
    }

    public function testALinkIsOnlyGoodInTheInstitutionItWasMadeIn(): void
    {
        $elsewhere = PeopleFactory::institution('PAX');
        $this->child('Eve', $elsewhere);

        $this->assertSame([], $this->access->children($this->parent));
    }

    public function testAStudentWhoLeftTheInstitutionIsNoLongerReachable(): void
    {
        $ann = $this->child('Ann');

        db_connect()->table('institution_users')->where('user_id', $ann->id)->delete();

        $this->assertSame([], $this->access->children($this->parent));
        $this->assertFalse($this->access->canViewChild($this->parent, (int) $ann->id));
    }

    public function testADeactivatedStudentStaysVisibleForTheirHistory(): void
    {
        $ann = $this->child('Ann');

        db_connect()->table('users')->where('id', $ann->id)->update(['active' => 0]);

        $this->assertCount(1, $this->access->children($this->parent));
        $this->assertTrue($this->access->canViewChild($this->parent, (int) $ann->id));
    }

    public function testADeletedStudentIsGone(): void
    {
        $ann = $this->child('Ann');

        db_connect()->table('users')->where('id', $ann->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([], $this->access->children($this->parent));
    }

    public function testOnlyTheParentsCurrentInstitutionCounts(): void
    {
        $this->child('Ann');
        $second = PeopleFactory::institution('PAY');
        $this->child('Bea', $second);

        $this->assertSame(['Ann Lee'], array_column($this->access->children($this->parent), 'name'));
    }

    public function testNobodyWhoIsNotAParentHasChildren(): void
    {
        $instructor = PeopleFactory::person('instructor', 'Ian', 'Teacher', null, $this->institution);
        $student    = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->link($instructor, $student);

        $this->assertSame([], $this->access->children($instructor));
        $this->assertFalse($this->access->canViewChild($instructor, (int) $student->id));
    }

    public function testNonsenseIdsAreRefused(): void
    {
        $this->child('Ann');

        foreach ([0, -1, PHP_INT_MAX] as $id) {
            $this->assertFalse($this->access->canViewChild($this->parent, $id), (string) $id);
            $this->assertFalse($this->access->canViewOffering($this->parent, $id, 1), (string) $id);
        }

        $this->assertFalse($this->access->canViewOffering($this->parent, (int) $this->child('Bea')->id, 0));
    }

    public function testTheChildCanBeLoadedAsAUserOnlyWhenReachable(): void
    {
        $ann      = $this->child('Ann');
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        $other    = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        $theirs   = $this->child('Tom', null, $other);

        $this->assertSame((int) $ann->id, (int) $this->access->childUser($this->parent, (int) $ann->id)?->id);
        $this->assertNull($this->access->childUser($this->parent, (int) $stranger->id));
        $this->assertNull($this->access->childUser($this->parent, (int) $theirs->id));
        $this->assertNull($this->access->childUser($this->parent, 0));

        db_connect()->table('users')->where('id', $ann->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->assertNull($this->access->childUser($this->parent, (int) $ann->id));
    }

    public function testAParentReachesTheOfferingsTheirChildIsIn(): void
    {
        $offering = CourseFactory::offering();
        $ann      = $this->child('Ann');
        CourseFactory::enroll($offering, $ann);

        $this->assertTrue($this->access->canViewOffering($this->parent, (int) $ann->id, $offering));
    }

    public function testADroppedOrCompletedEnrolmentStaysVisible(): void
    {
        $dropped = CourseFactory::offering('B');
        $done    = CourseFactory::offering('C');
        $ann     = $this->child('Ann');
        CourseFactory::enroll($dropped, $ann, 'dropped');
        CourseFactory::enroll($done, $ann, 'completed');

        $this->assertTrue($this->access->canViewOffering($this->parent, (int) $ann->id, $dropped));
        $this->assertTrue($this->access->canViewOffering($this->parent, (int) $ann->id, $done));
    }

    public function testOnlyAPlainStudentIsReadAsAStudent(): void
    {
        $plain = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->assertTrue(ParentAccessService::readsAsStudent($plain));

        foreach (['instructor', 'chairperson', 'dean', 'institutional_admin'] as $role) {
            $mixed = PeopleFactory::person('student', 'Mix', $role, null, $this->institution);
            $mixed->addGroup($role);

            $this->assertFalse(ParentAccessService::readsAsStudent($mixed), $role);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The parents a student has (who can reach them now)
    |--------------------------------------------------------------------------
    */

    public function testParentsOfListsOnlyThoseWhoCanReachTheStudentNow(): void
    {
        $ann = $this->child('Ann');

        $inactive = PeopleFactory::person('parent', 'Gone', 'Parent', null, $this->institution);
        $deleted  = PeopleFactory::person('parent', 'Deleted', 'Parent', null, $this->institution);
        $notAPar  = PeopleFactory::person('instructor', 'Not', 'Parent', null, $this->institution);
        $left     = PeopleFactory::person('parent', 'Left', 'Parent', null, $this->institution);

        foreach ([$inactive, $deleted, $notAPar, $left] as $person) {
            $this->link($person, $ann);
        }

        db_connect()->table('users')->where('id', $inactive->id)->update(['active' => 0]);
        db_connect()->table('users')->where('id', $deleted->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('institution_users')->where('user_id', $left->id)->delete();

        $this->assertSame([(int) $this->parent->id], array_column($this->access->parentsOf((int) $ann->id), 'parent_id'));
    }

    public function testParentsOfIsEmptyOnceTheStudentHasLeftTheInstitution(): void
    {
        $ann = $this->child('Ann');
        db_connect()->table('institution_users')->where('user_id', $ann->id)->delete();

        $this->assertSame([], $this->access->parentsOf((int) $ann->id));
        $this->assertSame([], $this->access->parentsOf(0));
    }

    public function testTheNamesAParentCanBeShownByAreSortedAndNothingMore(): void
    {
        $ann   = $this->child('Ann');
        $olga  = PeopleFactory::person('parent', 'Olga', 'Another', 'olga@family.test', $this->institution);
        $this->link($olga, $ann);

        $names = $this->access->parentNamesWithAccess((int) $ann->id);

        $this->assertSame(['Olga Another', 'Pam Parent'], $names);
    }

    public function testNoNamesWhileThePortalIsSwitchedOff(): void
    {
        $ann = $this->child('Ann');
        (new ParentPortal())->setInstitutionOn($this->institution, false);

        $this->assertSame([], $this->access->parentNamesWithAccess((int) $ann->id));
    }

    public function testAnotherStudentsParentsAreNeverListed(): void
    {
        $ann = $this->child('Ann');
        $zed = PeopleFactory::person('student', 'Zed', 'Other', null, $this->institution);
        $una = PeopleFactory::person('parent', 'Una', 'Other', null, $this->institution);
        $this->link($una, $zed);

        $this->assertSame(['Pam Parent'], $this->access->parentNamesWithAccess((int) $ann->id));
    }

    public function testTheOfferingRowIsGivenForEveryEnrolmentStatusAndOnlyToTheRightParent(): void
    {
        $current = CourseFactory::offering('G');
        $dropped = CourseFactory::offering('H');
        $other   = CourseFactory::offering('I');
        $ann     = $this->child('Ann');
        CourseFactory::enroll($current, $ann);
        CourseFactory::enroll($dropped, $ann, 'dropped');

        $row = $this->access->childOffering($this->parent, (int) $ann->id, $current);

        $this->assertSame($current, (int) $row['offering_id']);
        $this->assertNotSame('', (string) $row['course_title']);
        $this->assertSame('dropped', $this->access->childOffering($this->parent, (int) $ann->id, $dropped)['enrollment_status']);
        $this->assertNull($this->access->childOffering($this->parent, (int) $ann->id, $other));
        $this->assertNull($this->access->childOffering($this->parent, (int) $ann->id, 0));

        $stranger = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        $this->assertNull($this->access->childOffering($stranger, (int) $ann->id, $current));
    }

    public function testAnOfferingTheChildIsNotInIsRefused(): void
    {
        $theirs = CourseFactory::offering('D');
        $other  = CourseFactory::offering('E');
        $ann    = $this->child('Ann');
        CourseFactory::enroll($theirs, $ann);

        $this->assertFalse($this->access->canViewOffering($this->parent, (int) $ann->id, $other));
    }

    public function testAnOfferingOfAnUnlinkedStudentIsRefused(): void
    {
        $offering = CourseFactory::offering('F');
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        CourseFactory::enroll($offering, $stranger);

        $this->assertFalse($this->access->canViewOffering($this->parent, (int) $stranger->id, $offering));
    }
}
