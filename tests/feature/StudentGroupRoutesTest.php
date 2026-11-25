<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupExtensionService;
use App\Services\Groups\GroupSetService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The student's side of a group activity (screens 3A, 3C): their own group, joining, leaving and switching.
 *
 * @internal
 */
final class StudentGroupRoutesTest extends RouteTestCase
{
    private int $offering;

    private int $other;

    private int $assignment;

    private int $config;

    private int $a;

    private int $b;

    private User $teacher;

    private User $me;

    private User $mate;

    private User $loner;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->offering = CourseFactory::offering('A');
        $this->other    = CourseFactory::offering('B');
        $module         = CourseFactory::module($this->offering);
        $this->teacher  = CourseFactory::user('instructor');
        $this->me       = CourseFactory::user('student');
        $this->mate     = CourseFactory::user('student');
        $this->loner    = CourseFactory::user('student');
        $this->outsider = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->me);
        CourseFactory::enroll($this->offering, $this->mate);
        CourseFactory::enroll($this->offering, $this->loner);
        CourseFactory::enroll($this->other, $this->outsider);

        $sets = new GroupSetService();
        $set  = $sets->create($this->offering, ['name' => 'Teams', 'formation_mode' => 'self_signup', 'min_size' => 1, 'max_size' => 2], (int) $this->teacher->id);
        $sets->addGroup($set, 'Team 1', (int) $this->teacher->id);
        $sets->addGroup($set, 'Team 2', (int) $this->teacher->id);

        $this->assignment = CourseFactory::assessment($this->offering, $this->teacher, $module, null, 'Assignment');
        $this->config     = (new ActivityGroupService())->attachAssessment($this->assignment, $set, [], (int) $this->teacher->id);
        $this->a          = $this->groupId('Team 1');
        $this->b          = $this->groupId('Team 2');
    }

    private function groupId(string $name): int
    {
        return (int) db_connect()->table('student_groups')->where('config_id', $this->config)->where('name', $name)->get()->getRowArray()['id'];
    }

    private function seat(int $group, User $student): void
    {
        db_connect()->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $student->id, 'joined_at' => date('Y-m-d H:i:s')]);
    }

    private function settings(array $changes): void
    {
        db_connect()->table('activity_group_configs')->where('id', $this->config)->update($changes);
    }

    /** @return array{int, array<string, mixed>} */
    private function api(User $user, string $verb, string $tail = '', array $body = [], ?int $assessment = null, ?int $offering = null, string $area = 'student'): array
    {
        $this->loggedInAs($user);

        try {
            $res = $this->withBody(json_encode($body))
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
                ->call($verb, "{$area}/api/courses/" . ($offering ?? $this->offering) . '/assessments/' . ($assessment ?? $this->assignment) . "/my-group{$tail}");
        } catch (PageNotFoundException) {
            return [404, []];
        }

        return [$res->response()->getStatusCode(), json_decode((string) $res->response()->getBody(), true) ?? []];
    }

    public function testNonGroupAssignmentSaysSo(): void
    {
        $module = CourseFactory::module($this->offering, 'published', 2);
        $plain  = CourseFactory::assessment($this->offering, $this->teacher, $module, null, 'Assignment');

        [$status, $json] = $this->api($this->me, 'GET', '', [], $plain);

        $this->assertSame(200, $status);
        $this->assertFalse($json['isGroupActivity']);
    }

    public function testStateWithoutAGroupListsOpenGroups(): void
    {
        $this->seat($this->a, $this->mate);

        [$status, $json] = $this->api($this->me, 'GET');

        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertTrue($json['isGroupActivity']);
        $this->assertTrue($json['inPool']);
        $this->assertNull($json['myGroup']);
        $this->assertTrue($json['blocked']);
        $this->assertSame('self_signup', $json['signup']['mode']);
        $this->assertTrue($json['signup']['isOpen']);
        $this->assertCount(2, $json['candidates']);
        $this->assertArrayNotHasKey('otherGroups', $json);

        // Phase 10: an open group shows its members' names only, never their ids or anything else.
        $team1 = array_column($json['candidates'], null, 'id')[$this->a];
        $this->assertSame(['id', 'name', 'members_count', 'max', 'member_names', 'full'], array_keys($team1));
        $this->assertSame(1, $team1['members_count']);
        $this->assertContainsOnly('string', $team1['member_names']);
    }

    public function testStudentSeesOnlyTheirOwnGroupAndMembersCarryOnlyIdAndName(): void
    {
        $this->seat($this->a, $this->me);
        $this->seat($this->a, $this->mate);
        $this->seat($this->b, $this->loner);

        [, $json] = $this->api($this->me, 'GET');

        $this->assertSame('Team 1', $json['myGroup']['name']);
        $this->assertCount(2, $json['myGroup']['members']);

        foreach ($json['myGroup']['members'] as $member) {
            $this->assertSame(['id', 'name', 'avatar'], array_keys($member));
        }

        $this->assertArrayNotHasKey('otherGroups', $json);
        $this->assertNotContains((int) $this->loner->id, array_column($json['myGroup']['members'], 'id'));
    }

    public function testOtherGroupsAppearOnlyWhenTheInstructorAllowsIt(): void
    {
        $this->seat($this->a, $this->me);
        $this->seat($this->b, $this->mate);
        $this->settings(['groups_visible' => 1]);

        [, $json] = $this->api($this->me, 'GET');

        $this->assertCount(1, $json['otherGroups']);
        $this->assertSame('Team 2', $json['otherGroups'][0]['name']);

        foreach ($json['otherGroups'][0]['members'] as $member) {
            $this->assertSame(['id', 'name'], array_keys($member));
        }
    }

    public function testJoinLeaveAndSwitchWork(): void
    {
        $this->assertSame(200, $this->api($this->me, 'POST', '/join', ['group_id' => $this->a])[0]);
        [, $json] = $this->api($this->me, 'GET');
        $this->assertSame($this->a, $json['myGroup']['id']);
        $this->assertTrue($json['myGroup']['canLeave']);
        $this->assertFalse($json['blocked']);

        $this->assertSame(200, $this->api($this->me, 'POST', '/switch', ['group_id' => $this->b])[0]);
        $this->assertSame($this->b, $this->api($this->me, 'GET')[1]['myGroup']['id']);

        $this->assertSame(200, $this->api($this->me, 'POST', '/leave')[0]);
        $this->assertNull($this->api($this->me, 'GET')[1]['myGroup']);
    }

    public function testRuleViolationsAre422WithAMessage(): void
    {
        $this->seat($this->a, $this->mate);
        $this->seat($this->a, $this->loner);

        [$status, $json] = $this->api($this->me, 'POST', '/join', ['group_id' => $this->a]);
        $this->assertSame(422, $status);
        $this->assertSame('Group is full', $json['message']);

        $this->settings(['signup_closes_at' => date('Y-m-d H:i:s', time() - 60)]);
        [$status, $json] = $this->api($this->me, 'POST', '/join', ['group_id' => $this->b]);
        $this->assertSame(422, $status);
        $this->assertSame('Signup is closed', $json['message']);
        $state = $this->api($this->me, 'GET')[1];
        $this->assertFalse($state['signup']['isOpen']);
        $this->assertSame([], $state['candidates']);

        $this->settings(['signup_closes_at' => null]);
        db_connect()->table('student_groups')->where('id', $this->a)->update(['frozen_at' => date('Y-m-d H:i:s')]);
        [$status, $json] = $this->api($this->mate, 'POST', '/leave');
        $this->assertSame(422, $status);
        $this->assertStringContainsString('has submitted', $json['message']);

        $state = $this->api($this->mate, 'GET')[1];
        $this->assertTrue($state['myGroup']['frozen']);
        $this->assertFalse($state['myGroup']['canLeave']);
    }

    public function testForeignIdsAre404(): void
    {
        $this->assertSame(404, $this->api($this->me, 'GET', '', [], $this->assignment, $this->other)[0]);

        $foreign = CourseFactory::assessment($this->other, CourseFactory::user('instructor'), CourseFactory::module($this->other), null, 'Assignment');
        $this->assertSame(404, $this->api($this->me, 'GET', '', [], $foreign)[0]);

        $this->assertSame(404, $this->api($this->me, 'POST', '/join', ['group_id' => 999999])[0]);

        // A group of another activity of this course is not found either.
        $second = CourseFactory::assessment($this->offering, $this->teacher, CourseFactory::module($this->offering, 'published', 3), null, 'Assignment');
        $source = (int) db_connect()->table('activity_group_configs')->where('id', $this->config)->get()->getRowArray()['source_set_id'];
        (new ActivityGroupService())->attachAssessment($second, $source, [], (int) $this->teacher->id);
        $this->assertSame(404, $this->api($this->me, 'POST', '/join', ['group_id' => $this->a], $second)[0]);
    }

    public function testStudentOfAnotherCourseIsRefused(): void
    {
        $this->assertNotSame(200, $this->api($this->outsider, 'GET')[0]);
        $this->assertNotSame(200, $this->api($this->outsider, 'POST', '/join', ['group_id' => $this->a])[0]);
    }

    public function testStaffCannotUseTheStudentRoutes(): void
    {
        foreach (['instructor', 'dean', 'chairperson'] as $area) {
            $this->assertNotSame(200, $this->api($this->teacher, 'GET', '', [], null, null, $area)[0], $area);
        }

        $this->assertNotSame(200, $this->api($this->teacher, 'GET')[0]);
        $this->assertNotSame(200, $this->api($this->teacher, 'POST', '/join', ['group_id' => $this->a])[0]);
        $this->assertSame([], db_connect()->table('student_group_members')->where('student_user_id', $this->teacher->id)->get()->getResultArray());
    }

    public function testStudentOutsideTheActivityIsNotInThePoolAndCannotJoin(): void
    {
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->assignment, 'assign_to_specific_students' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('assessment_assignees')->insert(['assessment_id' => $this->assignment, 'student_user_id' => $this->mate->id]);

        [$status, $json] = $this->api($this->me, 'GET');
        $this->assertSame(200, $status);
        $this->assertFalse($json['inPool']);
        $this->assertFalse($json['blocked']);
        $this->assertSame([], $json['candidates']);

        $this->assertSame(422, $this->api($this->me, 'POST', '/join', ['group_id' => $this->a])[0]);
    }

    public function testFinalizedOfferingRefusesWrites(): void
    {
        $this->seat($this->a, $this->me);
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        [$status, $json] = $this->api($this->me, 'POST', '/leave');
        $this->assertSame(422, $status, (string) json_encode($json));

        $state = $this->api($this->me, 'GET')[1];
        $this->assertTrue($state['offeringFinalized']);
        $this->assertFalse($state['myGroup']['canLeave']);
    }

    public function testVotingShowsTheTallyAndAMajorityMakesTheLeader(): void
    {
        $this->seat($this->a, $this->me);
        $this->seat($this->a, $this->mate);

        [, $json] = $this->api($this->me, 'GET');
        $this->assertSame(['id' => null, 'final' => false, 'votes' => [], 'needed' => 2, 'myVote' => null], $json['myGroup']['leader']);
        $this->assertSame('active', $json['myGroup']['status']);
        $this->assertTrue($json['myGroup']['canRename']);

        [$status, $json] = $this->api($this->me, 'POST', '/vote', ['candidate_id' => (int) $this->mate->id]);
        $this->assertSame(200, $status, (string) json_encode($json));
        $leader = $this->api($this->me, 'GET')[1]['myGroup']['leader'];
        $this->assertSame([['candidateId' => (int) $this->mate->id, 'count' => 1]], $leader['votes']);
        $this->assertSame((int) $this->mate->id, $leader['myVote']);
        $this->assertFalse($leader['final']);

        $this->assertSame(200, $this->api($this->mate, 'POST', '/vote', ['candidate_id' => (int) $this->mate->id])[0]);
        $leader = $this->api($this->me, 'GET')[1]['myGroup']['leader'];
        $this->assertSame((int) $this->mate->id, $leader['id']);
        $this->assertTrue($leader['final']);

        // A vote for someone outside the group, or by a student without a group, is a broken rule.
        $this->assertSame(422, $this->api($this->me, 'POST', '/vote', ['candidate_id' => (int) $this->loner->id])[0]);
        $this->assertSame(422, $this->api($this->loner, 'POST', '/vote', ['candidate_id' => (int) $this->me->id])[0]);
    }

    public function testRenameIsOpenUntilALeaderIsFinalThenOnlyTheLeaderOrTheToggle(): void
    {
        $this->seat($this->a, $this->me);
        $this->seat($this->a, $this->mate);

        $this->assertSame(200, $this->api($this->me, 'POST', '/rename', ['name' => 'Night Owls'])[0]);
        $this->assertSame('Night Owls', $this->api($this->me, 'GET')[1]['myGroup']['name']);

        db_connect()->table('student_groups')->where('id', $this->a)->update(['leader_user_id' => $this->mate->id, 'leader_finalized_at' => date('Y-m-d H:i:s')]);
        $this->assertFalse($this->api($this->me, 'GET')[1]['myGroup']['canRename']);
        $this->assertTrue($this->api($this->mate, 'GET')[1]['myGroup']['canRename']);
        [$status, $json] = $this->api($this->me, 'POST', '/rename', ['name' => 'Mine']);
        $this->assertSame(422, $status);
        $this->assertSame('Only the group leader can rename the group.', $json['message']);
        $this->assertSame(200, $this->api($this->mate, 'POST', '/rename', ['name' => 'Leaders'])[0]);

        $this->settings(['nonleader_can_rename' => 1]);
        $this->assertTrue($this->api($this->me, 'GET')[1]['myGroup']['canRename']);
        $this->assertSame(200, $this->api($this->me, 'POST', '/rename', ['name' => 'Ours'])[0]);

        $this->assertSame(422, $this->api($this->me, 'POST', '/rename', ['name' => ' '])[0]);
        $this->assertSame(422, $this->api($this->loner, 'POST', '/rename', ['name' => 'Hijack'])[0]);
        $this->assertSame('Ours', db_connect()->table('student_groups')->where('id', $this->a)->get()->getRow()->name);
    }

    private function proposalMode(): void
    {
        $this->settings(['formation_mode' => 'proposed', 'min_size' => 2, 'max_size' => 3]);
    }

    public function testProposeInviteAcceptAndDecline(): void
    {
        $this->proposalMode();

        [, $json] = $this->api($this->me, 'GET');
        $this->assertEqualsCanonicalizing([(int) $this->loner->id, (int) $this->mate->id], array_column($json['invitable'], 'id'), 'the ungrouped pool without me or outsiders');

        foreach ($json['invitable'] as $s) {
            $this->assertSame(['id', 'name'], array_keys($s));
        }

        [$status, $json] = $this->api($this->me, 'POST', '/propose', ['name' => 'Our team', 'invitee_ids' => [(int) $this->mate->id, (int) $this->loner->id]]);
        $this->assertSame(200, $status, (string) json_encode($json));
        $groupId = (int) $json['id'];

        [, $mine] = $this->api($this->me, 'GET');
        $this->assertSame('proposed', $mine['myGroup']['status']);
        $this->assertSame('Our team', $mine['myGroup']['name']);
        $this->assertCount(2, $mine['myGroup']['proposal']['pending']);
        $this->assertSame([], $mine['invitable'], 'a grouped student invites nobody');

        [, $theirs] = $this->api($this->mate, 'GET');
        $this->assertNull($theirs['myGroup']);
        $this->assertSame($groupId, $theirs['pendingInvitations'][0]['groupId']);
        $this->assertSame(['groupId', 'name', 'proposer', 'members'], array_keys($theirs['pendingInvitations'][0]));
        $this->assertSame('Our team', $theirs['pendingInvitations'][0]['name']);

        $this->assertSame(200, $this->api($this->mate, 'POST', "/invitations/{$groupId}/accept")[0]);
        $this->assertSame(200, $this->api($this->loner, 'POST', "/invitations/{$groupId}/decline")[0]);
        $this->assertSame(422, $this->api($this->loner, 'POST', "/invitations/{$groupId}/accept")[0], 'a declined invitation is gone');

        [, $mine] = $this->api($this->me, 'GET');
        $this->assertCount(2, $mine['myGroup']['members']);
        $this->assertSame([], $mine['myGroup']['proposal']['pending']);
        $this->assertSame([], $this->api($this->loner, 'GET')[1]['pendingInvitations']);

        foreach ($mine['myGroup']['members'] as $member) {
            $this->assertSame(['id', 'name', 'avatar'], array_keys($member));
        }
    }

    public function testProposalRulesAre422AndForeignInvitationsAre404(): void
    {
        $this->proposalMode();

        $this->assertSame(422, $this->api($this->me, 'POST', '/propose', ['name' => 'X', 'invitee_ids' => [(int) $this->outsider->id]])[0]);
        $this->assertSame(422, $this->api($this->me, 'POST', '/propose', ['name' => '', 'invitee_ids' => [(int) $this->mate->id]])[0]);

        $this->assertSame(404, $this->api($this->mate, 'POST', '/invitations/999999/accept')[0]);
        $this->assertSame(404, $this->api($this->mate, 'POST', "/invitations/{$this->a}/accept", [], null, $this->other)[0]);

        // A proposal of another activity of this course is not found through this activity.
        $second = CourseFactory::assessment($this->offering, $this->teacher, CourseFactory::module($this->offering, 'published', 3), null, 'Assignment');
        $config = (new ActivityGroupService())->attachAssessment($second, null, [], (int) $this->teacher->id);
        db_connect()->table('activity_group_configs')->where('id', $config)->update(['formation_mode' => 'proposed', 'min_size' => 1, 'max_size' => 3]);
        $theirs = (new \App\Services\Groups\GroupProposalService())->propose($config, (int) $this->loner->id, [(int) $this->mate->id], 'Elsewhere');
        $this->assertSame(404, $this->api($this->mate, 'POST', "/invitations/{$theirs}/accept")[0]);
        $this->assertSame(404, $this->api($this->mate, 'POST', "/invitations/{$theirs}/decline")[0]);

        // Outside proposal mode there is nothing to invite and proposing is refused.
        $this->settings(['formation_mode' => 'self_signup']);
        $this->assertSame([], $this->api($this->me, 'GET')[1]['invitable']);
        $this->assertSame(422, $this->api($this->me, 'POST', '/propose', ['name' => 'X', 'invitee_ids' => [(int) $this->mate->id]])[0]);
    }

    public function testNewWritesAreRefusedToStaffOutsidersAndOnAFinalizedOffering(): void
    {
        $this->seat($this->a, $this->me);
        $this->seat($this->a, $this->mate);

        foreach (['/vote', '/rename', '/propose', "/invitations/{$this->a}/accept"] as $tail) {
            $this->assertNotSame(200, $this->api($this->teacher, 'POST', $tail, ['candidate_id' => (int) $this->me->id, 'name' => 'X'])[0], $tail);
            $this->assertNotSame(200, $this->api($this->outsider, 'POST', $tail, ['candidate_id' => (int) $this->me->id, 'name' => 'X'])[0], $tail);
        }

        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
        $this->assertSame(422, $this->api($this->me, 'POST', '/vote', ['candidate_id' => (int) $this->me->id])[0]);
        $this->assertSame(422, $this->api($this->me, 'POST', '/rename', ['name' => 'Late'])[0]);
        $this->assertFalse($this->api($this->me, 'GET')[1]['myGroup']['canRename']);
        $this->assertSame('Team 1', db_connect()->table('student_groups')->where('id', $this->a)->get()->getRow()->name);
        $this->assertSame(0, db_connect()->table('student_group_leader_votes')->countAllResults());
    }

    public function testMyGroupCarriesTheExtensionAndMembersStayIdAndName(): void
    {
        $this->seat($this->a, $this->me);
        $this->seat($this->a, $this->mate);

        $this->assertNull($this->api($this->me, 'GET')[1]['myGroup']['extension']);

        (new GroupExtensionService())->extend($this->a, $this->assignment, date('Y-m-d H:i:s', strtotime('+30 days')), 'Power cut', (int) $this->teacher->id);
        $group = $this->api($this->me, 'GET')[1]['myGroup'];

        $this->assertSame('Power cut', $group['extension']['reason']);
        $this->assertSame(date('Y-m-d H:i', strtotime('+30 days')), substr($group['extension']['until'], 0, 16));

        foreach ($group['members'] as $member) {
            $this->assertSame(['id', 'name', 'avatar'], array_keys($member));
        }
    }

    /** Published, with text entry on, so the only thing that can stop a student is the group. */
    private function openAssignment(): void
    {
        $db = db_connect();

        if ($db->table('assessment_settings')->where('assessment_id', $this->assignment)->countAllResults() === 0) {
            $db->table('assessment_settings')->insert(['assessment_id' => $this->assignment, 'allow_file_upload' => 0, 'allow_text_entry' => 1, 'allow_link_entry' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $db->table('assessment_settings')->where('assessment_id', $this->assignment)->update(['allow_text_entry' => 1]);
    }

    /** The assignment as a student's page draws it: the server writes the message and keeps the form, disabled. */
    private function pageFor(User $student): string
    {
        $this->openAssignment();

        $module = (int) db_connect()->table('assessments')->where('id', $this->assignment)->get()->getRow()->module_id;
        [$item] = CourseFactory::items(CourseFactory::unit($module), [['title' => 'Team project', 'type' => 'assignment']]);
        db_connect()->table('assessments')->where('id', $this->assignment)->update(['learning_item_id' => $item, 'status' => 'Published']);

        $this->loggedInAs($student);
        $res  = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->call('get', "student/api/courses/{$this->offering}/modules/items/{$item}");
        $body = json_decode((string) $res->response()->getBody(), true);

        return html_entity_decode(implode(' ', array_filter((array) $body, 'is_string')), ENT_QUOTES);
    }

    public function testAnUngroupedStudentSeesTheJoinWarningAndADisabledForm(): void
    {
        $html = $this->pageFor($this->loner);

        $this->assertStringContainsString("You're not in a group yet. You can't submit until you join one.", $html);
        $this->assertStringContainsString('data-assignment-submission-form', $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*disabled/', $html);
        $this->assertStringContainsString('data-sg-jump', $html);
    }

    public function testANonLeaderSeesWhoCanSubmit(): void
    {
        $this->settings(['submission_style' => 'leader_only']);
        $this->seat($this->a, $this->me);
        $this->seat($this->a, $this->mate);
        db_connect()->table('student_groups')->where('id', $this->a)->update(['leader_user_id' => $this->mate->id, 'leader_finalized_at' => date('Y-m-d H:i:s')]);
        $name = (string) db_connect()->query("SELECT COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username) AS n FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?", [$this->mate->id])->getRow()->n;

        $html = $this->pageFor($this->me);

        $this->assertStringContainsString("Only {$name} can submit for your group.", $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*disabled/', $html);
    }

    public function testAStudentOutsideThePoolIsToldTheActivityWasNotAssignedToThem(): void
    {
        $this->openAssignment();
        db_connect()->table('assessment_settings')->where('assessment_id', $this->assignment)->update(['assign_to_specific_students' => 1]);
        db_connect()->table('assessment_assignees')->insert(['assessment_id' => $this->assignment, 'student_user_id' => $this->mate->id]);

        $this->assertStringContainsString('This activity was not assigned to you.', $this->pageFor($this->loner));
    }

    public function testSoloPolicyUngroupedStudentSeesNoBlockingMessage(): void
    {
        $this->settings(['ungrouped_policy' => 'solo']);

        $html = $this->pageFor($this->loner);

        $this->assertStringNotContainsString("You're not in a group yet", $html);
        $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*disabled/', $html);
    }

    public function testMembersSeeTheExtensionNoticeAndWhoSubmitted(): void
    {
        $this->seat($this->a, $this->me);
        $this->seat($this->a, $this->mate);
        (new GroupExtensionService())->extend($this->a, $this->assignment, date('Y-m-d H:i:s', strtotime('+30 days')), 'Power cut', (int) $this->teacher->id);

        $html = $this->pageFor($this->me);

        $this->assertStringContainsString('Deadline extended for your group to ' . date('M j, Y g:i A', strtotime('+30 days')), $html);
        $this->assertStringContainsString('Reason: Power cut', $html);
    }

    public function testAMemberSeesWhoSubmittedForTheGroup(): void
    {
        $this->seat($this->a, $this->me);
        $this->seat($this->a, $this->mate);
        $when = '2026-11-20 14:30:00';
        db_connect()->table('assessment_submissions')->insert(['assessment_id' => $this->assignment, 'student_id' => $this->mate->id, 'group_id' => $this->a, 'submitted_by_user_id' => $this->mate->id,
            'attempt_number' => 1, 'status' => 'Submitted', 'submission_text' => 'Our work', 'started_at' => $when, 'submitted_at' => $when]);
        $name = (string) db_connect()->query("SELECT COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username) AS n FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?", [$this->mate->id])->getRow()->n;

        $this->assertStringContainsString("Submitted by {$name} on Nov 20, 2026 2:30 PM.", $this->pageFor($this->me));
    }

    public function testTheModuleLearningItemOfAGroupAssignmentCarriesTheStudentCard(): void
    {
        $module = (int) db_connect()->table('assessments')->where('id', $this->assignment)->get()->getRow()->module_id;
        [$item] = CourseFactory::items(CourseFactory::unit($module), [['title' => 'Team project', 'type' => 'assignment']]);
        db_connect()->table('assessments')->where('id', $this->assignment)->update(['learning_item_id' => $item]);

        $this->loggedInAs($this->me);
        $res  = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->call('get', "student/api/courses/{$this->offering}/modules/items/{$item}");
        $body = json_decode((string) $res->response()->getBody(), true);
        $html = implode(' ', array_filter((array) $body, 'is_string'));

        $this->assertStringContainsString('data-student-group', $html);
        $this->assertStringContainsString('data-assessment-id="' . $this->assignment . '"', $html);
    }
}
