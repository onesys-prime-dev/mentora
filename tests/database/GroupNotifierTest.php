<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssignmentSubmissionService;
use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupExtensionService;
use App\Services\Groups\GroupGradingService;
use App\Services\Groups\GroupLeaderService;
use App\Services\Groups\GroupNotifier;
use App\Services\Groups\GroupProposalService;
use App\Services\Groups\GroupSetService;
use App\Services\Groups\GroupSignupService;
use App\Services\Notifications\MailOutbox;
use App\Services\Notifications\NotificationTriggers;
use App\Services\Parents\ParentNotifier;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * The ten group events (D19): each tells the right people once, in the app and by email (outbox kind "group"), never
 * the person who caused it, never another member's adjustment (D8), and never breaks the action it reports.
 *
 * @internal
 */
final class GroupNotifierTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offering;

    private int $module;

    private User $instructor;

    private User $coTeacher;

    private int $teacher;

    private int $assignment;

    private int $config;

    /** @var list<User> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        FakeEmail::install();
        GroupNotifier::$instance = null;

        $this->offering   = CourseFactory::offering();
        $this->module     = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        $this->coTeacher  = CourseFactory::user('instructor');
        $this->teacher    = (int) $this->instructor->id;
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::assignInstructor($this->offering, $this->coTeacher);

        for ($i = 0; $i < 4; $i++) {
            $this->u[] = $student = CourseFactory::user('student');
            CourseFactory::enroll($this->offering, $student);
        }

        $this->assignment = CourseFactory::assessment($this->offering, $this->instructor, $this->module, null, 'Assignment');
        AssessmentFactory::open($this->assignment, ['attempt_limit' => 3, 'total_points' => 20, 'title' => 'Team essay']);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->assignment, 'allow_file_upload' => 0, 'allow_text_entry' => 1, 'allow_link_entry' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $this->config = (new ActivityGroupService())->attachAssessment($this->assignment, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 30, 'grading_mode' => 'collective_adjust'], $this->teacher);
    }

    protected function tearDown(): void
    {
        GroupNotifier::$instance = null;
        Services::resetSingle('email');
        parent::tearDown();
    }

    /** @param list<int> $members indexes into $this->u */
    private function group(array $members, bool $frozen = false): int
    {
        $db = db_connect();
        $db->table('student_groups')->insert(['set_id' => null, 'config_id' => $this->config, 'name' => 'Team ' . count($members), 'status' => 'active', 'frozen_at' => $frozen ? date('Y-m-d H:i:s') : null]);
        $group = (int) $db->insertID();

        foreach ($members as $m) {
            $db->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $this->u[$m]->id, 'joined_at' => date('Y-m-d H:i:s')]);
        }

        return $group;
    }

    private function configure(array $changes): void
    {
        db_connect()->table('activity_group_configs')->where('id', $this->config)->update($changes);
    }

    /** @return list<array<string, mixed>> */
    private function notes(string $type, User $user): array
    {
        return db_connect()->table('notifications')->where('type', $type)->where('user_id', $user->id)->orderBy('id')->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function outbox(User $user): array
    {
        return db_connect()->table('mail_outbox')->where('kind', 'group')->where('user_id', $user->id)->orderBy('id')->get()->getResultArray();
    }

    /**
     * Each of $told has exactly one $type notice and one group email row; nobody in $notTold has any.
     *
     * @param list<User> $told
     * @param list<User> $notTold
     */
    private function assertTold(string $type, array $told, array $notTold): void
    {
        foreach ($told as $user) {
            $this->assertCount(1, $this->notes($type, $user), "{$type} once for user {$user->id}");
            $this->assertCount(1, $this->outbox($user), "one group email row for user {$user->id}");
        }

        foreach ($notTold as $user) {
            $this->assertSame([], $this->notes($type, $user), "no {$type} for user {$user->id}");
        }
    }

    /** Runs a hook again: nothing new is stored. */
    private function assertRepeatIsDeduped(callable $again): void
    {
        $before = [db_connect()->table('notifications')->countAllResults(), db_connect()->table('mail_outbox')->countAllResults()];
        $again(new GroupNotifier());
        $this->assertSame($before, [db_connect()->table('notifications')->countAllResults(), db_connect()->table('mail_outbox')->countAllResults()], 'a repeat tells nobody again');
    }

    // 1. Placed in a group ------------------------------------------------------------------------------------------

    public function testManualAddTellsThePlacedStudent(): void
    {
        $group = $this->group([1]);

        (new ActivityGroupMembershipService())->addMember($group, (int) $this->u[0]->id, $this->teacher);

        $this->assertTold('groups.placed', [$this->u[0]], [$this->u[1], $this->instructor]);
        $note = $this->notes('groups.placed', $this->u[0])[0];
        $this->assertSame('You are in a group', $note['title']);
        $this->assertSame('You are in "Team 1" for "Team essay".', $note['body']);
        $this->assertStringContainsString('student/courses/' . $this->offering . '/', (string) $note['link']);
        $this->assertSame(0, (int) $note['urgent']);
        $this->assertRepeatIsDeduped(fn (GroupNotifier $n) => $n->placed($group, [(int) $this->u[0]->id], $this->teacher));
    }

    public function testAttachWithCopyTellsEveryCopiedStudent(): void
    {
        $set   = (new GroupSetService())->create($this->offering, ['name' => 'Teams'], $this->teacher);
        $group = (new GroupSetService())->addGroup($set, 'Blue', $this->teacher);
        db_connect()->table('student_group_members')->insert(['group_id' => $group, 'set_id' => $set, 'config_id' => null, 'student_user_id' => $this->u[2]->id, 'joined_at' => date('Y-m-d H:i:s')]);
        $other = CourseFactory::assessment($this->offering, $this->instructor, $this->module, null, 'Assignment');

        (new ActivityGroupService())->attachAssessment($other, $set, [], $this->teacher);

        $this->assertTold('groups.placed', [$this->u[2]], [$this->u[0], $this->instructor]);
    }

    public function testBulkAutoGenerateNotifiesAllPlacedStudentsOnce(): void
    {
        for ($i = 0; $i < 36; $i++) {
            $this->u[] = $student = CourseFactory::user('student');
            CourseFactory::enroll($this->offering, $student);
        }

        $set    = (new GroupSetService())->create($this->offering, ['name' => 'Auto'], $this->teacher);
        $ids    = array_map(static fn (User $u): int => (int) $u->id, $this->u);
        $groups = array_chunk($ids, 10);

        (new GroupSetService())->saveGenerated($set, ['groups' => $groups], false, $this->teacher);

        $this->assertSame(40, db_connect()->table('notifications')->where('type', 'groups.placed')->countAllResults());
        $this->assertSame(40, db_connect()->table('mail_outbox')->where('kind', 'group')->countAllResults());
        $this->assertTold('groups.placed', $this->u, [$this->instructor]);
        $this->assertRepeatIsDeduped(fn (GroupNotifier $n) => $n->groupsSaved('set_id', $set, $this->teacher));
    }

    // 2. Member joins, leaves, leader changes ------------------------------------------------------------------------

    public function testJoinTellsTheOtherMembersNotTheJoiner(): void
    {
        $this->configure(['formation_mode' => 'self_signup']);
        $group = $this->group([0, 1]);

        (new GroupSignupService())->join($this->config, $group, (int) $this->u[2]->id);

        $this->assertTold('groups.member_changed', [$this->u[0], $this->u[1]], [$this->u[2], $this->u[3]]);
        $this->assertRepeatIsDeduped(fn (GroupNotifier $n) => $n->memberChanged($group, (int) $this->u[2]->id, 'joined', (int) $this->u[2]->id));
    }

    public function testLeaveTellsTheRemainingMembers(): void
    {
        $group = $this->group([0, 1, 2]);

        (new GroupSignupService())->leave($group, (int) $this->u[2]->id);

        $this->assertTold('groups.member_changed', [$this->u[0], $this->u[1]], [$this->u[2]]);
    }

    public function testInstructorSettingALeaderTellsTheMembers(): void
    {
        $group = $this->group([0, 1]);

        (new GroupLeaderService())->setLeader($group, (int) $this->u[1]->id, $this->teacher);

        $this->assertTold('groups.member_changed', [$this->u[0], $this->u[1]], [$this->instructor]);
    }

    // 3. Leader vote ---------------------------------------------------------------------------------------------------

    public function testFirstVoteTellsTheOtherMembersOncePerRound(): void
    {
        $group = $this->group([0, 1, 2]);

        (new GroupLeaderService())->vote($group, (int) $this->u[0]->id, (int) $this->u[1]->id);
        (new GroupLeaderService())->vote($group, (int) $this->u[1]->id, (int) $this->u[2]->id);

        $this->assertTold('groups.leader', [$this->u[1], $this->u[2]], [$this->u[0], $this->u[3]]);
    }

    // 4. Submitted ----------------------------------------------------------------------------------------------------

    public function testGroupSubmissionTellsTheOtherMembers(): void
    {
        $group = $this->group([0, 1]);

        $this->assertTrue((new AssignmentSubmissionService())->submit($this->u[0], $this->assignment, ['submission_text' => 'Ours'])['success']);

        $this->assertTold('groups.submitted', [$this->u[1]], [$this->u[0], $this->u[2]]);
        $this->assertRepeatIsDeduped(fn (GroupNotifier $n) => $n->submitted($group, $this->assignment, (int) $this->u[0]->id));
    }

    // 5. Graded --------------------------------------------------------------------------------------------------------

    private function gradeWithAdjustment(int $group): void
    {
        AssessmentFactory::attempt($this->assignment, $this->u[0], 'Submitted', 0.0, ['group_id' => $group, 'submitted_by_user_id' => $this->u[0]->id]);
        (new GroupGradingService())->gradeGroup($this->assignment, $group, ['group_score' => 15, 'members' => [
            (int) $this->u[1]->id => ['adjustment' => -3, 'reason' => 'Skipped every meeting', 'note' => 'Private word'],
        ]], $this->teacher);
    }

    public function testGradingTellsEveryGradedMember(): void
    {
        $group = $this->group([0, 1, 2]);

        $this->gradeWithAdjustment($group);

        $this->assertTold('groups.graded', [$this->u[0], $this->u[1], $this->u[2]], [$this->u[3], $this->instructor]);
        $this->assertRepeatIsDeduped(fn (GroupNotifier $n) => $n->graded($group, $this->assignment, [(int) $this->u[0]->id, (int) $this->u[1]->id, (int) $this->u[2]->id], $this->teacher));
    }

    public function testGradedNoticeHasNoAdjustmentOrOtherMemberData(): void
    {
        $group = $this->group([0, 1, 2]);

        $this->gradeWithAdjustment($group);

        foreach ([0, 1, 2] as $i) {
            $note   = $this->notes('groups.graded', $this->u[$i])[0];
            $detail = $this->outbox($this->u[$i])[0]['detail'];

            $this->assertSame('Your group\'s work was graded', $note['title']);
            $this->assertSame('Your group\'s work "Team essay" was graded. See your result.', $note['body']);
            $this->assertStringContainsString('Your group\'s work "Team essay" was graded. See your result.', $detail);

            foreach ([$note['title'], $note['body'], (string) $note['link'], $detail] as $text) {
                foreach (['Skipped', 'Private', 'adjust', (string) $this->u[1]->username, (string) $this->u[0]->username, (string) $this->u[2]->username, 'student_user_id', 'member', 'group_id'] as $leak) {
                    $this->assertStringNotContainsString($leak, $text, "graded notice leaks \"{$leak}\"");
                }
            }

            // No amount at all in the words (the link holds only course ids and a slug).
            $this->assertDoesNotMatchRegularExpression('/\d/', $note['title'] . $note['body']);
        }

        $this->assertSame($this->notes('groups.graded', $this->u[0])[0]['link'], $this->notes('groups.graded', $this->u[1])[0]['link'], 'everyone is sent to their own result through the same page');
    }

    // 6 + 8. Signup window (scheduled) ---------------------------------------------------------------------------------

    public function testWindowClosingTriggerDedupedAndOnlyForUngrouped(): void
    {
        $now = new DateTimeImmutable();
        $this->configure(['formation_mode' => 'self_signup', 'signup_opens_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'signup_closes_at' => $now->modify('+12 hours')->format('Y-m-d H:i:s')]);
        $this->group([0]);

        $created = (new NotificationTriggers())->groupWindows($now);
        $this->assertGreaterThanOrEqual(3, $created);
        $this->assertTold('groups.window_closing', [$this->u[1], $this->u[2], $this->u[3]], [$this->u[0], $this->instructor]);

        $this->assertSame(0, (new NotificationTriggers())->groupWindows($now->modify('+1 hour')), 'a second run tells nobody');

        // Two days away is not "closing soon"; a closed window is not either.
        $this->configure(['signup_closes_at' => $now->modify('+2 days')->format('Y-m-d H:i:s')]);
        db_connect()->table('notifications')->truncate();
        $this->assertSame(0, (new NotificationTriggers())->groupWindows($now));
    }

    public function testWindowClosedSummaryToInstructors(): void
    {
        $now = new DateTimeImmutable();
        $this->configure(['formation_mode' => 'self_signup', 'min_size' => 2, 'signup_closes_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s')]);
        $this->group([0]);

        (new NotificationTriggers())->groupWindows($now);

        $this->assertTold('groups.window_closed', [$this->instructor, $this->coTeacher], $this->u);
        $note = $this->notes('groups.window_closed', $this->instructor)[0];
        $this->assertSame('Sign-up closed for "Team essay": 1 group below the minimum and 3 students without a group.', $note['body']);
        $this->assertStringContainsString('instructor/courses/' . $this->offering . '/', (string) $note['link']);

        $this->assertSame(0, (new NotificationTriggers())->groupWindows($now->modify('+10 minutes')), 'told once per activity');
    }

    // 7. Proposal awaiting approval -----------------------------------------------------------------------------------

    public function testProposalReadyForApprovalTellsTheInstructors(): void
    {
        $this->configure(['formation_mode' => 'proposed', 'min_size' => 2]);
        $service = new GroupProposalService();
        $group   = $service->propose($this->config, (int) $this->u[0]->id, [(int) $this->u[1]->id], 'Owls');

        $this->assertSame([], $this->notes('groups.proposal', $this->instructor), 'below the minimum it is not ready');

        $service->respondToInvitation($group, (int) $this->u[1]->id, true);

        $this->assertTold('groups.proposal', [$this->instructor, $this->coTeacher], [$this->u[0], $this->u[1]]);
        $this->assertRepeatIsDeduped(fn (GroupNotifier $n) => $n->proposalReady($group, (int) $this->u[1]->id));
    }

    // 9. Deadline extended --------------------------------------------------------------------------------------------

    public function testExtensionTellsEveryMember(): void
    {
        $group = $this->group([0, 1]);

        (new GroupExtensionService())->extend($group, $this->assignment, date('Y-m-d H:i:s', time() + 10 * 86400), 'Illness', $this->teacher);

        $this->assertTold('groups.extended', [$this->u[0], $this->u[1]], [$this->u[2], $this->instructor]);
        $this->assertStringNotContainsString('Illness', $this->notes('groups.extended', $this->u[0])[0]['body']);
    }

    // 10. Unlock and changes after the first submission ----------------------------------------------------------------

    public function testUnlockTellsTheMembersAndTheOtherInstructors(): void
    {
        $group = $this->group([0, 1], true);

        (new ActivityGroupMembershipService())->unlock($group, 'A member must move', $this->teacher);

        $this->assertTold('groups.unlocked', [$this->u[0], $this->u[1], $this->coTeacher], [$this->instructor, $this->u[2]]);
    }

    public function testLateEnrolleeOnAFrozenGroupTellsInstructorsAndTheGroup(): void
    {
        $group = $this->group([0], true);

        (new ActivityGroupMembershipService())->addLateEnrollee($group, (int) $this->u[1]->id, 'Enrolled late', $this->teacher);

        $this->assertCount(1, $this->notes('groups.unlocked', $this->u[0]));
        $this->assertCount(1, $this->notes('groups.unlocked', $this->u[1]));
        $this->assertCount(1, $this->notes('groups.unlocked', $this->coTeacher));
        $this->assertSame([], $this->notes('groups.unlocked', $this->instructor));
    }

    public function testDropFromAFrozenGroupTellsInstructorsAndTheRemainingMembers(): void
    {
        $group = $this->group([0, 1], true);

        (new ActivityGroupMembershipService())->onStudentDropped($this->offering, (int) $this->u[1]->id);

        $this->assertCount(1, $this->notes('groups.unlocked', $this->u[0]));
        $this->assertCount(1, $this->notes('groups.unlocked', $this->instructor));
        $this->assertCount(1, $this->notes('groups.unlocked', $this->coTeacher));
        $this->assertSame([], $this->notes('groups.unlocked', $this->u[1]), 'the dropped student is not told about the group');
    }

    // Never breaks the action -----------------------------------------------------------------------------------------

    public function testNotificationFailureNeverBreaksTheAction(): void
    {
        GroupNotifier::$instance = new class () extends GroupNotifier {
            public function placed(int $groupId, array $studentIds, int $actorId): void
            {
                throw new RuntimeException('notifications are down');
            }

            public function memberChanged(int $groupId, int $studentId, string $what, int $actorId): void
            {
                throw new RuntimeException('notifications are down');
            }
        };

        $group = $this->group([1]);

        (new ActivityGroupMembershipService())->addMember($group, (int) $this->u[0]->id, $this->teacher);

        $this->assertSame(1, db_connect()->table('student_group_members')->where('group_id', $group)->where('student_user_id', $this->u[0]->id)->countAllResults());
        $this->assertSame(0, db_connect()->table('notifications')->countAllResults());
    }

    // Email -----------------------------------------------------------------------------------------------------------

    public function testMigrationAddsGroupKind(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-12-01-000011_AddGroupKindToMailOutbox.php';
        $migration = new App\Database\Migrations\AddGroupKindToMailOutbox();
        $type      = static fn (): string => (string) db_connect()->query("SHOW COLUMNS FROM mail_outbox LIKE 'kind'")->getRowArray()['Type'];

        $this->assertStringContainsString("'group'", $type());
        $this->assertContains('group', MailOutbox::KINDS);

        try {
            $migration->down();
            $this->assertStringNotContainsString("'group'", $type(), 'down() takes the kind out again');
        } finally {
            $migration->up();
        }

        $this->assertStringContainsString("'group'", $type());
    }

    public function testGroupRowsAreEmailedAsOnePlainMessage(): void
    {
        $mailer      = FakeEmail::install();
        $institution = (int) db_connect()->table('colleges d')->select('d.institution_id')->join('departments sd', 'sd.college_id = d.id')->join('programs p', 'p.department_id = sd.id')
            ->join('courses c', 'c.program_id = p.id')->join('course_offerings o', 'o.course_id = c.id')->where('o.id', $this->offering)->get()->getRowArray()['institution_id'];
        $ann         = PeopleFactory::person('student', 'Ann', 'Lee', 'ann@example.test', $institution);
        CourseFactory::enroll($this->offering, $ann);
        $group = $this->group([0]);
        db_connect()->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $ann->id, 'joined_at' => date('Y-m-d H:i:s')]);

        (new GroupNotifier())->graded($group, $this->assignment, [(int) $ann->id], $this->teacher);
        (new MailOutbox())->sendDue((new DateTimeImmutable())->modify('+1 hour'));

        $this->assertCount(1, $mailer->sent);
        $this->assertStringContainsString('Your group\'s work "Team essay" was graded. See your result.', $mailer->sent[0]['message']);
        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', $ann->id)->countAllResults(), 'the email does not add a second in-app notice');
    }

    // Parents ---------------------------------------------------------------------------------------------------------

    public function testParentNotifierDoesNotReportTeammateAsMissing(): void
    {
        $institution = (int) db_connect()->table('colleges d')->select('d.institution_id')->join('departments sd', 'sd.college_id = d.id')->join('programs p', 'p.department_id = sd.id')
            ->join('courses c', 'c.program_id = p.id')->join('course_offerings o', 'o.course_id = c.id')->where('o.id', $this->offering)->get()->getRowArray()['institution_id'];
        $parent      = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@example.test', $institution);
        $teammate    = PeopleFactory::person('student', 'Ann', 'Lee', null, $institution);
        $loner       = PeopleFactory::person('student', 'Lou', 'Kim', null, $institution);
        CourseFactory::enroll($this->offering, $teammate);
        CourseFactory::enroll($this->offering, $loner);

        foreach ([$teammate, $loner] as $child) {
            db_connect()->table('parent_student_links')->insert(['institution_id' => $institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
        }

        db_connect()->table('assessments')->where('id', $this->assignment)->update(['status' => 'Published', 'due_date' => date('Y-m-d H:i:s', time() - 86400), 'available_until' => null]);

        // Ann's group handed in one shared row, under the other member's name.
        $group = $this->group([0]);
        db_connect()->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $teammate->id, 'joined_at' => date('Y-m-d H:i:s')]);
        AssessmentFactory::attempt($this->assignment, $this->u[0], 'Submitted', 0.0, ['group_id' => $group, 'submitted_by_user_id' => $this->u[0]->id]);

        (new ParentNotifier())->missingWork();

        $alerted = array_map('intval', array_column(db_connect()->table('parent_alerted_items')->get()->getResultArray(), 'student_user_id'));
        $this->assertNotContains((int) $teammate->id, $alerted, 'a teammate of a shared hand-in has nothing missing');
        $this->assertContains((int) $loner->id, $alerted, 'a student with no group and no hand-in is still reported');
    }
}
