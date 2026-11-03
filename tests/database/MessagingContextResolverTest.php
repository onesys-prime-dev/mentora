<?php

declare(strict_types=1);

use App\Services\Messaging\MessagingContextResolver;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The database facts behind MessagingPolicy: who shares a course, a department, a
 * scope or a linked child, and the state of a conversation for one person.
 *
 * @internal
 */
final class MessagingContextResolverTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private MessagingContextResolver $resolver;

    private int $institution;

    private int $department;

    private int $subA;

    private int $subB;

    private int $term;

    private int $programA;

    private int $programB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new MessagingContextResolver();

        $d                 = DeanFactory::department('M1');
        $this->institution = $d['institution'];
        $this->department  = $d['department'];
        $this->subA        = DeanFactory::subDepartment($this->department, 'MA');
        $this->subB        = DeanFactory::subDepartment($this->department, 'MB');
        $this->programA    = DeanFactory::program($this->subA, 'MA');
        $this->programB    = DeanFactory::program($this->subB, 'MB');
        $this->term        = DeanFactory::term($this->institution);
    }

    private function person(string $role, ?int $institution = null, bool $active = true): \CodeIgniter\Shield\Entities\User
    {
        return PeopleFactory::person($role, ucfirst($role), 'Person', null, $institution ?? $this->institution, $active);
    }

    private function offering(int $program, string $code, string $status = 'active'): int
    {
        return DeanFactory::offering($program, $this->term, $code, 'A', $status);
    }

    public function testASharedCourseNeedsBothActiveInOneActiveOffering(): void
    {
        $student    = $this->person('student');
        $instructor = $this->person('instructor');
        $other      = $this->person('instructor');
        $offering   = $this->offering($this->programA, 'C1');
        $otherOff   = $this->offering($this->programA, 'C2');
        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $instructor);
        CourseFactory::assignInstructor($otherOff, $other);

        $this->assertTrue($this->resolver->forStart($student, $instructor, null)->sharedCourse);
        $this->assertTrue($this->resolver->forStart($instructor, $student, null)->sharedCourse);
        $this->assertFalse($this->resolver->forStart($student, $other, null)->sharedCourse);
    }

    public function testASharedCourseIsFalseWhenTheOfferingIsClosedOrTheEnrolmentDropped(): void
    {
        $student    = $this->person('student');
        $instructor = $this->person('instructor');
        $closed     = $this->offering($this->programA, 'C3', 'completed');
        CourseFactory::enroll($closed, $student);
        CourseFactory::assignInstructor($closed, $instructor);

        $this->assertFalse($this->resolver->forStart($student, $instructor, null)->sharedCourse);

        $open = $this->offering($this->programA, 'C4');
        CourseFactory::enroll($open, $student, 'dropped');
        CourseFactory::assignInstructor($open, $instructor);

        $this->assertFalse($this->resolver->forStart($student, $instructor, null)->sharedCourse);
    }

    public function testSameDepartmentCoversInstructorsChairpersonsAndDeans(): void
    {
        $i1    = $this->person('instructor');
        $i2    = $this->person('instructor');
        $far   = $this->person('instructor');
        $chair = DeanFactory::chair($this->subA);
        $dean  = DeanFactory::dean($this->institution, $this->department);

        CourseFactory::assignInstructor($this->offering($this->programA, 'C5'), $i1);
        CourseFactory::assignInstructor($this->offering($this->programB, 'C6'), $i2);

        $other      = DeanFactory::department('M2');
        $otherSub   = DeanFactory::subDepartment($other['department'], 'MX');
        $otherProg  = DeanFactory::program($otherSub, 'MX');
        $otherTerm  = DeanFactory::term($other['institution']);
        $farOffer   = DeanFactory::offering($otherProg, $otherTerm, 'CX');
        CourseFactory::assignInstructor($farOffer, $far);

        $this->assertTrue($this->resolver->forStart($i1, $i2, null)->sameDepartment);
        $this->assertFalse($this->resolver->forStart($i1, $far, null)->sameDepartment);
        $this->assertTrue($this->resolver->forStart($i1, $chair, null)->sameDepartment);
        $this->assertTrue($this->resolver->forStart($i1, $dean, null)->sameDepartment);
        $this->assertTrue($this->resolver->forStart($chair, $dean, null)->sameDepartment);
    }

    public function testInScopeForAChairpersonIsTheirSubDepartmentAndForADeanTheirDepartment(): void
    {
        $chairA  = DeanFactory::chair($this->subA);
        $dean    = DeanFactory::dean($this->institution, $this->department);
        $studentA = $this->person('student');
        $studentB = $this->person('student');
        CourseFactory::enroll($this->offering($this->programA, 'C7'), $studentA);
        CourseFactory::enroll($this->offering($this->programB, 'C8'), $studentB);

        $this->assertTrue($this->resolver->forStart($chairA, $studentA, null)->inScope);
        $this->assertFalse($this->resolver->forStart($chairA, $studentB, null)->inScope);
        $this->assertTrue($this->resolver->forStart($dean, $studentA, null)->inScope);
        $this->assertTrue($this->resolver->forStart($dean, $studentB, null)->inScope);
        $this->assertTrue($this->resolver->forStart($dean, $chairA, null)->inScope);

        $other     = DeanFactory::department('M3');
        $otherSub  = DeanFactory::subDepartment($other['department'], 'MY');
        $outsider  = $this->person('student', $other['institution']);
        CourseFactory::enroll(DeanFactory::offering(DeanFactory::program($otherSub, 'MY'), DeanFactory::term($other['institution']), 'CY'), $outsider);

        $this->assertFalse($this->resolver->forStart($dean, $outsider, null)->inScope);
        $this->assertFalse($this->resolver->forStart($dean, $outsider, null)->sameInstitution);
    }

    public function testLinkedChildForAParentAndTheChildsInstructorAndChairperson(): void
    {
        $parent     = $this->person('parent');
        $stranger   = $this->person('parent');
        $child      = $this->person('student');
        $instructor = $this->person('instructor');
        $chair      = DeanFactory::chair($this->subA);
        $offering   = $this->offering($this->programA, 'C9');
        CourseFactory::enroll($offering, $child);
        CourseFactory::assignInstructor($offering, $instructor);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->resolver->forStart($parent, $instructor, null)->linkedChild);
        $this->assertTrue($this->resolver->forStart($parent, $instructor, (int) $child->id)->linkedChild);
        $this->assertTrue($this->resolver->forStart($instructor, $parent, null)->linkedChild);
        $this->assertTrue($this->resolver->forStart($parent, $chair, null)->linkedChild);
        $this->assertFalse($this->resolver->forStart($stranger, $instructor, null)->linkedChild);
        $this->assertFalse($this->resolver->forStart($parent, $instructor, (int) $stranger->id)->linkedChild);
    }

    public function testBlockedSelfAndActiveFacts(): void
    {
        $a = $this->person('student');
        $b = $this->person('student');
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $b->id, 'blocked_id' => $a->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->resolver->forStart($a, $b, null)->blockedByRecipient);
        $this->assertFalse($this->resolver->forStart($b, $a, null)->blockedByRecipient);
        $this->assertTrue($this->resolver->forStart($a, $a, null)->isSelf);
        $this->assertTrue($this->resolver->forStart($a, $b, null)->studentDmEnabled);

        $off = $this->person('student', null, false);
        $this->assertFalse($this->resolver->forStart($a, $off, null)->recipientActive);
        $this->assertTrue($this->resolver->forStart($a, $b, null)->recipientActive);
    }

    public function testTheSystemAdminAndAPersonWithoutAnInstitutionGetNoContext(): void
    {
        $admin   = CourseFactory::user('admin');
        $student = $this->person('student');
        $nowhere = CourseFactory::user('student');

        $this->assertNull($this->resolver->forStart($admin, $student, null));
        $this->assertNull($this->resolver->forStart($student, $admin, null));
        $this->assertNull($this->resolver->forStart($student, $nowhere, null));
    }

    /** @return array<string, mixed> */
    private function direct(int $a, int $b, int $institution = 0): array
    {
        $db = db_connect();
        $db->table('conversations')->insert(['institution_id' => $institution ?: $this->institution, 'type' => 'direct', 'direct_key' => bin2hex(random_bytes(32)), 'created_at' => date('Y-m-d H:i:s')]);
        $id = (int) $db->insertID();

        foreach ([$a, $b] as $user) {
            $db->table('conversation_participants')->insert(['conversation_id' => $id, 'user_id' => $user, 'joined_at' => date('Y-m-d H:i:s')]);
        }

        return $db->table('conversations')->where('id', $id)->get()->getRowArray();
    }

    public function testForPostReportsParticipationAndBlocks(): void
    {
        $student    = $this->person('student');
        $instructor = $this->person('instructor');
        $peer       = $this->person('student');
        $conv       = $this->direct((int) $student->id, (int) $peer->id);

        $this->assertTrue($this->resolver->forPost($student, $conv)->activeParticipant);
        $this->assertFalse($this->resolver->forPost($instructor, $conv)->activeParticipant);
        $this->assertTrue($this->resolver->isActiveParticipant((int) $student->id, (int) $conv['id']));

        $now = date('Y-m-d H:i:s');
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $peer->id, 'blocked_id' => $student->id, 'created_at' => $now]);
        $ctx = $this->resolver->forPost($student, $conv);
        $this->assertTrue($ctx->blocked);
        $this->assertFalse($ctx->blockerIsExempt);
        $this->assertFalse($this->resolver->forPost($peer, $conv)->blocked);

        db_connect()->table('conversation_participants')->where(['conversation_id' => $conv['id'], 'user_id' => $student->id])->update(['left_at' => $now]);
        $this->assertFalse($this->resolver->forPost($student, $conv)->activeParticipant);
        $this->assertFalse($this->resolver->isActiveParticipant((int) $student->id, (int) $conv['id']));
    }

    public function testForPostExemptsAnInstructorsBlock(): void
    {
        $student    = $this->person('student');
        $instructor = $this->person('instructor');
        $conv       = $this->direct((int) $student->id, (int) $instructor->id);
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $instructor->id, 'blocked_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);

        $ctx = $this->resolver->forPost($student, $conv);

        $this->assertTrue($ctx->blocked);
        $this->assertTrue($ctx->blockerIsExempt);
    }

    public function testForPostSeesAClosedOfferingALockAndAnInstructor(): void
    {
        $student    = $this->person('student');
        $instructor = $this->person('instructor');
        $offering   = $this->offering($this->programA, 'C10');
        CourseFactory::assignInstructor($offering, $instructor);
        $db = db_connect();
        $db->table('conversations')->insert(['institution_id' => $this->institution, 'type' => 'course', 'offering_id' => $offering, 'is_locked' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $id = (int) $db->insertID();
        foreach ([$student, $instructor] as $user) {
            $db->table('conversation_participants')->insert(['conversation_id' => $id, 'user_id' => $user->id, 'joined_at' => date('Y-m-d H:i:s')]);
        }
        $conv = $db->table('conversations')->where('id', $id)->get()->getRowArray();

        $this->assertTrue($this->resolver->forPost($student, $conv)->locked);
        $this->assertFalse($this->resolver->forPost($student, $conv)->isCourseInstructor);
        $this->assertTrue($this->resolver->forPost($instructor, $conv)->isCourseInstructor);
        $this->assertFalse($this->resolver->forPost($student, $conv)->offeringClosed);

        $db->table('course_offerings')->where('id', $offering)->update(['status' => 'completed']);
        $this->assertTrue($this->resolver->forPost($student, $conv)->offeringClosed);
    }

    public function testForPostSeesAReadOnlyInstitutionAndAWrongInstitution(): void
    {
        $a    = $this->person('student');
        $b    = $this->person('student');
        $conv = $this->direct((int) $a->id, (int) $b->id);

        $this->assertFalse($this->resolver->forPost($a, $conv)->institutionReadOnly);

        $outsider = $this->person('student', PeopleFactory::institution('OUT'));
        db_connect()->table('conversation_participants')->insert(['conversation_id' => $conv['id'], 'user_id' => $outsider->id, 'joined_at' => date('Y-m-d H:i:s')]);

        $this->assertFalse($this->resolver->forPost($outsider, $conv)->activeParticipant);
    }
}
