<?php

declare(strict_types=1);

use App\Filters\CoursePermissionFilter;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupMembershipService;
use App\Services\Groups\GroupSetService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\RouteTestCase;

/**
 * The group endpoints of one assignment (screen 2A): instructor and chairperson change them, the dean only reads,
 * students never reach them.
 *
 * @internal
 */
final class ActivityGroupsRoutesTest extends RouteTestCase
{
    private int $offering;

    private int $other;

    private int $module;

    private int $assignment;

    private int $set;

    private User $teacher;

    private User $stranger;

    private User $student;

    private User $second;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->offering = CourseFactory::offering('A');
        $this->other    = CourseFactory::offering('B');
        $this->module   = CourseFactory::module($this->offering);
        $this->teacher  = CourseFactory::user('instructor');
        $this->stranger = CourseFactory::user('instructor');
        $this->student  = CourseFactory::user('student');
        $this->second   = CourseFactory::user('student');
        $this->chair    = CourseFactory::user('chairperson');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::assignInstructor($this->other, $this->stranger);
        CourseFactory::enroll($this->offering, $this->student);
        CourseFactory::enroll($this->offering, $this->second);

        db_connect()->table('chairperson_assignments')->insert([
            'department_id'       => (int) db_connect()->query('SELECT p.department_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id WHERE o.id = ?', [$this->offering])->getRow()->department_id,
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->assignment = CourseFactory::assessment($this->offering, $this->teacher, $this->module, null, 'Assignment');

        $sets      = new GroupSetService();
        $this->set = $sets->create($this->offering, ['name' => 'Project teams', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 3], (int) $this->teacher->id);
        $g         = $sets->addGroup($this->set, 'Team 1', (int) $this->teacher->id);
        (new GroupMembershipService())->addToSet($g, (int) $this->student->id, (int) $this->teacher->id);
    }

    /** @return array{int, array<string, mixed>} status and decoded JSON body */
    private function api(User $user, string $area, string $verb, string $path = '', array $body = [], ?int $assessment = null, ?int $offering = null): array
    {
        $this->loggedInAs($user);

        try {
            $res = $this->withBody(json_encode($body))
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
                ->call($verb, "{$area}/api/courses/" . ($offering ?? $this->offering) . '/assessments/' . ($assessment ?? $this->assignment) . "/groups{$path}");
        } catch (PageNotFoundException) {
            return [404, []];
        }

        return [$res->response()->getStatusCode(), json_decode((string) $res->response()->getBody(), true) ?? []];
    }

    private function attach(string $area = 'instructor', ?User $as = null): array
    {
        [$status, $json] = $this->api($as ?? $this->teacher, $area, 'POST', '/attach', ['set_id' => $this->set, 'settings' => ['submission_style' => 'leader_only']]);
        $this->assertSame(200, $status, (string) json_encode($json));

        return $this->api($as ?? $this->teacher, $area, 'GET')[1];
    }

    private function dean(): User
    {
        $inst    = (int) db_connect()->query('SELECT t.institution_id FROM course_offerings o JOIN academic_terms t ON t.id = o.term_id WHERE o.id = ?', [$this->offering])->getRow()->institution_id;
        $college = (int) db_connect()->query('SELECT d.college_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN departments d ON d.id = p.department_id WHERE o.id = ?', [$this->offering])->getRow()->college_id;

        return DeanFactory::dean($inst, $college);
    }

    private function freeze(int $groupId): void
    {
        db_connect()->table('student_groups')->where('id', $groupId)->update(['frozen_at' => date('Y-m-d H:i:s')]);
    }

    public function testShowBeforeAttachIsEmptyWithTheSets(): void
    {
        [$status, $json] = $this->api($this->teacher, 'instructor', 'GET');

        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertNull($json['config']);
        $this->assertSame([], $json['groups']);
        $this->assertSame('Project teams', $json['sets'][0]['name']);
        $this->assertSame(1, (int) $json['sets'][0]['group_count']);
        $this->assertTrue($json['flags']['canWrite']);
        $this->assertFalse($json['flags']['frozenGroups']);
        $this->assertFalse($json['flags']['offeringFinalized']);
    }

    public function testInstructorAttachesChangesSettingsPlacesAndUnlocks(): void
    {
        $detail = $this->attach();

        $this->assertSame('leader_only', $detail['config']['submission_style']);
        $this->assertSame($this->set, (int) $detail['config']['source_set_id']);
        $this->assertSame('Team 1', $detail['groups'][0]['name']);
        $this->assertSame((int) $this->student->id, (int) $detail['groups'][0]['members'][0]['student_user_id']);
        $this->assertSame((int) $this->second->id, (int) $detail['ungrouped'][0]['student_user_id']);
        $g1 = (int) $detail['groups'][0]['id'];

        [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['grading_mode' => 'individual', 'groups_visible' => true, 'max_size' => 4]]);
        $this->assertSame(200, $status, (string) json_encode($json));

        [$status, $g] = $this->api($this->teacher, 'instructor', 'POST', '/groups', ['name' => 'Team 2']);
        $this->assertSame(200, $status, (string) json_encode($g));
        $g2 = (int) $g['id'];
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'PUT', "/groups/{$g2}", ['name' => 'Beta'])[0]);

        $sid = (int) $this->second->id;
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$g2}/members", ['student_user_id' => $sid])[0]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', '/members/move', ['student_user_id' => $sid, 'to_group_id' => $g1])[0]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'DELETE', "/members/{$sid}")[0]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'DELETE', "/groups/{$g2}")[0]);

        [, $detail] = $this->api($this->teacher, 'instructor', 'GET');
        $this->assertSame('individual', $detail['config']['grading_mode']);
        $this->assertSame(1, (int) $detail['config']['groups_visible']);
        $this->assertSame(4, (int) $detail['config']['max_size']);
        $this->assertCount(1, $detail['groups']);

        // Frozen: a move is refused, the group says so, unlock needs a reason, a late enrollee can be added.
        $this->freeze($g1);
        [, $detail] = $this->api($this->teacher, 'instructor', 'GET');
        $this->assertTrue($detail['groups'][0]['frozen']);
        $this->assertTrue($detail['flags']['frozenGroups']);

        $this->assertSame(422, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$g1}/members", ['student_user_id' => $sid])[0]);
        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/groups/{$g1}/late-members", ['student_user_id' => $sid, 'reason' => '']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('reason', $json['errors']);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$g1}/late-members", ['student_user_id' => $sid, 'reason' => 'Enrolled late'])[0]);

        // Style cannot change while frozen; the server's message comes back.
        [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['submission_style' => 'shared']]);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('submission_style', $json['errors']);

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/groups/{$g1}/unlock", ['reason' => ' ']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('reason', $json['errors']);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$g1}/unlock", ['reason' => 'Transferred'])[0]);
        $this->assertNull(db_connect()->table('student_groups')->where('id', $g1)->get()->getRow()->frozen_at);
    }

    public function testChangingUngroupedPolicyAfterAFreezeNeedsConfirmation(): void
    {
        $detail = $this->attach();
        $this->freeze((int) $detail['groups'][0]['id']);

        [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['ungrouped_policy' => 'solo']]);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('confirm_required', $json['errors']);
        $this->assertSame('block', db_connect()->table('activity_group_configs')->where('assessment_id', $this->assignment)->get()->getRow()->ungrouped_policy);

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['ungrouped_policy' => 'solo'], 'confirmed' => true])[0]);
        $this->assertSame('solo', db_connect()->table('activity_group_configs')->where('assessment_id', $this->assignment)->get()->getRow()->ungrouped_policy);
    }

    public function testFormationModeAndSignupWindowAreSavedAuditedAndValidated(): void
    {
        $detail = $this->attach();

        [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['formation_mode' => 'self_signup', 'signup_opens_at' => '2030-01-01T08:00', 'signup_closes_at' => '2030-01-02T08:00']]);
        $this->assertSame(200, $status, (string) json_encode($json));
        $row = db_connect()->table('activity_group_configs')->where('assessment_id', $this->assignment)->get()->getRow();
        $this->assertSame('self_signup', $row->formation_mode);
        $this->assertSame('2030-01-02 08:00:00', $row->signup_closes_at);
        $audit = db_connect()->table('audit_logs')->where('action', 'groups.settings_changed')->get()->getResultArray();
        $this->assertStringContainsString('self_signup', json_encode($audit));

        [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['signup_closes_at' => '2029-12-31T08:00']]);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('signup_closes_at', $json['errors']);

        $this->freeze((int) $detail['groups'][0]['id']);
        [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['formation_mode' => 'manual']]);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('formation_mode', $json['errors']);
    }

    public function testRecopyCopyFromAndDetach(): void
    {
        $this->attach();
        $second = CourseFactory::assessment($this->offering, $this->teacher, $this->module, null, 'Assignment');
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', '/attach', [], $second)[0]);

        (new GroupSetService())->addGroup($this->set, 'Team 2', (int) $this->teacher->id);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', '/recopy')[0]);
        $this->assertCount(2, $this->api($this->teacher, 'instructor', 'GET')[1]['groups']);

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', '/copy-from', ['from_assessment_id' => $this->assignment], $second);
        $this->assertSame(200, $status, (string) json_encode($json));
        [, $copied] = $this->api($this->teacher, 'instructor', 'GET', '', [], $second);
        $this->assertSame(['Team 1', 'Team 2'], array_column($copied['groups'], 'name'));

        // The detail lists the other group activities of the course to copy from.
        $this->assertSame([$this->assignment], array_map('intval', array_column($copied['activities'], 'assessment_id')));

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'DELETE', '', [], $second)[0]);
        $this->assertNull($this->api($this->teacher, 'instructor', 'GET', '', [], $second)[1]['config']);
    }

    public function testCopyFromAnActivityOfAnotherOfferingIsRefused(): void
    {
        $this->attach();
        $theirs = CourseFactory::assessment($this->other, $this->stranger, CourseFactory::module($this->other), null, 'Assignment');
        (new ActivityGroupService())->attachAssessment($theirs, null, [], (int) $this->stranger->id);

        $this->assertContains($this->api($this->teacher, 'instructor', 'POST', '/copy-from', ['from_assessment_id' => $theirs])[0], [404, 422]);
    }

    public function testAQuizCannotBecomeAGroupActivity(): void
    {
        $quiz = CourseFactory::assessment($this->offering, $this->teacher, $this->module, null, 'Quiz');

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', '/attach', [], $quiz);
        $this->assertSame(422, $status);
        $this->assertNotSame('', (string) $json['message']);
        $this->assertNull((new ActivityGroupService())->findByAssessment($quiz));
    }

    public function testAnAssessmentOfAnotherCourseAndAForeignGroupAre404(): void
    {
        $detail = $this->attach();
        $theirs = CourseFactory::assessment($this->other, $this->stranger, CourseFactory::module($this->other), null, 'Assignment');
        $config = (new ActivityGroupService())->attachAssessment($theirs, null, [], (int) $this->stranger->id);
        $group  = (new ActivityGroupService())->addGroup($config, 'Theirs', (int) $this->stranger->id);

        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'GET', '', [], $theirs)[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', '/attach', [], $theirs)[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'PUT', "/groups/{$group}", ['name' => 'x'])[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'DELETE', "/groups/{$group}")[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$group}/unlock", ['reason' => 'x'])[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', '/members/move', ['student_user_id' => (int) $this->student->id, 'to_group_id' => $group])[0]);
        $this->assertSame(404, $this->api($this->stranger, 'instructor', 'GET')[0], 'instructor of another course');
        $this->assertSame('Theirs', db_connect()->table('student_groups')->where('id', $group)->get()->getRow()->name);
        $this->assertCount(1, $detail['groups']);
    }

    public function testAStudentReachesNoRoute(): void
    {
        $this->attach();

        foreach ([['GET', ''], ['POST', '/attach'], ['PUT', '/settings'], ['DELETE', ''], ['POST', '/groups'], ['POST', '/members/move']] as [$verb, $path]) {
            $this->assertContains($this->api($this->student, 'student', $verb, $path)[0], [403, 404, 405], "{$verb} {$path}");
            $this->assertContains($this->api($this->student, 'instructor', $verb, $path)[0], [302, 403, 404], "instructor area {$verb} {$path}");
        }
    }

    public function testTheDeanReadsButCannotChange(): void
    {
        $this->attach();
        $dean = $this->dean();

        [$status, $json] = $this->api($dean, 'dean', 'GET');
        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertFalse($json['flags']['canWrite']);
        $this->assertSame('Team 1', $json['groups'][0]['name']);

        foreach ([['POST', '/attach'], ['PUT', '/settings'], ['DELETE', ''], ['POST', '/recopy'], ['POST', '/groups'], ['POST', '/members/move'], ['POST', '/groups/1/unlock']] as [$verb, $path]) {
            $this->assertContains($this->api($dean, 'dean', $verb, $path, ['name' => 'x'])[0], [403, 404, 405], "{$verb} {$path}");
        }

        $this->assertNotNull((new ActivityGroupService())->findByAssessment($this->assignment));
    }

    public function testTheChairpersonMirrorsTheInstructor(): void
    {
        $detail = $this->attach('chairperson', $this->chair);
        $g1     = (int) $detail['groups'][0]['id'];

        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'PUT', '/settings', ['changes' => ['nonleader_can_leave' => false]])[0]);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/groups/{$g1}/members", ['student_user_id' => (int) $this->second->id])[0]);
        $this->freeze($g1);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/groups/{$g1}/unlock", ['reason' => 'Fix'])[0]);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'DELETE')[0]);
    }

    public function testAFinalizedOfferingRefusesEveryWrite(): void
    {
        $detail = $this->attach();
        $g1     = (int) $detail['groups'][0]['id'];
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => $this->teacher->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        [, $json] = $this->api($this->teacher, 'instructor', 'GET');
        $this->assertTrue($json['flags']['offeringFinalized']);
        $this->assertFalse($json['flags']['canWrite']);

        foreach ([['PUT', '/settings', ['changes' => ['grading_mode' => 'individual']]], ['POST', '/groups', ['name' => 'X']], ['PUT', "/groups/{$g1}", ['name' => 'X']], ['POST', "/groups/{$g1}/unlock", ['reason' => 'x']], ['DELETE', '', []]] as [$verb, $path, $body]) {
            $this->assertContains($this->api($this->teacher, 'instructor', $verb, $path, $body)[0], [403, 409, 422, 423], "{$verb} {$path}");
        }

        $this->assertSame('collective', db_connect()->table('activity_group_configs')->where('assessment_id', $this->assignment)->get()->getRow()->grading_mode);
        $this->assertSame('Team 1', db_connect()->table('student_groups')->where('id', $g1)->get()->getRow()->name);
    }

    public function testOnlyTheAssignmentEditorHasTheGroupActivitySection(): void
    {
        $quiz = CourseFactory::assessment($this->offering, $this->teacher, $this->module, null, 'Quiz');
        $this->loggedInAs($this->teacher);

        $html = function (int $id): string {
            $res = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->call('GET', "instructor/api/courses/{$this->offering}/assessments/{$id}/editor");
            $this->assertSame(200, $res->response()->getStatusCode());

            return (string) (json_decode((string) $res->response()->getBody(), true)['html'] ?? $res->response()->getBody());
        };

        $this->assertStringContainsString('data-group-activity', $html($this->assignment));
        $this->assertStringNotContainsString('data-group-activity', $html($quiz));
    }

    /** Proposal mode with two student proposals: one at the minimum, one below it with a pending invitee. */
    private function proposals(): array
    {
        $this->attach();
        $config = (int) (new ActivityGroupService())->findByAssessment($this->assignment)['id'];
        $third  = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $third);
        db_connect()->table('student_group_members')->where('config_id', $config)->delete();
        db_connect()->table('student_groups')->where('config_id', $config)->delete();
        db_connect()->table('activity_group_configs')->where('id', $config)->update(['formation_mode' => 'proposed', 'min_size' => 2, 'max_size' => 3]);

        $service = new \App\Services\Groups\GroupProposalService();
        $full    = $service->propose($config, (int) $this->student->id, [(int) $this->second->id], 'Ready');
        $service->respondToInvitation($full, (int) $this->second->id, true);
        $short = $service->propose($config, (int) $third->id, [], 'Short');

        return [$full, $short, $config];
    }

    public function testTheProposalQueueListsProposalsAndTheWindowSummary(): void
    {
        [$full, $short] = $this->proposals();

        [$status, $json] = $this->api($this->teacher, 'instructor', 'GET', '/proposals');
        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertSame([$full, $short], array_column($json['proposals'], 'id'));
        $this->assertTrue($json['proposals'][0]['meetsMinimum']);
        $this->assertFalse($json['proposals'][1]['meetsMinimum']);
        $this->assertCount(2, $json['proposals'][0]['member_names']);
        $this->assertSame(2, $json['minSize']);
        $this->assertSame([['id' => $short, 'name' => 'Short']], $json['summary']['belowMinimum']);
        $this->assertSame([], $json['summary']['ungrouped']);

        // The dean and the chairperson read it too.
        $this->assertSame(200, $this->api($this->dean(), 'dean', 'GET', '/proposals')[0]);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'GET', '/proposals')[0]);
    }

    public function testApproveAndDeclineAProposal(): void
    {
        [$full, $short] = $this->proposals();

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/groups/{$short}/approve");
        $this->assertSame(422, $status);
        $this->assertSame('Needs at least 2 members', $json['message']);

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$full}/approve")[0]);
        $this->assertSame('active', db_connect()->table('student_groups')->where('id', $full)->get()->getRow()->status);

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/groups/{$short}/decline", ['reason' => ' ']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('reason', $json['errors']);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/groups/{$short}/decline", ['reason' => 'Join a team instead'])[0]);
        $this->assertSame('declined', db_connect()->table('student_groups')->where('id', $short)->get()->getRow()->status);
        $this->assertSame(422, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$short}/approve")[0], 'no longer pending');
    }

    public function testSetLeaderAndTheDetailCarriesLeaderInfo(): void
    {
        $detail = $this->attach();
        $g1     = (int) $detail['groups'][0]['id'];

        $this->assertSame(['id' => null, 'final' => false], $detail['groups'][0]['leader']);
        $this->assertSame('active', $detail['groups'][0]['status']);

        $this->assertSame(422, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$g1}/leader", ['student_user_id' => (int) $this->second->id])[0], 'not a member');
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$g1}/leader", ['student_user_id' => (int) $this->student->id])[0]);
        $this->assertSame(['id' => (int) $this->student->id, 'final' => true], $this->api($this->teacher, 'instructor', 'GET')[1]['groups'][0]['leader']);

        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/groups/{$g1}/leader", ['student_user_id' => (int) $this->student->id])[0]);

        // The instructor renames through the shared rule: allowed even after the leader is final and the group froze.
        $this->freeze($g1);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'PUT', "/groups/{$g1}", ['name' => 'Renamed'])[0]);
        $this->assertSame('Renamed', db_connect()->table('student_groups')->where('id', $g1)->get()->getRow()->name);
    }

    public function testProposalAndLeaderWritesAreRefusedToOthersForeignIdsAndAFinalizedOffering(): void
    {
        [$full, $short] = $this->proposals();
        $dean           = $this->dean();

        foreach (["/groups/{$full}/approve", "/groups/{$full}/decline", "/groups/{$full}/leader"] as $path) {
            $this->assertContains($this->api($dean, 'dean', 'POST', $path, ['reason' => 'x', 'student_user_id' => (int) $this->student->id])[0], [403, 404, 405], "dean {$path}");
            $this->assertContains($this->api($this->student, 'instructor', 'POST', $path, ['reason' => 'x'])[0], [302, 403, 404], "student {$path}");
            $this->assertSame(404, $this->api($this->stranger, 'instructor', 'POST', $path, ['reason' => 'x'])[0], "stranger {$path}");
        }

        $this->assertContains($this->api($this->student, 'student', 'GET', '/proposals')[0], [403, 404, 405]);

        $theirs = CourseFactory::assessment($this->other, $this->stranger, CourseFactory::module($this->other), null, 'Assignment');
        $config = (new ActivityGroupService())->attachAssessment($theirs, null, [], (int) $this->stranger->id);
        $group  = (new ActivityGroupService())->addGroup($config, 'Theirs', (int) $this->stranger->id);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$group}/approve")[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$group}/leader", ['student_user_id' => 1])[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'GET', '/proposals', [], $theirs)[0]);

        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => $this->teacher->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        foreach ([["/groups/{$full}/approve", []], ["/groups/{$short}/decline", ['reason' => 'x']], ["/groups/{$full}/leader", ['student_user_id' => (int) $this->student->id]]] as [$path, $body]) {
            $this->assertContains($this->api($this->teacher, 'instructor', 'POST', $path, $body)[0], [403, 409, 422, 423], $path);
        }

        $this->assertSame(2, db_connect()->table('student_groups')->whereIn('id', [$full, $short])->where('status', 'proposed')->countAllResults());
        $this->assertNull(db_connect()->table('student_groups')->where('id', $full)->get()->getRow()->leader_user_id);
    }

    public function testTheFilterAsksTheContentPermissionForActivityGroupWrites(): void
    {
        $this->assertSame('courses.manage', CoursePermissionFilter::required("assessments/{$this->assignment}/groups/attach"));
        $this->assertSame('courses.manage', CoursePermissionFilter::required("assessments/{$this->assignment}/groups/members/move"));
    }
}
