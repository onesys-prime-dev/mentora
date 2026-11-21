<?php

declare(strict_types=1);

use App\Filters\CoursePermissionFilter;
use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\RouteTestCase;

/**
 * Grade Work's group view (screen 2B) and its post-grading dialogs (4D): the list of an activity's groups, one
 * group's detail, the atomic save, reopen, the late-add and drop decisions. Instructor and chairperson write, the
 * dean reads, students never reach it. Ordinary Grade Work refuses a group's submission.
 *
 * @internal
 */
final class GroupGradingRoutesTest extends RouteTestCase
{
    private int $offering;

    private int $other;

    private int $module;

    private int $assignment;

    private int $config;

    private int $team1;

    private int $team2;

    private int $shared;

    private User $teacher;

    private User $stranger;

    private User $chair;

    /** @var list<User> u0..u3 in Team 1 (u0 submits), u4 in Team 2 (nothing handed in), u5 in no group */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->offering = CourseFactory::offering('A');
        $this->other    = CourseFactory::offering('B');
        $this->module   = CourseFactory::module($this->offering);
        $this->teacher  = CourseFactory::user('instructor');
        $this->stranger = CourseFactory::user('instructor');
        $this->chair    = CourseFactory::user('chairperson');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::assignInstructor($this->other, $this->stranger);

        db_connect()->table('chairperson_assignments')->insert([
            'department_id'       => (int) db_connect()->query('SELECT p.department_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id WHERE o.id = ?', [$this->offering])->getRow()->department_id,
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->u[] = $student = CourseFactory::user('student');
            CourseFactory::enroll($this->offering, $student);
        }

        $this->assignment = CourseFactory::assessment($this->offering, $this->teacher, $this->module, null, 'Assignment');
        db_connect()->table('assessments')->where('id', $this->assignment)->update(['total_points' => 20]);
        AssessmentFactory::open($this->assignment);
        $this->config = (new ActivityGroupService())->attachAssessment($this->assignment, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 6, 'grading_mode' => 'collective_adjust'], (int) $this->teacher->id);

        $this->team1  = $this->group('Team 1', [0, 1, 2, 3]);
        $this->team2  = $this->group('Team 2', [4]);
        $this->shared = AssessmentFactory::attempt($this->assignment, $this->u[0], 'Submitted', 0.0, ['group_id' => $this->team1, 'submitted_by_user_id' => $this->u[0]->id, 'submission_text' => 'Our final report']);
    }

    /** @param list<int> $members indexes into $this->u */
    private function group(string $name, array $members, ?int $config = null): int
    {
        $db = db_connect();
        $db->table('student_groups')->insert(['set_id' => null, 'config_id' => $config ?? $this->config, 'name' => $name, 'status' => 'active']);
        $group = (int) $db->insertID();

        foreach ($members as $m) {
            $db->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $config ?? $this->config, 'student_user_id' => $this->u[$m]->id, 'joined_at' => date('Y-m-d H:i:s')]);
        }

        return $group;
    }

    /** @return array{0: int, 1: int} [group criterion, individual criterion], 10 points each */
    private function rubric(): array
    {
        $ids = AssessmentFactory::rubric($this->assignment, $this->teacher, [10, 10])['criteria'];
        db_connect()->table('assessment_rubric_criteria')->where('id', $ids[0])->update(['scope' => 'group']);
        db_connect()->table('assessment_rubric_criteria')->where('id', $ids[1])->update(['scope' => 'individual']);

        return $ids;
    }

    /** @return array{int, array<string, mixed>} status and decoded JSON body */
    private function api(User $user, string $area, string $verb, string $path = '', array $body = [], ?int $assessment = null): array
    {
        $this->loggedInAs($user);

        try {
            $res = $this->withBody(json_encode($body))
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
                ->call($verb, "{$area}/api/courses/{$this->offering}/assessments/" . ($assessment ?? $this->assignment) . "/group-grading{$path}");
        } catch (PageNotFoundException) {
            return [404, []];
        }

        return [$res->response()->getStatusCode(), json_decode((string) $res->response()->getBody(), true) ?? []];
    }

    /** Any course API call; the raw body. @return array{int, string} */
    private function raw(User $user, string $verb, string $uri, array $body = []): array
    {
        $this->loggedInAs($user);

        try {
            $res = $this->withBody(json_encode($body))
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
                ->call($verb, $uri);
        } catch (PageNotFoundException) {
            return [404, ''];
        }

        return [$res->response()->getStatusCode(), (string) $res->response()->getBody()];
    }

    private function dean(): User
    {
        $inst    = (int) db_connect()->query('SELECT t.institution_id FROM course_offerings o JOIN academic_terms t ON t.id = o.term_id WHERE o.id = ?', [$this->offering])->getRow()->institution_id;
        $college = (int) db_connect()->query('SELECT d.college_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN departments d ON d.id = p.department_id WHERE o.id = ?', [$this->offering])->getRow()->college_id;

        return DeanFactory::dean($inst, $college);
    }

    private function finalize(): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => $this->teacher->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
    }

    private function id(int $i): int
    {
        return (int) $this->u[$i]->id;
    }

    /** Collective with adjustment: 16 for the group, u2 minus 4 with a reason and a note. */
    private function gradeInput(): array
    {
        return [
            'group_score' => 16,
            'feedback'    => 'Clear structure.',
            'members'     => [
                $this->id(0) => ['adjustment' => 0],
                $this->id(1) => ['adjustment' => 0],
                $this->id(2) => ['adjustment' => -4, 'reason' => 'Missed three meetings', 'note' => 'Talk to me after class'],
                $this->id(3) => ['adjustment' => 0],
            ],
        ];
    }

    private function grade(?User $as = null,string $area = 'instructor'): array
    {
        [$status, $json] = $this->api($as ?? $this->teacher, $area, 'POST', "/{$this->team1}", $this->gradeInput());
        $this->assertSame(200, $status, (string) json_encode($json));

        return $json;
    }

    /** @return array<int, array<string, mixed>> members of the detail by student id */
    private function membersOf(array $detail): array
    {
        return array_column($detail['members'], null, 'student_user_id');
    }

    public function testTheListShowsEachGroupsStatusMemberCountAndTheUngrouped(): void
    {
        [$status, $json] = $this->api($this->teacher, 'instructor', 'GET');

        $this->assertSame(200, $status, (string) json_encode($json));
        $groups = array_column($json['groups'], null, 'id');
        $this->assertSame('needs_grading', $groups[$this->team1]['status']);
        $this->assertSame(4, $groups[$this->team1]['memberCount']);
        $this->assertSame('Team 1', $groups[$this->team1]['name']);
        $this->assertSame('not_submitted', $groups[$this->team2]['status']);
        $this->assertSame(1, $groups[$this->team2]['memberCount']);
        $this->assertSame([$this->id(5)], array_column($json['ungrouped'], 'student_user_id'));
        $this->assertIsArray($json['blockers']);
        $this->assertNotSame([], $json['blockers'], 'an ungrouped student under the "must join" policy is a blocker');
        $this->assertTrue($json['flags']['canWrite']);

        $this->grade();
        $this->assertSame('graded', array_column($this->api($this->teacher, 'instructor', 'GET')[1]['groups'], null, 'id')[$this->team1]['status']);

        // A late enrollee added after grading waits for a decision.
        (new ActivityGroupMembershipService())->addLateEnrollee($this->team1, $this->id(5), 'Enrolled late', (int) $this->teacher->id);
        $this->assertSame('pending_members', array_column($this->api($this->teacher, 'instructor', 'GET')[1]['groups'], null, 'id')[$this->team1]['status']);
    }

    public function testTheDetailCarriesSubmissionCriteriaConfigAndMembers(): void
    {
        [$groupCriterion, $ownCriterion] = $this->rubric();

        [$status, $json] = $this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}");

        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertSame('Team 1', $json['group']['name']);
        $this->assertSame('needs_grading', $json['status']);
        $this->assertSame($this->shared, $json['submission']['id']);
        $this->assertSame($this->id(0), $json['submission']['submittedBy']['id']);
        $this->assertNotSame('', $json['submission']['submittedBy']['name']);
        $this->assertFalse($json['submission']['isLate']);
        $this->assertStringContainsString('Our final report', $json['submission']['workHtml']);
        $this->assertSame(20.0, (float) $json['totalPoints']);

        $this->assertSame(['gradingMode' => 'collective_adjust', 'allowsAdjustment' => true, 'usesAverage' => false, 'submissionStyle' => 'shared', 'isRubric' => true], $json['config']);
        $criteria = array_column($json['criteria'], null, 'id');
        $this->assertSame('group', $criteria[$groupCriterion]['scope']);
        $this->assertSame('individual', $criteria[$ownCriterion]['scope']);
        $this->assertSame(10.0, (float) $criteria[$ownCriterion]['max_points']);

        $this->assertCount(4, $json['members']);
        $member = $this->membersOf($json)[$this->id(0)];

        foreach (['name', 'hasSubmission', 'individualScores', 'individualScore', 'adjustment', 'reason', 'note', 'final', 'finalPercentage', 'pending', 'excused', 'removed'] as $key) {
            $this->assertArrayHasKey($key, $member, $key);
        }

        $this->assertFalse($member['removed']);
        $this->assertNull($member['final']);
        $this->assertSame([], $json['feedback']);
        $this->assertFalse($json['flags']['graded']);
        $this->assertTrue($json['flags']['canWrite']);
    }

    public function testOneSaveFansOutToAllFourMembers(): void
    {
        $this->grade();

        $this->assertSame(4, db_connect()->table('group_member_scores')->where('submission_id', $this->shared)->countAllResults());

        [, $json] = $this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}");
        $members = $this->membersOf($json);

        $this->assertSame('graded', $json['status']);
        $this->assertTrue($json['flags']['graded']);
        $this->assertSame(16.0, (float) $json['groupScore']);
        $this->assertSame(16.0, (float) $members[$this->id(0)]['final']);
        $this->assertSame(12.0, (float) $members[$this->id(2)]['final']);
        $this->assertSame(60.0, (float) $members[$this->id(2)]['finalPercentage']);
        $this->assertSame(-4.0, (float) $members[$this->id(2)]['adjustment']);
        $this->assertSame('Missed three meetings', $members[$this->id(2)]['reason']);
        $this->assertSame('Talk to me after class', $members[$this->id(2)]['note'], 'the instructor sees each note');
        $this->assertSame('Clear structure.', end($json['feedback'])['message']);
    }

    public function testAnAdjustmentWithoutAReasonIsRefusedAndNothingIsSaved(): void
    {
        $input                                   = $this->gradeInput();
        $input['members'][$this->id(2)]['reason'] = '  ';

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}", $input);

        $this->assertSame(422, $status);
        $this->assertArrayHasKey('reason', $json['errors']);
        $this->assertNotSame('', $json['message']);
        $this->assertSame(0, db_connect()->table('group_member_scores')->countAllResults());
    }

    public function testACriterionOfTheWrongScopeIsRefused(): void
    {
        [$groupCriterion, $ownCriterion] = $this->rubric();

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}", ['group_scores' => [$ownCriterion => 5], 'members' => []]);

        $this->assertSame(422, $status);
        $this->assertArrayHasKey('rubric_scores', $json['errors']);
        $this->assertSame(0, db_connect()->table('group_member_scores')->countAllResults());

        // The right scopes save.
        $members = [];

        foreach ([0, 1, 2, 3] as $i) {
            $members[$this->id($i)] = ['individual' => [$ownCriterion => 5 + $i]];
        }

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}", ['group_scores' => [$groupCriterion => 8], 'members' => $members])[0]);
        [, $json] = $this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}");
        $this->assertSame(8.0, (float) $json['groupScores'][$groupCriterion]);
        $this->assertSame(16.0, (float) $this->membersOf($json)[$this->id(3)]['final']);
        $this->assertSame(8.0, (float) $this->membersOf($json)[$this->id(3)]['individualScores'][$ownCriterion]);
    }

    public function testReopenPutsTheGroupBackToNeedsGrading(): void
    {
        $this->grade();

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}/reopen");
        $this->assertSame(200, $status, (string) json_encode($json));

        [, $json] = $this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}");
        $this->assertSame('needs_grading', $json['status']);
        $this->assertNull($this->membersOf($json)[$this->id(2)]['final']);
        $this->assertSame('Missed three meetings', $this->membersOf($json)[$this->id(2)]['reason'], 'kept for the regrade');

        $this->assertSame(422, $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}/reopen")[0], 'nothing graded any more');
    }

    public function testALateEnrolleeIsPendingUntilTheInstructorDecides(): void
    {
        $this->grade();
        (new ActivityGroupMembershipService())->addLateEnrollee($this->team1, $this->id(5), 'Enrolled late', (int) $this->teacher->id);

        [, $json] = $this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}");
        $late = $this->membersOf($json)[$this->id(5)];
        $this->assertTrue($late['pending']);
        $this->assertNull($late['final']);

        [$status, $body] = $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}/members/{$this->id(5)}/late-decision", ['decision' => 'inherit', 'reason' => '']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('reason', $body['errors']);

        [$status, $body] = $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}/members/{$this->id(5)}/late-decision", ['decision' => 'inherit', 'reason' => 'Joined the team before the demo']);
        $this->assertSame(200, $status, (string) json_encode($body));

        $late = $this->membersOf($this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}")[1])[$this->id(5)];
        $this->assertFalse($late['pending']);
        $this->assertSame(16.0, (float) $late['final']);
        $this->assertNotNull(db_connect()->table('audit_logs')->where('action', 'groups.late_add_resolved')->get()->getRowArray());
    }

    public function testARemovedGradedMemberCanBeKeptOrExcused(): void
    {
        $this->grade();
        (new ActivityGroupMembershipService())->removeMember($this->id(3), $this->config, (int) $this->teacher->id);

        [, $json] = $this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}");
        $gone = $this->membersOf($json)[$this->id(3)];
        $this->assertTrue($gone['removed']);
        $this->assertFalse($gone['excused']);
        $this->assertSame(16.0, (float) $gone['final']);
        $this->assertFalse($this->membersOf($json)[$this->id(0)]['removed']);

        [$status, $body] = $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}/members/{$this->id(3)}/drop-decision", ['decision' => 'excuse']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('reason', $body['errors']);

        [$status, $body] = $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}/members/{$this->id(3)}/drop-decision", ['decision' => 'excuse', 'reason' => 'Dropped on 5 Dec']);
        $this->assertSame(200, $status, (string) json_encode($body));
        $this->assertTrue($this->membersOf($this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}")[1])[$this->id(3)]['excused']);

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}/members/{$this->id(3)}/drop-decision", ['decision' => 'keep', 'reason' => 'Changed my mind'])[0]);
        $this->assertFalse($this->membersOf($this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}")[1])[$this->id(3)]['excused']);
    }

    public function testForeignIdsAre404(): void
    {
        $theirs = CourseFactory::assessment($this->other, $this->stranger, CourseFactory::module($this->other), null, 'Assignment');
        $config = (new ActivityGroupService())->attachAssessment($theirs, null, [], (int) $this->stranger->id);
        $group  = (new ActivityGroupService())->addGroup($config, 'Theirs', (int) $this->stranger->id);
        $plain  = CourseFactory::assessment($this->offering, $this->teacher, $this->module, null, 'Assignment');

        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'GET', '', [], $theirs)[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'GET', '', [], $plain)[0], 'not a group activity');
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'GET', "/{$group}")[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/{$group}", $this->gradeInput())[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/{$group}/reopen")[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}/members/{$this->id(4)}/late-decision", ['decision' => 'inherit', 'reason' => 'x'])[0], 'a student of another group');
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}/members/{$this->id(5)}/drop-decision", ['decision' => 'keep', 'reason' => 'x'])[0], 'a student in no group');
        $this->assertSame(404, $this->api($this->stranger, 'instructor', 'GET')[0], 'instructor of another course');
        $this->assertSame(0, db_connect()->table('group_member_scores')->countAllResults());
    }

    public function testStudentsReachNothingAndTheDeanOnlyReads(): void
    {
        $this->grade();

        foreach ([['GET', ''], ['GET', "/{$this->team1}"], ['POST', "/{$this->team1}"], ['POST', "/{$this->team1}/reopen"]] as [$verb, $path]) {
            $this->assertContains($this->api($this->u[0], 'student', $verb, $path, $this->gradeInput())[0], [403, 404, 405], "student {$verb} {$path}");
            $this->assertContains($this->api($this->u[0], 'instructor', $verb, $path, $this->gradeInput())[0], [302, 403, 404], "instructor area {$verb} {$path}");
        }

        $dean = $this->dean();
        [$status, $json] = $this->api($dean, 'dean', 'GET');
        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertFalse($json['flags']['canWrite']);

        [$status, $json] = $this->api($dean, 'dean', 'GET', "/{$this->team1}");
        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertFalse($json['flags']['canWrite']);
        $this->assertSame('Missed three meetings', $this->membersOf($json)[$this->id(2)]['reason'], 'the dean sees adjustment reasons (4E)');

        foreach ([['POST', "/{$this->team1}"], ['POST', "/{$this->team1}/reopen"], ['POST', "/{$this->team1}/members/{$this->id(2)}/drop-decision"]] as [$verb, $path]) {
            $this->assertContains($this->api($dean, 'dean', $verb, $path, ['decision' => 'keep', 'reason' => 'x'])[0], [403, 404, 405], "dean {$verb} {$path}");
        }

        $this->assertSame('Graded', db_connect()->table('assessment_submissions')->where('id', $this->shared)->get()->getRow()->status);
    }

    public function testTheChairpersonGradesToo(): void
    {
        $this->grade($this->chair, 'chairperson');
        $this->assertSame(4, db_connect()->table('group_member_scores')->countAllResults());
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/{$this->team1}/reopen")[0]);
    }

    public function testTheFilterAsksTheGradingPermission(): void
    {
        $this->assertSame('grading.manage', CoursePermissionFilter::required("assessments/{$this->assignment}/group-grading/{$this->team1}"));
        $this->assertSame('grading.manage', CoursePermissionFilter::required("assessments/{$this->assignment}/group-grading/{$this->team1}/members/5/late-decision"));
        $this->assertSame('courses.manage', CoursePermissionFilter::required("assessments/{$this->assignment}/groups/attach"), 'unchanged');
    }

    public function testAFinalizedOfferingRefusesEveryWrite(): void
    {
        $this->grade();
        (new ActivityGroupMembershipService())->addLateEnrollee($this->team1, $this->id(5), 'Enrolled late', (int) $this->teacher->id);
        $this->finalize();

        [, $json] = $this->api($this->teacher, 'instructor', 'GET', "/{$this->team1}");
        $this->assertFalse($json['flags']['canWrite']);
        $this->assertTrue($json['flags']['finalized']);
        $this->assertNotSame('', $json['lockReason']);

        $input                        = $this->gradeInput();
        $input['group_score']         = 2;

        foreach ([["/{$this->team1}", $input], ["/{$this->team1}/reopen", []], ["/{$this->team1}/members/{$this->id(5)}/late-decision", ['decision' => 'inherit', 'reason' => 'x']], ["/{$this->team1}/members/{$this->id(1)}/drop-decision", ['decision' => 'excuse', 'reason' => 'x']]] as [$path, $body]) {
            $this->assertContains($this->api($this->teacher, 'instructor', 'POST', $path, $body)[0], [403, 409, 422, 423], $path);
        }

        $this->assertSame(16.0, (float) db_connect()->table('group_member_scores')->where('student_user_id', $this->id(0))->get()->getRow()->final_score);
        $this->assertSame('Graded', db_connect()->table('assessment_submissions')->where('id', $this->shared)->get()->getRow()->status);
    }

    public function testOrdinaryGradeWorkRefusesAGroupSubmission(): void
    {
        [$status, $body] = $this->raw($this->teacher, 'PATCH', "instructor/api/courses/{$this->offering}/submissions/{$this->shared}/grade", ['grading_mode' => 'Default', 'score' => 18]);
        $this->assertSame(422, $status, $body);
        $this->assertSame('This is a group submission. Grade it from the group view.', json_decode($body, true)['message']);
        $this->assertSame('Submitted', db_connect()->table('assessment_submissions')->where('id', $this->shared)->get()->getRow()->status);

        $this->grade();
        $this->assertSame(422, $this->raw($this->teacher, 'PATCH', "instructor/api/courses/{$this->offering}/submissions/{$this->shared}/reopen-grading")[0]);

        // Per member: a member's own row of a group activity is graded from the group view too.
        db_connect()->table('activity_group_configs')->where('id', $this->config)->update(['submission_style' => 'per_member']);
        $own = AssessmentFactory::attempt($this->assignment, $this->u[4], 'Submitted');
        $this->assertSame(422, $this->raw($this->teacher, 'PATCH', "instructor/api/courses/{$this->offering}/submissions/{$own}/grade", ['score' => 10])[0]);

        // Ordinary work is graded as before.
        $plain   = CourseFactory::assessment($this->offering, $this->teacher, $this->module, null, 'Assignment');
        $handIn  = AssessmentFactory::attempt($plain, $this->u[5], 'Submitted');
        [$status, $body] = $this->raw($this->teacher, 'PATCH', "instructor/api/courses/{$this->offering}/submissions/{$handIn}/grade", ['grading_mode' => 'Default', 'score' => 15]);
        $this->assertSame(200, $status, $body);
        $this->assertTrue(json_decode($body, true)['success']);
    }

    public function testGradeWorkDrawsTheGroupViewOnlyForAGroupActivity(): void
    {
        $plain = CourseFactory::assessment($this->offering, $this->teacher, $this->module, null, 'Assignment');

        $html = function (int $id): string {
            [$status, $body] = $this->raw($this->teacher, 'GET', "instructor/api/courses/{$this->offering}/assessments/{$id}/grade-work");
            $this->assertSame(200, $status);

            return (string) (json_decode($body, true)['html'] ?? '');
        };

        $this->assertStringContainsString('data-group-grading', $html($this->assignment));
        $this->assertStringNotContainsString('data-grade-work-student-list', $html($this->assignment));
        $this->assertStringNotContainsString('data-group-grading', $html($plain));
        $this->assertStringContainsString('data-grade-work-student-list', $html($plain));
    }

    public function testAStudentNeverSeesAnotherMembersNoteReasonOrAdjustment(): void
    {
        $this->grade();

        foreach (["student/api/courses/{$this->offering}/assessments/{$this->assignment}/view", "student/api/courses/{$this->offering}/assessments/{$this->assignment}/my-group", "student/api/courses/{$this->offering}/grades"] as $uri) {
            [$status, $body] = $this->raw($this->u[0], 'GET', $uri);
            $this->assertSame(200, $status, $uri);
            $this->assertStringNotContainsString('Talk to me after class', $body, $uri);
            $this->assertStringNotContainsString('Missed three meetings', $body, $uri);
        }

        // The member themself sees their own.
        [, $body] = $this->raw($this->u[2], 'GET', "student/api/courses/{$this->offering}/assessments/{$this->assignment}/view");
        $this->assertStringContainsString('Missed three meetings', $body);
    }

    /** Phase 10: rare numbers, so a teammate's individual score, adjustment or final would show up in any page body. */
    public function testAStudentSeesOnlyTheirOwnNumbersAndNoOtherGroupsWork(): void
    {
        [$groupCriterion, $ownCriterion] = $this->rubric();
        $own = static fn (float $points, float $adjustment = 0, ?string $reason = null, ?string $note = null): array => ['individual' => [$ownCriterion => $points], 'adjustment' => $adjustment, 'reason' => $reason, 'note' => $note];

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/{$this->team1}", [
            'group_scores' => [$groupCriterion => 8],
            'feedback'     => 'Clear structure.',
            'members'      => [$this->id(0) => $own(2.11), $this->id(1) => $own(2.5), $this->id(2) => $own(3.37, -1.13, 'Missed three meetings', 'Talk to me after class'), $this->id(3) => $own(2)],
        ]);
        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertSame(10.24, round((float) db_connect()->table('group_member_scores')->where('student_user_id', $this->id(2))->get()->getRow()->final_score, 2));

        $pages = static fn (int $o, int $a): array => ["student/api/courses/{$o}/assessments/{$a}/view", "student/api/courses/{$o}/assessments/{$a}/my-group", "student/api/courses/{$o}/grades", "student/api/courses/{$o}/assessments"];

        foreach ($pages($this->offering, $this->assignment) as $uri) {
            [$status, $body] = $this->raw($this->u[0], 'GET', $uri);
            $this->assertSame(200, $status, $uri);

            foreach (['10.24', '51.2', '3.37', '1.13', 'Missed three meetings', 'Talk to me after class'] as $theirs) {
                $this->assertStringNotContainsString($theirs, $body, "{$uri} shows u2's {$theirs}");
            }
        }

        // The control: the page does show this member's own part and percentage.
        $view = $this->raw($this->u[0], 'GET', "student/api/courses/{$this->offering}/assessments/{$this->assignment}/view")[1];
        $this->assertStringContainsString('2.11', $view);
        $this->assertStringContainsString('50.55%', $view);

        // Team 2 and the ungrouped student see nothing of Team 1: no work, feedback, members or scores.
        foreach ([4, 5] as $i) {
            foreach ($pages($this->offering, $this->assignment) as $uri) {
                [, $body] = $this->raw($this->u[$i], 'GET', $uri);

                foreach (['Our final report', 'Clear structure.', '2.11', '50.55', '10.24', $this->u[0]->username, $this->u[2]->username] as $theirs) {
                    $this->assertStringNotContainsString($theirs, $body, "u{$i}: {$uri} shows {$theirs}");
                }
            }
        }
    }

    public function testAnyMemberCanReplyOnTheGroupsFeedbackThread(): void
    {
        $this->grade();
        $uri = "student/api/courses/{$this->offering}/submissions/{$this->shared}/feedback";

        [, $body] = $this->raw($this->u[1], 'POST', $uri, ['message' => 'Thanks, we will add tests']);
        $this->assertTrue(json_decode($body, true)['success'], $body);

        [, $body] = $this->raw($this->u[4], 'POST', $uri, ['message' => 'Not my group']);
        $this->assertFalse(json_decode($body, true)['success'] ?? false, $body);

        $messages = array_column(db_connect()->table('assessment_feedbacks')->where('submission_id', $this->shared)->get()->getResultArray(), 'message');
        $this->assertContains('Thanks, we will add tests', $messages);
        $this->assertNotContains('Not my group', $messages);
    }
}
