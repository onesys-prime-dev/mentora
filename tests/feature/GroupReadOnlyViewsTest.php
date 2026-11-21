<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The read-only group views (Task 9.2, screens 4E and 4F): the dean reads every group and grade of a group
 * activity on the assessment page and can change nothing; a parent sees only their own child's group and the
 * child's own grade, never a teammate's score, adjustment, reason or note, and never the child's private note.
 *
 * @internal
 */
final class GroupReadOnlyViewsTest extends RouteTestCase
{
    private int $offering;

    private int $assignment;

    private int $plain;

    private int $config;

    private int $team1;

    private int $item;

    private User $teacher;

    private User $parent;

    /** @var array<string, User> Ann (the child), Ben, Cai, Dee in Team 1; Eve in Team 2 */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $inst           = PeopleFactory::institution('GRO');
        $this->offering = CourseFactory::offering('A');
        $module         = CourseFactory::module($this->offering);
        $this->teacher  = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $inst);
        $this->parent   = PeopleFactory::person('parent', 'Pam', 'Parent', null, $inst);
        CourseFactory::assignInstructor($this->offering, $this->teacher);

        foreach (['Ann' => 'Lee', 'Ben' => 'Bolt', 'Cai' => 'Chen', 'Dee' => 'Park', 'Eve' => 'Evans'] as $first => $last) {
            $this->s[$first] = PeopleFactory::person('student', $first, $last, null, $inst);
            CourseFactory::enroll($this->offering, $this->s[$first]);
        }

        db_connect()->table('parent_student_links')->insert(['institution_id' => $inst, 'parent_user_id' => $this->parent->id, 'student_user_id' => $this->s['Ann']->id, 'created_at' => date('Y-m-d H:i:s')]);

        // A group assignment worth 20: a group criterion and an individual one, 10 each.
        $this->assignment = CourseFactory::assessment($this->offering, $this->teacher, $module, null, 'Assignment');
        db_connect()->table('assessments')->where('id', $this->assignment)->update(['title' => 'Final project', 'total_points' => 20]);
        AssessmentFactory::open($this->assignment);
        $this->config = (new ActivityGroupService())->attachAssessment($this->assignment, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 6, 'grading_mode' => 'collective_adjust'], (int) $this->teacher->id);
        [$groupCriterion, $ownCriterion] = AssessmentFactory::rubric($this->assignment, $this->teacher, [10, 10])['criteria'];
        db_connect()->table('assessment_rubric_criteria')->where('id', $groupCriterion)->update(['scope' => 'group']);
        db_connect()->table('assessment_rubric_criteria')->where('id', $ownCriterion)->update(['scope' => 'individual']);

        $this->team1 = $this->group('Team 1', ['Ann', 'Ben', 'Cai', 'Dee']);
        $this->group('Team 2', ['Eve']);
        AssessmentFactory::attempt($this->assignment, $this->s['Ben'], 'Submitted', 0.0, ['group_id' => $this->team1, 'submitted_by_user_id' => $this->s['Ben']->id, 'submission_text' => 'Our final report']);

        // Group part 7.5 for all; Ann 6 + 1 (ANNREASON, ANNNOTE) = 14.5; Ben 9 = 16.5; Cai 3 - 2 (CAIREASON, CAINOTE) = 8.5; Dee 4 = 11.5.
        $member = static fn (float $own, float $adj = 0, string $reason = '', string $note = ''): array => ['individual' => [$ownCriterion => $own], 'adjustment' => $adj, 'reason' => $reason, 'note' => $note];
        $this->loggedInAs($this->teacher);
        $res = $this->withBody((string) json_encode(['group_scores' => [$groupCriterion => 7.5], 'feedback' => 'Clear structure.', 'members' => [
            $this->s['Ann']->id => $member(6, 1, 'ANNREASON led the demo', 'ANNNOTE see me'),
            $this->s['Ben']->id => $member(9),
            $this->s['Cai']->id => $member(3, -2, 'CAIREASON missed meetings', 'CAINOTE talk to me'),
            $this->s['Dee']->id => $member(4),
        ]]))->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->call('POST', "instructor/api/courses/{$this->offering}/assessments/{$this->assignment}/group-grading/{$this->team1}");
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        Services::resetSingle('courseContext');

        // A plain assignment the child handed in and got 7.25 for: unchanged, no group line.
        $this->plain = CourseFactory::assessment($this->offering, $this->teacher, $module, null, 'Assignment');
        db_connect()->table('assessments')->where('id', $this->plain)->update(['title' => 'Solo essay', 'total_points' => 10]);
        AssessmentFactory::open($this->plain);
        AssessmentFactory::attempt($this->plain, $this->s['Ann'], 'Graded', 7.25);

        [$this->item] = CourseFactory::items(CourseFactory::unit($module), [['title' => 'Reading', 'type' => 'reading']]);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    /** @param list<string> $members first names */
    private function group(string $name, array $members): int
    {
        $db = db_connect();
        $db->table('student_groups')->insert(['set_id' => null, 'config_id' => $this->config, 'name' => $name, 'status' => 'active']);
        $group = (int) $db->insertID();

        foreach ($members as $m) {
            $db->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $this->s[$m]->id, 'joined_at' => date('Y-m-d H:i:s')]);
        }

        return $group;
    }

    private function dean(): User
    {
        $row = db_connect()->query('SELECT t.institution_id, d.college_id FROM course_offerings o JOIN academic_terms t ON t.id = o.term_id JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN departments d ON d.id = p.department_id WHERE o.id = ?', [$this->offering])->getRow();

        return DeanFactory::dean((int) $row->institution_id, (int) $row->college_id);
    }

    /** @return array{int, string} status and raw body */
    private function call2(User $user, string $verb, string $uri, array $body = []): array
    {
        auth()->logout();
        Services::resetSingle('auth');
        Services::resetSingle('courseContext');
        $this->loggedInAs($user);

        try {
            $res = $this->withBody((string) json_encode($body))
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
                ->call($verb, $uri);
        } catch (PageNotFoundException) {
            return [404, ''];
        }

        return [$res->response()->getStatusCode(), (string) $res->response()->getBody()];
    }

    private function html(string $body): string
    {
        return html_entity_decode((string) (json_decode($body, true)['html'] ?? ''), ENT_QUOTES);
    }

    /*
    | Dean (4E)
    */

    public function testTheDeansAssessmentPageCarriesTheReadOnlyGroupView(): void
    {
        $dean = $this->dean();

        [$status, $body] = $this->call2($dean, 'GET', "dean/api/courses/{$this->offering}/assessments/{$this->assignment}/view");
        $this->assertSame(200, $status, $body);
        $html = $this->html($body);

        $this->assertStringContainsString('data-group-grading', $html);
        $this->assertStringContainsString('data-assessment-id="' . $this->assignment . '"', $html);
        $this->assertStringContainsString('data-lock', $html, 'the lock-note slot');

        // The read-only frame has no grading dialogs or forms to submit.
        foreach (['gg-late-dialog', 'gg-drop-dialog', 'gg-reopen-confirm', '<form', '<input', '<textarea'] as $control) {
            $this->assertStringNotContainsString($control, $this->section($html), $control);
        }

        // A plain assignment has none, and neither does the instructor's or the student's view page.
        $this->assertStringNotContainsString('data-group-grading', $this->html($this->call2($dean, 'GET', "dean/api/courses/{$this->offering}/assessments/{$this->plain}/view")[1]));
        $this->assertStringNotContainsString('data-group-grading', $this->html($this->call2($this->teacher, 'GET', "instructor/api/courses/{$this->offering}/assessments/{$this->assignment}/view")[1]));
        $this->assertStringNotContainsString('data-group-grading', $this->html($this->call2($this->s['Ann'], 'GET', "student/api/courses/{$this->offering}/assessments/{$this->assignment}/view")[1]));
    }

    /** The group section of the page only (the rest of the page belongs to other tests). */
    private function section(string $html): string
    {
        $start = strpos($html, 'data-dean-groups');
        $this->assertNotFalse($start, 'the dean groups section');

        $end = strpos($html, '</section>', (int) strpos($html, 'data-gg-live', (int) $start));

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    public function testTheDeanReadsGroupsStatusesAndEveryMembersFinalAdjustmentReasonAndNote(): void
    {
        $dean = $this->dean();
        $base = "dean/api/courses/{$this->offering}/assessments/{$this->assignment}/group-grading";

        [$status, $body] = $this->call2($dean, 'GET', $base);
        $this->assertSame(200, $status, $body);
        $list = json_decode($body, true);
        $this->assertFalse($list['flags']['canWrite']);
        $this->assertStringStartsWith('View only.', (string) $list['lockReason']);
        $this->assertSame(['Team 1' => 'graded', 'Team 2' => 'not_submitted'], array_column($list['groups'], 'status', 'name'));

        [$status, $body] = $this->call2($dean, 'GET', "{$base}/{$this->team1}");
        $this->assertSame(200, $status, $body);
        $detail  = json_decode($body, true);
        $members = array_column($detail['members'], null, 'student_user_id');

        $this->assertFalse($detail['flags']['canWrite']);
        $this->assertStringContainsString('Our final report', $detail['submission']['workHtml']);
        $this->assertSame('Clear structure.', end($detail['feedback'])['message']);
        $this->assertSame([14.5, 16.5, 8.5, 11.5], array_map(fn (string $n): float => (float) $members[$this->s[$n]->id]['final'], ['Ann', 'Ben', 'Cai', 'Dee']));
        $this->assertSame(-2.0, (float) $members[$this->s['Cai']->id]['adjustment']);
        $this->assertSame('CAIREASON missed meetings', $members[$this->s['Cai']->id]['reason']);
        $this->assertSame('CAINOTE talk to me', $members[$this->s['Cai']->id]['note'], 'the dean is staff and sees private notes (FR-10)');
    }

    public function testEveryGroupWriteRouteIsRefusedForTheDeanAndNothingChanges(): void
    {
        $dean = $this->dean();
        $o    = $this->offering;
        $a    = $this->assignment;
        $g    = $this->team1;
        $i    = $this->item;
        $sid  = $this->s['Ann']->id;
        $set  = (new \App\Services\Groups\GroupSetService())->create($o, ['name' => 'Set', 'formation_mode' => 'manual'], (int) $this->teacher->id);

        $before = $this->snapshot();
        $writes = [
            // group sets
            ['POST', "{$o}/group-sets"], ['PUT', "{$o}/group-sets/{$set}"], ['POST', "{$o}/group-sets/{$set}/archive"], ['POST', "{$o}/group-sets/{$set}/groups"],
            ['DELETE', "{$o}/group-sets/{$set}/members/{$sid}"], ['POST', "{$o}/group-sets/{$set}/generate"],
            // activity groups, unlock, late members, proposals, leader, extension
            ['POST', "{$o}/assessments/{$a}/groups/attach"], ['PUT', "{$o}/assessments/{$a}/groups/settings"], ['DELETE', "{$o}/assessments/{$a}/groups"],
            ['POST', "{$o}/assessments/{$a}/groups/groups"], ['PUT', "{$o}/assessments/{$a}/groups/groups/{$g}"], ['DELETE', "{$o}/assessments/{$a}/groups/groups/{$g}"],
            ['POST', "{$o}/assessments/{$a}/groups/members/move"], ['DELETE', "{$o}/assessments/{$a}/groups/members/{$sid}"],
            ['POST', "{$o}/assessments/{$a}/groups/groups/{$g}/unlock"], ['POST', "{$o}/assessments/{$a}/groups/groups/{$g}/late-members"],
            ['POST', "{$o}/assessments/{$a}/groups/groups/{$g}/approve"], ['POST', "{$o}/assessments/{$a}/groups/groups/{$g}/decline"],
            ['POST', "{$o}/assessments/{$a}/groups/groups/{$g}/leader"], ['POST', "{$o}/assessments/{$a}/groups/groups/{$g}/extension"],
            // grading
            ['POST', "{$o}/assessments/{$a}/group-grading/{$g}"], ['POST', "{$o}/assessments/{$a}/group-grading/{$g}/reopen"],
            ['POST', "{$o}/assessments/{$a}/group-grading/{$g}/members/{$sid}/late-decision"], ['POST', "{$o}/assessments/{$a}/group-grading/{$g}/members/{$sid}/drop-decision"],
            // learning-item groups
            ['POST', "{$o}/learning-items/{$i}/groups/attach"], ['PUT', "{$o}/learning-items/{$i}/groups/settings"], ['DELETE', "{$o}/learning-items/{$i}/groups"],
            ['POST', "{$o}/learning-items/{$i}/groups/members/move"],
            // the student's own routes, at the dean's address
            ['POST', "{$o}/assessments/{$a}/my-group/join"], ['POST', "{$o}/assessments/{$a}/my-group/leave"], ['POST', "{$o}/assessments/{$a}/my-group/vote"],
            ['POST', "{$o}/learning-items/{$i}/my-group/join"],
        ];
        $body = ['name' => 'Hacked', 'reason' => 'x', 'decision' => 'keep', 'student_id' => $sid, 'group_id' => $g, 'members' => []];

        foreach ($writes as [$verb, $path]) {
            $this->assertContains($this->call2($dean, $verb, "dean/api/courses/{$path}", $body)[0], [401, 403, 404, 405], "dean {$verb} {$path}");
        }

        // Nor through the instructor's, chairperson's or student's address.
        foreach (['instructor', 'chairperson', 'student'] as $area) {
            foreach ([['POST', "{$o}/assessments/{$a}/group-grading/{$g}"], ['POST', "{$o}/assessments/{$a}/groups/groups"], ['POST', "{$o}/assessments/{$a}/my-group/leave"]] as [$verb, $path]) {
                $this->assertContains($this->call2($dean, $verb, "{$area}/api/courses/{$path}", $body)[0], [302, 401, 403, 404, 405], "dean via {$area} {$verb} {$path}");
            }
        }

        $this->assertSame($before, $this->snapshot());
    }

    /** @return array<string, mixed> what a write would have changed */
    private function snapshot(): array
    {
        $db = db_connect();

        return [
            'sets'    => $db->table('group_sets')->select('id, name, status')->get()->getResultArray(),
            'groups'  => $db->table('student_groups')->select('id, name, status, leader_user_id, frozen_at')->get()->getResultArray(),
            'members' => $db->table('student_group_members')->select('group_id, student_user_id')->orderBy('id')->get()->getResultArray(),
            'scores'  => $db->table('group_member_scores')->select('student_user_id, final_score, adjustment, excused, pending')->orderBy('id')->get()->getResultArray(),
            'configs' => $db->table('activity_group_configs')->countAllResults(),
        ];
    }

    /*
    | Parent (4F)
    */

    private function parentTab(?User $parent = null, ?int $child = null): array
    {
        return $this->call2($parent ?? $this->parent, 'GET', '/parent/children/' . ($child ?? $this->s['Ann']->id) . "/api/courses/{$this->offering}/assessments");
    }

    public function testTheParentSeesTheChildsGroupAndOwnGradeOnly(): void
    {
        [$status, $body] = $this->parentTab();
        $this->assertSame(200, $status, $body);
        $html = $this->html($body);

        $this->assertStringContainsString('Group: Team 1 (Ann, Ben, Cai, Dee)', $html);
        $this->assertStringContainsString('Grade 14.5 / 20', $html);
        $this->assertStringContainsString('Group part 7.5', $html);
        $this->assertStringContainsString('individual part 6', $html);
        $this->assertStringContainsString('Adjustment +1', $html, 'what the child sees on their own result');
        $this->assertStringContainsString('ANNREASON led the demo', $html, 'the child sees their own reason, so the parent does');

        // Never the child's private note, nor anything of a teammate, nor another group, nor full names.
        foreach (['ANNNOTE', 'CAIREASON', 'CAINOTE', '16.5', '11.5', '8.5', 'Adjustment -2', 'Team 2', 'Eve', 'Bolt', 'Chen', 'Park', 'Our final report', 'Clear structure.'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, $secret);
        }

        // The raw response carries no more than the page.
        foreach (['ANNNOTE', 'CAIREASON', 'CAINOTE', '16.5', '11.5'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
    }

    public function testAPlainAssignmentIsShownAsBeforeWithNoGroupAndNoScore(): void
    {
        $html = $this->html($this->parentTab()[1]);

        $this->assertStringContainsString('Solo essay', $html);
        $this->assertSame(1, substr_count($html, 'data-parent-group'), 'only the group activity has a group line');
        $this->assertStringNotContainsString('7.25', $html, 'a plain assignment still shows no score');
    }

    public function testAnUngradedGroupShowsTheGroupButNoGrade(): void
    {
        db_connect()->table('group_member_scores')->where('student_user_id', $this->s['Ann']->id)->update(['pending' => 1]);

        $html = $this->html($this->parentTab()[1]);

        $this->assertStringContainsString('Group: Team 1', $html);
        $this->assertStringNotContainsString('Grade 14.5', $html);
        $this->assertStringNotContainsString('ANNREASON', $html);
    }

    public function testAParentNotLinkedToTheChildIsRefused(): void
    {
        $other = PeopleFactory::person('parent', 'Otto', 'Other', null, PeopleFactory::institution('GRX'));

        $this->assertSame(404, $this->parentTab($other)[0]);
        // Nor the parent for a teammate who is not their child.
        $this->assertSame(404, $this->parentTab(null, (int) $this->s['Cai']->id)[0]);
    }
}
