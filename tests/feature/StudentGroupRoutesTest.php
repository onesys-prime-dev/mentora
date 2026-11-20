<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupService;
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
            $this->assertSame(['id', 'name'], array_keys($member));
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
