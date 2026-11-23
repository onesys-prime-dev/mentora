<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupMembershipService;
use App\Services\Groups\GroupSetService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\RouteTestCase;

/**
 * Phase 10 security sweep: every Group Activities route, read from the real route table (any route whose handler
 * is in App\Controllers\Courses\Groups), called by every wrong caller, with foreign ids, and on a finalized
 * offering. A route added later is swept automatically.
 *
 * @internal
 */
final class GroupRoutesSecuritySweepTest extends RouteTestCase
{
    /** What a refusal may look like: a redirect (to login or back), unauthorized, forbidden, not found. */
    private const REFUSED = [302, 401, 403, 404, 405];

    /** @var array<string, array<string, mixed>> 'own' and 'foreign' ids by kind */
    private array $ids = [];

    /** @var array<string, User> */
    private array $who = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->ids['own']     = $this->offeringWithGroups('A', true);
        $this->ids['foreign'] = $this->offeringWithGroups('B', false);
    }

    /**
     * One offering with a set, an assignment with its groups (one with a submission, one proposed with a pending
     * invitation) and a learning item with groups.
     *
     * @return array<string, mixed>
     */
    private function offeringWithGroups(string $tag, bool $own): array
    {
        $db       = db_connect();
        $offering = CourseFactory::offering($tag);
        $module   = CourseFactory::module($offering);
        $teacher  = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $teacher);

        $students = [];

        for ($i = 0; $i < 3; $i++) {
            $students[] = $s = CourseFactory::user('student');
            CourseFactory::enroll($offering, $s);
        }

        $actor = (int) $teacher->id;
        $sets  = new GroupSetService();
        $set   = $sets->create($offering, ['name' => "Teams {$tag}", 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 4], $actor);
        $sg1   = $sets->addGroup($set, 'Set team 1', $actor);
        $sets->addGroup($set, 'Set team 2', $actor);
        (new GroupMembershipService())->addToSet($sg1, (int) $students[0]->id, $actor);

        $assignment = CourseFactory::assessment($offering, $teacher, $module, null, 'Assignment');
        $db->table('assessments')->where('id', $assignment)->update(['total_points' => 20]);
        AssessmentFactory::open($assignment);
        // Copied from the set, so recopy has a source: Set team 1 (student 0) and Set team 2.
        $config = (new ActivityGroupService())->attachAssessment($assignment, $set, ['formation_mode' => 'self_signup', 'min_size' => 1, 'max_size' => 4, 'grading_mode' => 'collective_adjust'], $actor);
        $g1     = (int) $db->table('student_groups')->where('config_id', $config)->where('name', 'Set team 1')->get()->getRow()->id;
        $g2     = (int) $db->table('student_groups')->where('config_id', $config)->where('name', 'Set team 2')->get()->getRow()->id;

        // Another group assignment and item of the course, the copy-from sources.
        $copySource = CourseFactory::assessment($offering, $teacher, $module, null, 'Assignment');
        (new ActivityGroupService())->attachAssessment($copySource, null, [], $actor);
        [$copyItem] = CourseFactory::items(CourseFactory::unit($module, 'published', 2), [['title' => "Video {$tag}", 'type' => 'video']]);
        (new ActivityGroupService())->attachLearningItem($copyItem, null, 'each', $actor);

        AssessmentFactory::attempt($assignment, $students[0], 'Submitted', 0.0, ['group_id' => $g1, 'submitted_by_user_id' => $students[0]->id, 'submission_text' => 'Report']);

        // A proposal by student 1 that invites student 2.
        $db->table('student_groups')->insert(['set_id' => null, 'config_id' => $config, 'name' => 'Proposed', 'status' => 'proposed', 'proposed_by' => $students[1]->id]);
        $proposal = (int) $db->insertID();
        $db->table('student_group_members')->insert(['group_id' => $proposal, 'set_id' => null, 'config_id' => $config, 'student_user_id' => $students[1]->id, 'joined_at' => date('Y-m-d H:i:s')]);
        $db->table('student_group_invitations')->insert(['group_id' => $proposal, 'student_user_id' => $students[2]->id, 'status' => 'pending', 'invited_at' => date('Y-m-d H:i:s')]);

        [$item]     = CourseFactory::items(CourseFactory::unit($module), [['title' => "Reading {$tag}", 'type' => 'reading']]);
        $itemConfig = (new ActivityGroupService())->attachLearningItem($item, $set, 'any', $actor);
        $ig1        = (int) $db->table('student_groups')->where('config_id', $itemConfig)->where('name', 'Set team 1')->get()->getRow()->id;
        $ig2        = (int) $db->table('student_groups')->where('config_id', $itemConfig)->where('name', 'Set team 2')->get()->getRow()->id;

        if ($own) {
            $chair = CourseFactory::user('chairperson');
            $db->table('chairperson_assignments')->insert(['department_id' => $this->departmentOf($offering), 'chairperson_user_id' => $chair->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);
            $inst    = (int) $db->query('SELECT t.institution_id FROM course_offerings o JOIN academic_terms t ON t.id = o.term_id WHERE o.id = ?', [$offering])->getRow()->institution_id;
            $college = (int) $db->query('SELECT d.college_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN departments d ON d.id = p.department_id WHERE o.id = ?', [$offering])->getRow()->college_id;
            $parent  = CourseFactory::user('parent');
            $db->table('parent_student_links')->insert(['institution_id' => $inst, 'parent_user_id' => $parent->id, 'student_user_id' => $students[0]->id, 'created_at' => date('Y-m-d H:i:s')]);

            $this->who += ['teacher' => $teacher, 'chair' => $chair, 'dean' => DeanFactory::dean($inst, $college), 'parent' => $parent,
                's1' => $students[0], 's2' => $students[1], 's3' => $students[2]];
        } else {
            $otherChair = CourseFactory::user('chairperson');
            $db->table('chairperson_assignments')->insert(['department_id' => $this->departmentOf($offering), 'chairperson_user_id' => $otherChair->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);
            $this->who += ['stranger' => $teacher, 'otherChair' => $otherChair, 'outsider' => $students[0]];
        }

        return [
            'offering' => $offering, 'assessment' => $assignment, 'item' => $item, 'set' => $set, 'invitation' => $proposal, 'grading' => $g1,
            'group'    => ['set' => $sg1, 'assessment' => $g1, 'item' => $ig1],
            'group2'   => ['set' => $sg1, 'assessment' => $g2, 'item' => $ig2],
            'member'   => (int) $students[0]->id, 'free' => (int) $students[1]->id,
            'copySource' => $copySource, 'copyItem' => $copyItem,
        ];
    }

    private function departmentOf(int $offering): int
    {
        return (int) db_connect()->query('SELECT p.department_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id WHERE o.id = ?', [$offering])->getRow()->department_id;
    }

    /**
     * Every route served by a Courses\Groups controller, from a fresh copy of the real route table.
     *
     * @return list<array{verb: string, key: string, area: string, handler: string, filters: list<string>}>
     */
    private static function groupRoutes(): array
    {
        $collection = Services::routes(false)->loadRoutes();
        $out        = [];

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach ($collection->getRoutes($verb, false) as $key => $handler) {
                if (! is_string($handler) || ! str_contains($handler, 'Courses\Groups\\')) {
                    continue;
                }

                $out[] = [
                    'verb'    => strtoupper($verb),
                    'key'     => (string) $key,
                    'area'    => explode('/', (string) $key)[0],
                    'handler' => $handler,
                    'filters' => array_values((array) ($collection->getRoutesOptions((string) $key, $verb)['filter'] ?? [])),
                ];
            }
        }

        return $out;
    }

    private static function isWrite(array $route): bool
    {
        return $route['verb'] !== 'GET';
    }

    /** Which activity the route is about: a set, an assignment or a learning item. */
    private static function kind(string $key): string
    {
        return str_contains($key, 'group-sets') ? 'set' : (str_contains($key, 'learning-items') ? 'item' : 'assessment');
    }

    /**
     * The route with each placeholder filled by the previous segment's kind; $foreign names the one position to
     * fill from the other offering instead.
     *
     * @return array{string, list<string>} uri and the positions it has
     */
    private function uri(string $key, ?string $foreign = null): array
    {
        $kind     = self::kind($key);
        $segments = explode('/', $key);
        $names    = ['courses' => 'offering', 'assessments' => 'assessment', 'learning-items' => 'item', 'group-sets' => 'set', 'groups' => 'group', 'members' => 'member', 'group-grading' => 'grading', 'invitations' => 'invitation'];
        $found    = [];

        foreach ($segments as $i => $segment) {
            if (! in_array($segment, ['([0-9]+)', '(:num)'], true)) {
                continue;
            }

            $name    = $names[$segments[$i - 1]] ?? throw new RuntimeException("No id kind for {$key} at {$i}");
            $found[] = $name;
            $id      = $this->ids[$name === $foreign ? 'foreign' : 'own'][$name];
            $segments[$i] = (string) (is_array($id) ? $id[$kind] : $id);
        }

        return [implode('/', $segments), $found];
    }

    /** A body that a write would accept, with the activity's own ids. @return array<string, mixed> */
    private function body(string $key): array
    {
        $own  = $this->ids['own'];
        $kind = self::kind($key);

        return [
            'name' => 'Renamed', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 4,
            'student_user_id' => $own['free'], 'to_group_id' => $own['group'][$kind], 'group_id' => $own['group2'][$kind],
            'candidate_id' => $own['member'], 'invitee_ids' => [$own['free']], 'reason' => 'Agreed in class', 'until' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'set_id' => $own['set'], 'settings' => [], 'changes' => ['groups_visible' => 1], 'confirmed' => true, 'completion_mode' => 'any',
            'by' => 'size', 'n' => 2, 'groups' => [[$own['free']]], 'replace' => true,
            'decision' => 'keep', 'group_score' => 10, 'members' => [], 'feedback' => 'Fine',
            'from_assessment_id' => $own['copySource'], 'from_learning_item_id' => $own['copyItem'],
        ];
    }

    /** @return array{int, string} status and body; a thrown 404 is a 404 and anything else thrown is a 500 */
    private function send(?User $user, string $verb, string $uri, array $body = []): array
    {
        auth()->logout();

        if ($user !== null) {
            $this->loggedInAs($user);
        }

        try {
            $res = $this->withBody(json_encode($body))
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
                ->call($verb, $uri);
        } catch (PageNotFoundException) {
            return [404, ''];
        } catch (Throwable $e) {
            return [500, $e::class . ': ' . $e->getMessage()];
        }

        return [$res->response()->getStatusCode(), (string) $res->response()->getBody()];
    }

    /** @param list<string> $problems */
    private function assertNoProblems(array $problems): void
    {
        $this->assertSame([], $problems, count($problems) . " problem(s):\n" . implode("\n", $problems));
    }

    public function testTheSweepFindsEveryGroupRouteOfEveryArea(): void
    {
        $byArea = array_count_values(array_column(self::groupRoutes(), 'area'));

        // Instructor and chairperson mirror each other; the dean only reads; students have their own few.
        $this->assertSame($byArea['instructor'], $byArea['chairperson'], 'chairperson mirrors instructor');
        $this->assertSame(8, $byArea['dean']);
        $this->assertSame(13, $byArea['student']);
        ksort($byArea);
        $this->assertSame(['chairperson', 'dean', 'instructor', 'student'], array_keys($byArea), 'no group route outside these four areas');
        $this->assertSame(51, $byArea['instructor']);
    }

    /** The control for the refusal tests: with the right caller and own ids every read answers 200, so the addresses are real. */
    public function testTheRightfulCallerReadsEveryGetRoute(): void
    {
        $callers  = ['instructor' => 'teacher', 'chairperson' => 'chair', 'dean' => 'dean', 'student' => 's1'];
        $problems = [];

        foreach (self::groupRoutes() as $r) {
            if (self::isWrite($r)) {
                continue;
            }

            [$uri]           = $this->uri($r['key']);
            [$status, $text] = $this->send($this->who[$callers[$r['area']]], 'GET', $uri);

            if ($status !== 200) {
                $problems[] = "{$r['area']}: GET {$uri} -> {$status} " . substr($text, 0, 160);
            }
        }

        $this->assertNoProblems($problems);
    }

    public function testEveryGroupRouteHasARoleAndTheReadOnlyFilterAndTheDeanOnlyReads(): void
    {
        $problems = [];

        foreach (self::groupRoutes() as $r) {
            $filters = implode(',', $r['filters']);

            if (! str_contains($filters, 'role:' . $r['area']) || ! in_array('readonly', $r['filters'], true)) {
                $problems[] = "{$r['verb']} {$r['key']} filters [{$filters}]";
            }

            if ($r['area'] === 'dean' && self::isWrite($r)) {
                $problems[] = "dean write route {$r['verb']} {$r['key']}";
            }
        }

        $this->assertNoProblems($problems);
    }

    public function testNobodyWithoutTheRightRoleOrCourseGetsIn(): void
    {
        $problems = [];
        $w        = $this->who;

        foreach (self::groupRoutes() as $r) {
            [$uri] = $this->uri($r['key']);
            $body  = $this->body($r['key']);

            $callers = ['nobody' => null, 'parent' => $w['parent']];

            if (in_array($r['area'], ['instructor', 'chairperson'], true)) {
                $callers += ['student' => $w['s1'], 'stranger instructor' => $w['stranger'], 'other chairperson' => $w['otherChair']];
            }

            if ($r['area'] === 'instructor' && self::isWrite($r)) {
                $callers['dean'] = $w['dean'];
            }

            if ($r['area'] === 'student') {
                $callers += ['outsider student' => $w['outsider'], 'instructor' => $w['teacher'], 'dean' => $w['dean']];
            }

            if ($r['area'] === 'dean') {
                $callers += ['student' => $w['s1'], 'instructor' => $w['teacher']];
            }

            foreach ($callers as $label => $user) {
                [$status, $text] = $this->send($user, $r['verb'], $uri, $body);

                if (! in_array($status, self::REFUSED, true)) {
                    $problems[] = "{$label}: {$r['verb']} {$uri} -> {$status} " . substr($text, 0, 160);
                }
            }
        }

        $this->assertNoProblems($problems);
    }

    public function testTheDeanCannotWriteThroughTheDeanArea(): void
    {
        $problems = [];

        foreach (self::groupRoutes() as $r) {
            if ($r['area'] !== 'instructor' || ! self::isWrite($r)) {
                continue;
            }

            [$uri] = $this->uri('dean' . substr($r['key'], strlen('instructor')));
            [$status, $text] = $this->send($this->who['dean'], $r['verb'], $uri, $this->body($r['key']));

            if (! in_array($status, self::REFUSED, true)) {
                $problems[] = "dean: {$r['verb']} {$uri} -> {$status} " . substr($text, 0, 160);
            }
        }

        $this->assertNoProblems($problems);
    }

    public function testAForeignIdIsNotFoundOnEveryRouteForTheRightfulCaller(): void
    {
        $problems = [];
        $callers  = ['instructor' => 'teacher', 'chairperson' => 'chair', 'dean' => 'dean', 'student' => 's1'];
        $checked  = 0;

        foreach (self::groupRoutes() as $r) {
            [, $positions] = $this->uri($r['key']);
            $user          = $this->who[str_contains($r['key'], 'invitations') ? 's3' : $callers[$r['area']]];

            foreach (array_unique($positions) as $position) {
                [$uri]           = $this->uri($r['key'], $position);
                [$status, $text] = $this->send($user, $r['verb'], $uri, $this->body($r['key']));
                $checked++;

                // Another course in the address is refused by the course filters (403 or 404); every other foreign id is a 404.
                $ok = $position === 'offering' ? in_array($status, [403, 404], true) : $status === 404;

                if (! $ok) {
                    $problems[] = "foreign {$position}: {$r['verb']} {$uri} -> {$status} " . substr($text, 0, 160);
                }
            }
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertNoProblems($problems);
    }

    /** Ids that travel in the JSON body, not in the address: a group, set, activity or student of another course. */
    public function testAForeignIdInTheBodyIsRefused(): void
    {
        $own = $this->ids['own'];
        $far = $this->ids['foreign'];
        $o   = $own['offering'];
        $a   = $own['assessment'];
        $i   = $own['item'];
        $s   = $own['set'];

        $cases = [
            ['teacher', 'POST', "instructor/api/courses/{$o}/group-sets/{$s}/groups/{$own['group']['set']}/members", ['student_user_id' => $far['free']]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/group-sets/{$s}/members/move", ['student_user_id' => $own['member'], 'to_group_id' => $far['group']['set']]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/group-sets/{$s}/generate", ['groups' => [[$far['free'], $far['member']]], 'replace' => true]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/assessments/{$a}/groups/groups/{$own['group']['assessment']}/members", ['student_user_id' => $far['free']]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/assessments/{$a}/groups/members/move", ['student_user_id' => $own['member'], 'to_group_id' => $far['group']['assessment']]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/assessments/{$a}/groups/groups/{$own['group']['assessment']}/leader", ['student_user_id' => $far['member']]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/assessments/{$a}/groups/groups/{$own['group']['assessment']}/late-members", ['student_user_id' => $far['free'], 'reason' => 'Late']],
            ['teacher', 'POST', "instructor/api/courses/{$o}/assessments/{$a}/groups/copy-from", ['from_assessment_id' => $far['assessment']]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/learning-items/{$i}/groups/copy-from", ['from_learning_item_id' => $far['item']]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/learning-items/{$i}/groups/groups/{$own['group']['item']}/members", ['student_user_id' => $far['free']]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/learning-items/{$i}/groups/members/move", ['student_user_id' => $own['member'], 'to_group_id' => $far['group']['item']]],
            ['teacher', 'POST', "instructor/api/courses/{$o}/assessments/{$a}/group-grading/{$own['grading']}", ['group_score' => 10, 'members' => [$far['member'] => ['adjustment' => 2, 'reason' => 'x']]]],
            ['s2', 'POST', "student/api/courses/{$o}/assessments/{$a}/my-group/join", ['group_id' => $far['group']['assessment']]],
            ['s1', 'POST', "student/api/courses/{$o}/assessments/{$a}/my-group/switch", ['group_id' => $far['group']['assessment']]],
            ['s2', 'POST', "student/api/courses/{$o}/learning-items/{$i}/my-group/join", ['group_id' => $far['group']['item']]],
            ['s1', 'POST', "student/api/courses/{$o}/assessments/{$a}/my-group/vote", ['candidate_id' => $far['member']]],
        ];

        $problems = [];

        foreach ($cases as [$who, $verb, $uri, $body]) {
            [$status, $text] = $this->send($this->who[$who], $verb, $uri, $body);

            if (! in_array($status, [403, 404, 422], true)) {
                $problems[] = "{$who}: {$verb} {$uri} " . json_encode($body) . " -> {$status} " . substr($text, 0, 160);
            }
        }

        // Nothing of the other course was moved into this one, and no score was written for its student.
        $this->assertSame(0, db_connect()->table('student_group_members m')->join('student_groups g', 'g.id = m.group_id')
            ->whereIn('m.student_user_id', [$far['free'], $far['member']])->whereIn('g.config_id', $this->configsOf($o))->countAllResults(), 'a foreign student in this course\'s activity groups');
        $this->assertSame(0, db_connect()->table('student_group_members')->whereIn('student_user_id', [$far['free'], $far['member']])->where('set_id', $s)->countAllResults(), 'a foreign student in this course\'s set');
        $this->assertSame(0, db_connect()->table('group_member_scores')->where('student_user_id', $far['member'])->countAllResults());
        $this->assertNoProblems($problems);
    }

    /** @return list<int> */
    private function configsOf(int $offering): array
    {
        return array_map('intval', array_column(db_connect()->table('activity_group_configs')->select('id')->where('offering_id', $offering)->get()->getResultArray(), 'id'));
    }

    public function testAFinalizedOfferingRefusesEveryWrite(): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->ids['own']['offering'], 'finalized_by' => $this->who['teacher']->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $callers  = ['instructor' => 'teacher', 'chairperson' => 'chair', 'student' => 's1'];
        $problems = [];
        $notLock  = [];

        foreach (self::groupRoutes() as $r) {
            // generate/preview is a POST that writes nothing (GroupSetsController::generatePreview); its save is swept.
            if (! self::isWrite($r) || ! isset($callers[$r['area']]) || str_ends_with($r['key'], 'generate/preview')) {
                continue;
            }

            [$uri]           = $this->uri($r['key']);
            $user            = $this->who[str_contains($r['key'], 'invitations') ? 's3' : (str_contains($r['key'], 'my-group/join') || str_contains($r['key'], 'propose') ? 's2' : $callers[$r['area']])];
            [$status, $text] = $this->send($user, $r['verb'], $uri, $this->body($r['key']));

            if ($status < 400 || $status >= 500) {
                $problems[] = "{$r['verb']} {$uri} -> {$status} " . substr($text, 0, 160);
            } elseif (! str_contains($text, GradeFinalizationService::LOCKED_MESSAGE) && ! str_contains($text, 'grades of this course are final')) {
                $notLock[] = "{$r['verb']} {$uri} -> {$status} " . substr($text, 0, 160);
            }
        }

        $this->assertNoProblems($problems);
        $this->assertNoProblems($notLock);
    }
}
