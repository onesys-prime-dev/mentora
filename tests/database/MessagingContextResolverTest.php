<?php

declare(strict_types=1);

use App\Services\Messaging\MessagingContextResolver;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The database facts behind MessagingPolicy: classmates, who teaches or leads a
 * minor, a linked child, and the state of a conversation for one person.
 *
 * @internal
 */
final class MessagingContextResolverTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private MessagingContextResolver $resolver;

    private int $institution;

    private int $college;

    private int $departmentA;

    private int $departmentB;

    private int $term;

    private int $programA;

    private int $programB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new MessagingContextResolver();

        $d                 = DeanFactory::college('M1');
        $this->institution = $d['institution'];
        $this->college  = $d['college'];
        $this->departmentA        = DeanFactory::department($this->college, 'MA');
        $this->departmentB        = DeanFactory::department($this->college, 'MB');
        $this->programA    = DeanFactory::program($this->departmentA, 'MA');
        $this->programB    = DeanFactory::program($this->departmentB, 'MB');
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

    public function testASharedCourseMeansClassmatesInOneActiveOffering(): void
    {
        $a          = $this->person('student');
        $b          = $this->person('student');
        $elsewhere  = $this->person('student');
        $instructor = $this->person('instructor');
        $offering   = $this->offering($this->programA, 'C1');
        CourseFactory::enroll($offering, $a);
        CourseFactory::enroll($offering, $b);
        CourseFactory::enroll($this->offering($this->programA, 'C2'), $elsewhere);
        CourseFactory::assignInstructor($offering, $instructor);

        $this->assertTrue($this->resolver->forStart($a, $b, null)->sharedCourse);
        $this->assertTrue($this->resolver->forStart($b, $a, null)->sharedCourse);
        $this->assertFalse($this->resolver->forStart($a, $elsewhere, null)->sharedCourse);
        // Teaching the course is not sharing it as a classmate.
        $this->assertFalse($this->resolver->forStart($instructor, $a, null)->sharedCourse);
    }

    public function testASharedCourseIsFalseWhenTheOfferingIsClosedOrTheEnrolmentDropped(): void
    {
        $a      = $this->person('student');
        $b      = $this->person('student');
        $closed = $this->offering($this->programA, 'C3', 'completed');
        CourseFactory::enroll($closed, $a);
        CourseFactory::enroll($closed, $b);

        $this->assertFalse($this->resolver->forStart($a, $b, null)->sharedCourse);

        $open = $this->offering($this->programA, 'C4');
        CourseFactory::enroll($open, $a, 'dropped');
        CourseFactory::enroll($open, $b);

        $this->assertFalse($this->resolver->forStart($a, $b, null)->sharedCourse);
    }

    public function testTeachesAndLeadsAreReadForAMinorRecipientOnly(): void
    {
        $minor      = $this->person('student');
        $adult      = $this->person('student');
        $instructor = $this->person('instructor');
        $stranger   = $this->person('instructor');
        $chairA     = DeanFactory::chair($this->departmentA);
        $chairB     = DeanFactory::chair($this->departmentB);
        $dean       = DeanFactory::dean($this->institution, $this->college);
        $offering   = $this->offering($this->programA, 'C5');
        CourseFactory::enroll($offering, $minor);
        CourseFactory::enroll($offering, $adult);
        CourseFactory::assignInstructor($offering, $instructor);
        db_connect()->table('institution_users')->where('institution_id', $this->institution)->where('user_id', $minor->id)->update(['is_minor' => 1]);
        $this->homeProgram($minor, $this->programA);

        $this->assertTrue($this->resolver->forStart($instructor, $minor, null)->recipientMinor);
        $this->assertTrue($this->resolver->forStart($instructor, $minor, null)->senderTeachesRecipient);
        $this->assertFalse($this->resolver->forStart($stranger, $minor, null)->senderTeachesRecipient);
        $this->assertTrue($this->resolver->forStart($chairA, $minor, null)->senderLeadsRecipient);
        $this->assertFalse($this->resolver->forStart($chairB, $minor, null)->senderLeadsRecipient);
        $this->assertTrue($this->resolver->forStart($dean, $minor, null)->senderLeadsRecipient);
        $this->assertFalse($this->resolver->forStart($instructor, $minor, null)->senderLeadsRecipient);

        // An adult recipient: the minor rule does not apply, so the facts are not looked up.
        $this->assertFalse($this->resolver->forStart($instructor, $adult, null)->recipientMinor);
        $this->assertFalse($this->resolver->forStart($instructor, $adult, null)->senderTeachesRecipient);
        $this->assertFalse($this->resolver->forStart($chairA, $adult, null)->senderLeadsRecipient);

        $other    = DeanFactory::college('M3');
        $outsider = $this->person('student', $other['institution']);
        $this->assertFalse($this->resolver->forStart($dean, $outsider, null)->sameInstitution);
    }

    /** M5 ruling: "their department" is the home program's department, not the departments of the courses they take. */
    public function testAChairOrDeanLeadsAMinorThroughTheirHomeProgramOnly(): void
    {
        $other     = DeanFactory::college('M4', $this->institution);
        $otherDep  = DeanFactory::department($other['college'], 'MC');
        $minor     = $this->person('student');
        $chairA    = DeanFactory::chair($this->departmentA);
        $chairB    = DeanFactory::chair($this->departmentB);
        $chairC    = DeanFactory::chair($otherDep);
        $dean      = DeanFactory::dean($this->institution, $this->college);
        $otherDean = DeanFactory::dean($this->institution, $other['college']);
        db_connect()->table('institution_users')->where('institution_id', $this->institution)->where('user_id', $minor->id)->update(['is_minor' => 1]);

        // Taking a course of department C (another college) gives its chair and dean no reach.
        CourseFactory::enroll(DeanFactory::offering(DeanFactory::program($otherDep, 'MC'), $this->term, 'C9'), $minor);
        $this->homeProgram($minor, $this->programB);

        $this->assertTrue($this->resolver->forStart($chairB, $minor, null)->senderLeadsRecipient);
        $this->assertTrue($this->resolver->forStart($dean, $minor, null)->senderLeadsRecipient);
        $this->assertFalse($this->resolver->forStart($chairA, $minor, null)->senderLeadsRecipient);
        $this->assertFalse($this->resolver->forStart($chairC, $minor, null)->senderLeadsRecipient);
        $this->assertFalse($this->resolver->forStart($otherDean, $minor, null)->senderLeadsRecipient);

        // No home program: no chair or dean leads them.
        $this->homeProgram($minor, null);
        $this->assertFalse($this->resolver->forStart($chairB, $minor, null)->senderLeadsRecipient);
        $this->assertFalse($this->resolver->forStart($dean, $minor, null)->senderLeadsRecipient);
    }

    private function homeProgram(\CodeIgniter\Shield\Entities\User $student, ?int $program): void
    {
        db_connect()->table('user_profiles')->where('user_id', $student->id)->update(['program_id' => $program]);
    }

    public function testLinkedChildIsTheParentSideLinkedToTheNamedChild(): void
    {
        $parent   = $this->person('parent');
        $stranger = $this->person('parent');
        $child    = $this->person('student');
        $dean     = DeanFactory::dean($this->institution, $this->college);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);

        // D2: any staff member; the child need not be theirs to teach.
        $this->assertTrue($this->resolver->forStart($parent, $dean, (int) $child->id)->linkedChildAsSenderParent);
        $this->assertFalse($this->resolver->forStart($parent, $dean, (int) $child->id)->linkedChildAsRecipientParent);
        $this->assertTrue($this->resolver->forStart($dean, $parent, (int) $child->id)->linkedChildAsRecipientParent);
        $this->assertFalse($this->resolver->forStart($dean, $parent, (int) $child->id)->linkedChildAsSenderParent);
        $this->assertFalse($this->resolver->forStart($parent, $dean, null)->linkedChildAsSenderParent, 'no child named');
        $this->assertFalse($this->resolver->forStart($stranger, $dean, (int) $child->id)->linkedChildAsSenderParent);
        $this->assertFalse($this->resolver->forStart($parent, $dean, (int) $stranger->id)->linkedChildAsSenderParent);
    }

    public function testChildSubjectsAreTheParentsChildrenWithAnyStaff(): void
    {
        $parent  = $this->person('parent');
        $child   = $this->person('student');
        $dean    = DeanFactory::dean($this->institution, $this->college);
        $student = $this->person('student');
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
        $p = fn ($u) => $this->resolver->party($u);

        $this->assertSame([(int) $child->id], $this->resolver->childSubjects($parent, $p($parent), $dean, $p($dean)));
        $this->assertSame([(int) $child->id], $this->resolver->childSubjects($dean, $p($dean), $parent, $p($parent)));
        $this->assertNull($this->resolver->childSubjects($parent, $p($parent), $student, $p($student)));
    }

    public function testBlockedSelfAndActiveFacts(): void
    {
        $a = $this->person('student');
        $b = $this->person('student');
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $b->id, 'blocked_id' => $a->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->resolver->forStart($a, $b, null)->blockedByRecipient);
        $this->assertFalse($this->resolver->forStart($b, $a, null)->blockedByRecipient);
        $this->assertTrue($this->resolver->forStart($a, $a, null)->isSelf);
        $this->assertSame('institution', $this->resolver->forStart($a, $b, null)->studentScope);
        $this->assertTrue($this->resolver->forStart($a, $b, null)->senderInstitutionWritable);
        $this->assertFalse($this->resolver->forStart($a, $b, null)->impersonating);

        (new \App\Services\Messaging\StudentMessaging())->setScope($this->institution, 'course');
        $this->assertSame('course', $this->resolver->forStart($a, $b, null)->studentScope);

        $off = $this->person('student', null, false);
        $this->assertFalse($this->resolver->forStart($a, $off, null)->recipientActive);
        $this->assertTrue($this->resolver->forStart($a, $b, null)->recipientActive);
    }

    public function testADeanWithoutAnAssignmentWhoAlsoTeachesActsAsAnInstructor(): void
    {
        $dean = $this->person('dean');
        db_connect()->table('auth_groups_users')->insert(['user_id' => $dean->id, 'group' => 'instructor', 'created_at' => date('Y-m-d H:i:s')]);
        $dean = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($dean->id);

        $this->assertSame(['instructor'], $this->resolver->party($dean)->roles);
    }

    private function withGroup(\CodeIgniter\Shield\Entities\User $user, string $group): \CodeIgniter\Shield\Entities\User
    {
        db_connect()->table('auth_groups_users')->insert(['user_id' => $user->id, 'group' => $group, 'created_at' => date('Y-m-d H:i:s')]);

        return model(\CodeIgniter\Shield\Models\UserModel::class)->findById($user->id);
    }

    public function testPartyListsEveryUsableRole(): void
    {
        $user  = $this->withGroup($this->person('instructor'), 'parent');
        $party = $this->resolver->party($user);

        $this->assertEqualsCanonicalizing(['instructor', 'parent'], $party->roles);
        $this->assertTrue($party->active);
        $this->assertSame($this->institution, $party->institutionId);
        $this->assertFalse($party->minor);

        // A dean with no assignment who holds another role keeps only the other role.
        $dean = $this->withGroup($this->person('dean'), 'instructor');
        $this->assertSame(['instructor'], $this->resolver->party($dean)->roles);
        $this->assertSame(['instructor'], $this->resolver->roles($dean));
    }

    public function testRolesMatchesThePartyWithoutTheAccountLookups(): void
    {
        $user = $this->withGroup($this->person('instructor'), 'parent');

        $this->assertEqualsCanonicalizing($this->resolver->party($user)->roles, $this->resolver->roles($user));
    }

    public function testAnAdminWhoAlsoHoldsAnInstitutionRoleGetsAContext(): void
    {
        // Ruling R10: the admin role is ignored beside institution roles.
        $both    = $this->withGroup($this->person('instructor'), 'admin');
        $student = $this->person('student');

        $this->assertNotNull($this->resolver->forStart($both, $student, null));
    }

    public function testPartyIgnoresSavedActiveRole(): void
    {
        $user = $this->withGroup($this->person('instructor'), 'parent');
        db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['active_role' => 'parent']);
        $student  = $this->person('student');
        $offering = $this->offering($this->programA, 'PS1');
        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $user);

        $this->assertEqualsCanonicalizing(['instructor', 'parent'], $this->resolver->party($user)->roles);

        $context = $this->resolver->forStart($user, $student, null);
        $this->assertTrue((new \App\Services\Messaging\MessagingPolicy())->canStart($this->resolver->party($user), $this->resolver->party($student), null, $context));
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
        $this->assertFalse($this->resolver->forPost($peer, $conv)->blocked);

        db_connect()->table('conversation_participants')->where(['conversation_id' => $conv['id'], 'user_id' => $student->id])->update(['left_at' => $now]);
        $this->assertFalse($this->resolver->forPost($student, $conv)->activeParticipant);
        $this->assertFalse($this->resolver->isActiveParticipant((int) $student->id, (int) $conv['id']));
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

    /** D9 in an existing thread: with no known starter both directions are checked; a thread the minor started is theirs. */
    public function testMinorLostChecksBothDirectionsWhenTheStarterIsUnknown(): void
    {
        $minor    = $this->person('student');
        $stranger = $this->person('instructor');
        db_connect()->table('institution_users')->where('user_id', $minor->id)->update(['is_minor' => 1]);
        $conv = $this->direct((int) $minor->id, (int) $stranger->id);
        $this->assertNull($conv['created_by']);

        $this->assertTrue($this->resolver->forPost($stranger, $conv)->minorLost);
        $this->assertTrue((new MessagingContextResolver())->forPost($minor, $conv)->minorLost, 'the minor side too');

        $conv['created_by'] = $minor->id;
        $this->assertFalse((new MessagingContextResolver())->forPost($stranger, $conv)->minorLost);
        $this->assertFalse((new MessagingContextResolver())->forPost($minor, $conv)->minorLost);

        $conv['created_by'] = $stranger->id;
        $this->assertTrue((new MessagingContextResolver())->forPost($minor, $conv)->minorLost);
    }

    /** S2-f: a minor who also holds a staff role is still protected when the other side started the thread. */
    public function testAMinorWhoAlsoTeachesIsStillProtectedInForPost(): void
    {
        $minor = $this->person('student');
        $minor->addGroup('instructor');
        $other = $this->person('instructor');
        db_connect()->table('institution_users')->where('user_id', $minor->id)->update(['is_minor' => 1]);
        $conv               = $this->direct((int) $minor->id, (int) $other->id);
        $conv['created_by'] = $other->id;

        $this->assertTrue((new MessagingContextResolver())->forPost($other, $conv)->minorLost);

        $offering = $this->offering($this->programA, 'C77');
        CourseFactory::enroll($offering, $minor);
        CourseFactory::assignInstructor($offering, $other);
        $this->assertFalse((new MessagingContextResolver())->forPost($other, $conv)->minorLost, 'their own instructor reaches them');
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
