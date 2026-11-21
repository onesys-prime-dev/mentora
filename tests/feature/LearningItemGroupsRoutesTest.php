<?php

declare(strict_types=1);

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
 * Groups on an ungraded learning item (D12, D13, D26, screens 2A and 3D): instructor and chairperson change them,
 * the dean only reads, a student sees and changes only their own group.
 *
 * @internal
 */
final class LearningItemGroupsRoutesTest extends RouteTestCase
{
    private int $offering;

    private int $other;

    private int $unit;

    private int $item;

    private int $set;

    private User $teacher;

    private User $stranger;

    private User $student;

    private User $second;

    private User $third;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->offering = CourseFactory::offering('A');
        $this->other    = CourseFactory::offering('B');
        $this->teacher  = CourseFactory::user('instructor');
        $this->stranger = CourseFactory::user('instructor');
        $this->student  = CourseFactory::user('student');
        $this->second   = CourseFactory::user('student');
        $this->third    = CourseFactory::user('student');
        $this->chair    = CourseFactory::user('chairperson');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::assignInstructor($this->other, $this->stranger);

        foreach ([$this->student, $this->second, $this->third] as $s) {
            CourseFactory::enroll($this->offering, $s);
        }

        db_connect()->table('chairperson_assignments')->insert([
            'department_id'       => (int) db_connect()->query('SELECT p.department_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id WHERE o.id = ?', [$this->offering])->getRow()->department_id,
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->unit       = CourseFactory::unit(CourseFactory::module($this->offering));
        [$this->item]     = CourseFactory::items($this->unit, [['title' => 'Lab 3: Sorting', 'type' => 'reading']]);

        $sets      = new GroupSetService();
        $this->set = $sets->create($this->offering, ['name' => 'Pairs', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 3], (int) $this->teacher->id);
        $g         = $sets->addGroup($this->set, 'Pair 7', (int) $this->teacher->id);
        (new GroupMembershipService())->addToSet($g, (int) $this->student->id, (int) $this->teacher->id);
        (new GroupMembershipService())->addToSet($g, (int) $this->second->id, (int) $this->teacher->id);
    }

    /** @return array{int, array<string, mixed>} status and decoded JSON body */
    private function api(User $user, string $area, string $verb, string $path = '', array $body = [], ?int $item = null, string $tail = 'groups'): array
    {
        $this->loggedInAs($user);

        try {
            $res = $this->withBody(json_encode($body))
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
                ->call($verb, "{$area}/api/courses/{$this->offering}/learning-items/" . ($item ?? $this->item) . "/{$tail}{$path}");
        } catch (PageNotFoundException) {
            return [404, []];
        }

        return [$res->response()->getStatusCode(), json_decode((string) $res->response()->getBody(), true) ?? []];
    }

    private function mine(User $student, string $verb = 'GET', string $path = '', array $body = []): array
    {
        return $this->api($student, 'student', $verb, $path, $body, null, 'my-group');
    }

    private function attach(string $mode = 'any', array $settings = [], string $area = 'instructor', ?User $as = null): array
    {
        [$status, $json] = $this->api($as ?? $this->teacher, $area, 'POST', '/attach', ['set_id' => $this->set, 'completion_mode' => $mode, 'settings' => $settings]);
        $this->assertSame(200, $status, (string) json_encode($json));

        return $this->api($as ?? $this->teacher, $area, 'GET')[1];
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

    private function openSignup(): void
    {
        db_connect()->table('activity_group_configs')->where('learning_item_id', $this->item)->update(['formation_mode' => 'self_signup', 'signup_opens_at' => null, 'signup_closes_at' => date('Y-m-d H:i:s', strtotime('+3 days'))]);
    }

    private function nameOf(User $user): string
    {
        return (string) db_connect()->query("SELECT COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username) AS n FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?", [$user->id])->getRow()->n;
    }

    private function completed(User $user): bool
    {
        return db_connect()->table('learning_completions')->where('learning_item_id', $this->item)->where('student_user_id', $user->id)->where('status', 'completed')->countAllResults() > 0;
    }

    public function testShowBeforeAttachIsEmptyWithTheSets(): void
    {
        [$status, $json] = $this->api($this->teacher, 'instructor', 'GET');

        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertNull($json['config']);
        $this->assertSame('Pairs', $json['sets'][0]['name']);
        $this->assertTrue($json['flags']['canWrite']);
        $this->assertFalse($json['flags']['frozenGroups']);
    }

    public function testInstructorAttachesChangesSettingsPlacesAndDetaches(): void
    {
        $detail = $this->attach('any', ['groups_visible' => true]);

        $this->assertSame('any', $detail['config']['completion_mode']);
        $this->assertSame(1, (int) $detail['config']['groups_visible']);
        $this->assertSame('Pair 7', $detail['groups'][0]['name']);
        $this->assertCount(2, $detail['groups'][0]['members']);
        $this->assertSame((int) $this->third->id, (int) $detail['ungrouped'][0]['student_user_id']);
        $g1 = (int) $detail['groups'][0]['id'];

        [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['completion_mode' => 'each', 'max_size' => 4, 'formation_mode' => 'self_signup', 'signup_closes_at' => '2030-01-02T08:00']]);
        $this->assertSame(200, $status, (string) json_encode($json));

        // Assignment-only settings are not settings of an item.
        foreach (['submission_style' => 'leader_only', 'grading_mode' => 'individual', 'ungrouped_policy' => 'solo'] as $key => $value) {
            [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => [$key => $value]]);
            $this->assertSame(422, $status, $key);
            $this->assertArrayHasKey($key, $json['errors']);
        }

        [$status, $g] = $this->api($this->teacher, 'instructor', 'POST', '/groups', ['name' => 'Pair 8']);
        $this->assertSame(200, $status, (string) json_encode($g));
        $g2  = (int) $g['id'];
        $tid = (int) $this->third->id;
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'PUT', "/groups/{$g2}", ['name' => 'Pair 9'])[0]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/groups/{$g2}/members", ['student_user_id' => $tid])[0]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', '/members/move', ['student_user_id' => $tid, 'to_group_id' => $g1])[0]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'DELETE', "/members/{$tid}")[0]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'DELETE', "/groups/{$g2}")[0]);

        [, $detail] = $this->api($this->teacher, 'instructor', 'GET');
        $this->assertSame('each', $detail['config']['completion_mode']);
        $this->assertSame('self_signup', $detail['config']['formation_mode']);
        $this->assertSame(4, (int) $detail['config']['max_size']);
        $this->assertCount(1, $detail['groups']);

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'DELETE')[0]);
        $this->assertNull($this->api($this->teacher, 'instructor', 'GET')[1]['config']);
        $this->assertNotNull(db_connect()->table('audit_logs')->where('action', 'groups.activity_detached')->get()->getRowArray());
    }

    public function testAttachNeedsAValidCompletionMode(): void
    {
        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', '/attach', ['set_id' => null, 'completion_mode' => 'sometimes']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('completion_mode', $json['errors']);

        [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['completion_mode' => 'each']]);
        $this->assertSame(404, $status, 'no groups yet');
    }

    public function testRecopyAndCopyFromAnotherItem(): void
    {
        $this->attach();
        [$other] = CourseFactory::items($this->unit, [['title' => 'Video', 'type' => 'video']]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', '/attach', ['completion_mode' => 'each'], $other)[0]);

        (new GroupSetService())->addGroup($this->set, 'Pair 8', (int) $this->teacher->id);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', '/recopy')[0]);
        $this->assertCount(2, $this->api($this->teacher, 'instructor', 'GET')[1]['groups']);

        [, $detail] = $this->api($this->teacher, 'instructor', 'GET', '', [], $other);
        $this->assertSame([$this->item], array_map('intval', array_column($detail['activities'], 'learning_item_id')));

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', '/copy-from', ['from_learning_item_id' => $this->item], $other);
        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertSame(['Pair 7', 'Pair 8'], array_column($this->api($this->teacher, 'instructor', 'GET', '', [], $other)[1]['groups'], 'name'));
    }

    public function testAssignmentAndQuizItemsUseTheirOwnSettings(): void
    {
        [$assignment, $quiz] = CourseFactory::items($this->unit, [['type' => 'assignment'], ['type' => 'quiz']]);

        foreach ([$assignment, $quiz] as $id) {
            [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', '/attach', ['completion_mode' => 'any'], $id);
            $this->assertSame(422, $status);
            $this->assertStringContainsString("Use the assignment's own group settings", (string) $json['message']);
            $this->assertNull((new ActivityGroupService())->findByLearningItem($id));
        }
    }

    public function testStudentProposedGroupsAreNotOfferedOnItems(): void
    {
        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', '/attach', ['completion_mode' => 'any', 'settings' => ['formation_mode' => 'proposed']]);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('formation_mode', $json['errors']);
        $this->assertNull((new ActivityGroupService())->findByLearningItem($this->item));

        $this->attach();
        [$status, $json] = $this->api($this->teacher, 'instructor', 'PUT', '/settings', ['changes' => ['formation_mode' => 'proposed']]);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('formation_mode', $json['errors']);
        $this->assertSame('manual', (new ActivityGroupService())->findByLearningItem($this->item)['formation_mode']);
    }

    public function testAnItemOfAnotherCourseAndAForeignGroupAre404(): void
    {
        $this->attach();
        [$theirs] = CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->other)), [['type' => 'reading']]);
        $config   = (new ActivityGroupService())->attachLearningItem($theirs, null, 'any', (int) $this->stranger->id);
        $group    = (new ActivityGroupService())->addGroup($config, 'Theirs', (int) $this->stranger->id);

        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'GET', '', [], $theirs)[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', '/attach', ['completion_mode' => 'any'], $theirs)[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'PUT', "/groups/{$group}", ['name' => 'x'])[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', '/members/move', ['student_user_id' => (int) $this->third->id, 'to_group_id' => $group])[0]);
        $this->assertSame(404, $this->api($this->stranger, 'instructor', 'GET')[0], 'instructor of another course');
        $this->assertSame(404, $this->mine($this->student, 'POST', '/join', ['group_id' => $group])[0]);
        $this->assertSame(404, $this->api($this->student, 'student', 'GET', '', [], $theirs, 'my-group')[0]);
        $this->assertSame('Theirs', db_connect()->table('student_groups')->where('id', $group)->get()->getRow()->name);
    }

    public function testStudentsAndTheDeanCannotChangeAnything(): void
    {
        $this->attach();
        $dean = $this->dean();

        [$status, $json] = $this->api($dean, 'dean', 'GET');
        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertFalse($json['flags']['canWrite']);
        $this->assertSame('Pair 7', $json['groups'][0]['name']);

        foreach ([['GET', ''], ['POST', '/attach'], ['PUT', '/settings'], ['DELETE', ''], ['POST', '/groups'], ['POST', '/members/move']] as [$verb, $path]) {
            $this->assertContains($this->api($this->student, 'student', $verb, $path)[0], [403, 404, 405], "student {$verb} {$path}");
            $this->assertContains($this->api($this->student, 'instructor', $verb, $path)[0], [302, 403, 404], "student in instructor area {$verb} {$path}");

            if ($verb !== 'GET') {
                $this->assertContains($this->api($dean, 'dean', $verb, $path, ['name' => 'x'])[0], [403, 404, 405], "dean {$verb} {$path}");
            }
        }

        $this->assertNotNull((new ActivityGroupService())->findByLearningItem($this->item));
        $this->assertSame(1, db_connect()->table('student_groups')->where('config_id', (new ActivityGroupService())->findByLearningItem($this->item)['id'])->countAllResults());
    }

    public function testTheChairpersonMirrorsTheInstructor(): void
    {
        $detail = $this->attach('each', [], 'chairperson', $this->chair);
        $g1     = (int) $detail['groups'][0]['id'];

        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'PUT', '/settings', ['changes' => ['completion_mode' => 'any']])[0]);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/groups/{$g1}/members", ['student_user_id' => (int) $this->third->id])[0]);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'DELETE')[0]);
    }

    public function testAFinalizedOfferingRefusesEveryWrite(): void
    {
        $detail = $this->attach();
        $g1     = (int) $detail['groups'][0]['id'];
        $this->openSignup();
        $this->finalize();

        [, $json] = $this->api($this->teacher, 'instructor', 'GET');
        $this->assertFalse($json['flags']['canWrite']);

        foreach ([['PUT', '/settings', ['changes' => ['completion_mode' => 'each']]], ['POST', '/groups', ['name' => 'X']], ['PUT', "/groups/{$g1}", ['name' => 'X']], ['DELETE', '', []]] as [$verb, $path, $body]) {
            $this->assertContains($this->api($this->teacher, 'instructor', $verb, $path, $body)[0], [403, 409, 422, 423], "{$verb} {$path}");
        }

        $this->assertContains($this->mine($this->third, 'POST', '/join', ['group_id' => $g1])[0], [403, 409, 422, 423]);
        $this->assertContains($this->mine($this->student, 'POST', '/leave')[0], [403, 409, 422, 423]);
        $this->assertSame('any', (new ActivityGroupService())->findByLearningItem($this->item)['completion_mode']);
        $this->assertSame(2, db_connect()->table('student_group_members')->where('group_id', $g1)->countAllResults());
    }

    public function testAStudentSeesTheirGroupJoinsLeavesAndSwitches(): void
    {
        $detail = $this->attach('each');
        $g1     = (int) $detail['groups'][0]['id'];

        // Not a group item: says so and nothing else.
        [$other] = CourseFactory::items($this->unit, [['type' => 'reading']]);
        $this->assertSame(['success' => true, 'isGroupActivity' => false], $this->api($this->third, 'student', 'GET', '', [], $other, 'my-group')[1]);

        [$status, $json] = $this->mine($this->third);
        $this->assertSame(200, $status, (string) json_encode($json));
        $this->assertTrue($json['isGroupActivity']);
        $this->assertNull($json['myGroup']);
        $this->assertFalse($json['blocked'], 'an ungrouped student can still complete the item');
        $this->assertSame([], $json['candidates'], 'manual mode: nothing to join');
        $this->assertSame(422, $this->mine($this->third, 'POST', '/join', ['group_id' => $g1])[0]);

        $this->openSignup();
        $g2 = (new ActivityGroupService())->addGroup((int) (new ActivityGroupService())->findByLearningItem($this->item)['id'], 'Pair 8', (int) $this->teacher->id);

        [, $json] = $this->mine($this->third);
        $this->assertTrue($json['signup']['isOpen']);
        $this->assertSame(['Pair 7', 'Pair 8'], array_column($json['candidates'], 'name'));

        [$status, $json] = $this->mine($this->third, 'POST', '/join', ['group_id' => $g2]);
        $this->assertSame(200, $status, (string) json_encode($json));
        [, $json] = $this->mine($this->third);
        $this->assertSame('Pair 8', $json['myGroup']['name']);
        $this->assertTrue($json['myGroup']['canLeave']);

        $this->assertSame(200, $this->mine($this->third, 'POST', '/switch', ['group_id' => $g1])[0]);
        $this->assertSame('Pair 7', $this->mine($this->third)[1]['myGroup']['name']);
        $this->assertSame(200, $this->mine($this->third, 'POST', '/leave')[0]);
        $this->assertNull($this->mine($this->third)[1]['myGroup']);
    }

    public function testEachModeShowsEveryPartnersOwnStatus(): void
    {
        $this->attach('each');
        $this->loggedInAs($this->student);
        $res = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->call('POST', "student/api/courses/{$this->offering}/modules/items/{$this->item}/complete");
        $this->assertTrue(json_decode((string) $res->response()->getBody(), true)['success']);

        $this->assertTrue($this->completed($this->student));
        $this->assertFalse($this->completed($this->second), 'each member completes their own');

        [, $json] = $this->mine($this->second);
        $this->assertSame('each', $json['completionMode']);
        $members = array_column($json['myGroup']['members'], 'completed', 'id');
        $this->assertSame([(int) $this->student->id => true, (int) $this->second->id => false], $members);
    }

    public function testAnyModeCompletesEveryMemberThroughTheStudentCompletionEndpoint(): void
    {
        $this->attach('any');
        [, $before] = $this->mine($this->second);
        $this->assertSame('any', $before['completionMode']);
        $this->assertFalse($before['myGroup']['completed']);
        $this->assertNull($before['myGroup']['completedBy']);

        $this->loggedInAs($this->student);
        $res = $this->withBody(json_encode([]))->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->call('POST', "student/api/courses/{$this->offering}/modules/items/{$this->item}/complete");
        $this->assertSame(200, $res->response()->getStatusCode());
        $this->assertTrue(json_decode((string) $res->response()->getBody(), true)['success']);

        $this->assertTrue($this->completed($this->student));
        $this->assertTrue($this->completed($this->second));
        $this->assertFalse($this->completed($this->third), 'not in the group');

        [, $json] = $this->mine($this->second);
        $this->assertTrue($json['myGroup']['completed']);
        $this->assertSame($this->nameOf($this->student), $json['myGroup']['completedBy']);
        $this->assertArrayNotHasKey('completed', $json['myGroup']['members'][0], 'under "any" the group has one status');
    }

    public function testAStudentGetsNothingPrivateAboutOtherGroups(): void
    {
        $this->attach('each');
        $configId = (int) (new ActivityGroupService())->findByLearningItem($this->item)['id'];
        $other    = (new ActivityGroupService())->addGroup($configId, 'Pair 8', (int) $this->teacher->id);
        (new \App\Services\Groups\ActivityGroupMembershipService())->addMember($other, (int) $this->third->id, (int) $this->teacher->id);
        db_connect()->table('learning_completions')->insert(['learning_item_id' => $this->item, 'student_user_id' => $this->third->id, 'status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        [, $json] = $this->mine($this->student);
        $this->assertArrayNotHasKey('otherGroups', $json);
        $this->assertStringNotContainsString($this->nameOf($this->third), (string) json_encode($json));

        db_connect()->table('activity_group_configs')->where('id', $configId)->update(['groups_visible' => 1]);
        [, $json] = $this->mine($this->student);
        $this->assertSame('Pair 8', $json['otherGroups'][0]['name']);
        $this->assertSame([['id' => (int) $this->third->id, 'name' => $this->nameOf($this->third)]], $json['otherGroups'][0]['members'], 'names only, never their completion');
    }

    public function testTheEditorShowsTheSectionForUngradedItemsOnly(): void
    {
        [$quiz] = CourseFactory::items($this->unit, [['type' => 'quiz']]);
        $this->loggedInAs($this->teacher);

        $html = function (int $id): string {
            $res = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->call('GET', "instructor/api/courses/{$this->offering}/modules/items/edit/{$id}");
            $this->assertSame(200, $res->response()->getStatusCode());

            return (string) (json_decode((string) $res->response()->getBody(), true)['html'] ?? $res->response()->getBody());
        };

        $this->assertStringContainsString('data-group-activity data-learning-item-id="' . $this->item . '"', $html($this->item));
        $this->assertStringContainsString('completion_mode', $html($this->item));
        $this->assertStringNotContainsString('data-group-activity', $html($quiz));
    }

    public function testTheStudentItemPageCarriesTheGroupCardOnlyForAGroupItem(): void
    {
        $page = function (): string {
            $this->loggedInAs($this->student);
            $res  = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->call('GET', "student/api/courses/{$this->offering}/modules/items/{$this->item}");
            $body = json_decode((string) $res->response()->getBody(), true);

            return implode(' ', array_filter((array) $body, 'is_string'));
        };

        $this->assertStringNotContainsString('data-student-group', $page());

        $this->attach();
        $html = $page();
        $this->assertStringContainsString('data-student-group data-learning-item-id="' . $this->item . '"', $html);
    }
}
