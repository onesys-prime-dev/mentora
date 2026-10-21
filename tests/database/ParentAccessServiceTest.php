<?php

declare(strict_types=1);

use App\Services\Parents\ParentAccessService;
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

        $this->institution = PeopleFactory::institution('PAS');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->access      = new ParentAccessService();
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
        db_connect()->table('institution_users')->insert(['institution_id' => $second, 'user_id' => $this->parent->id, 'created_at' => date('Y-m-d H:i:s')]);
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
