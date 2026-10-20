<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\RouteTestCase;

/**
 * Every gradebook route, over HTTP: who can reach it, that another section's
 * ids are never honoured, that a closed section can be read but not changed,
 * and that the roles each have exactly the grading routes they should.
 * (Read-only institutions are covered for every changing route by
 * ReadOnlyModeTest.)
 *
 * @internal
 */
final class GradeRoutesTest extends RouteTestCase
{
    private const WRITING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private int $mine;

    private int $theirs;

    private User $teacher;

    private User $stranger;

    private User $ann;

    private User $outsider;

    // Mine
    private int $myCategory;

    private int $myItem;

    // Theirs: ids that must never be reachable through my section
    private int $theirCategory;

    private int $theirItem;

    private int $theirPeriod;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->mine = CourseFactory::offering('A');
        $this->theirs = CourseFactory::offering('B');

        $this->teacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->mine, $this->teacher);

        $this->stranger = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->theirs, $this->stranger);

        $this->ann = CourseFactory::user('student');
        CourseFactory::enroll($this->mine, $this->ann);

        $this->outsider = CourseFactory::user('student');
        CourseFactory::enroll($this->theirs, $this->outsider);

        foreach ([$this->mine, $this->theirs] as $offering) {
            $scheme = GradeFactory::scheme($offering, 75.0);
            db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);
            $category = GradeFactory::category($scheme, 'Exams', 100);
            $item = GradeFactory::item($scheme, $category, 'Midterm', 100);

            if ($offering === $this->mine) {
                $this->myCategory = $category;
                $this->myItem = $item;
            } else {
                $this->theirCategory = $category;
                $this->theirItem = $item;
                $this->theirPeriod = GradeFactory::period($scheme, 'Prelim', 100);
            }
        }
    }

    private function as(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** @return array{status: int, json: array<string, mixed>|null} */
    private function send(string $verb, string $uri, array $body = []): array
    {
        try {
            $response = $this->withBody(json_encode($body))
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
                ->call(strtolower($verb), $uri)
                ->response();
        } catch (PageNotFoundException) {
            return ['status' => 404, 'json' => null];
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'json' => is_array($decoded) ? $decoded : null];
    }

    /**
     * Every grading route of one role area, as [verb, uri with the section filled in and 1 for other ids].
     *
     * @return list<array{string, string}>
     */
    private function gradeRoutes(string $area, int $offering): array
    {
        $routes = service('routes')->loadRoutes();

        $found = [];

        foreach (['GET', ...self::WRITING] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $route) {

                if (preg_match('#^' . $area . '/api/courses/\(\[0-9\]\+\)/grades(/|$)#', $route) !== 1) {
                    continue;
                }

                $uri = preg_replace('/\(\[0-9\]\+\)/', (string) $offering, $route, 1);
                $uri = str_replace('([0-9]+)', '1', $uri);

                $found[] = [$verb, preg_replace('/\([^()]*\)/', 'x', $uri)];

            }
        }

        return $found;
    }

    /** @return list<string> the routes of an area without the section, e.g. "PATCH grades/scheme" */
    private function shape(string $area): array
    {
        $routes = service('routes')->loadRoutes();

        $shapes = [];

        foreach (['GET', ...self::WRITING] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $route) {
                if (preg_match('#^' . $area . '/api/courses/\(\[0-9\]\+\)/(grades(/.*)?)$#', $route, $m) === 1) {
                    $shapes[] = $verb . ' ' . $m[1];
                }
            }
        }

        sort($shapes);

        return $shapes;
    }

    /*
    |--------------------------------------------------------------------------
    | Who has which routes
    |--------------------------------------------------------------------------
    */

    public function testTheInstructorAreaHasTheWholeGradebookAndTheTableIsRead(): void
    {
        $shapes = $this->shape('instructor');

        $this->assertGreaterThan(30, count($shapes), 'the route table was not read');

        foreach ([
            'GET grades', 'GET grades/roster', 'GET grades/export', 'POST grades/recalculate',
            'PATCH grades/scheme', 'POST grades/finalization', 'POST grades/finalization/reopen',
            'POST grades/curve', 'POST grades/curve/preview', 'GET grades/report/class',
            'PATCH grades/roster/students/([0-9]+)/incomplete', 'PATCH grades/roster/students/([0-9]+)/override',
        ] as $expected) {
            $this->assertContains($expected, $shapes, $expected);
        }
    }

    public function testAStudentCanOnlyReadTheirOwnGradesAndReport(): void
    {
        $shapes = $this->shape('student');

        $this->assertSame(['GET grades', 'GET grades/report/mine'], $shapes);
    }

    public function testTheDeanAndTheInstitutionCanLookAtGradesButChangeNothing(): void
    {
        foreach (['dean', 'institution'] as $area) {

            $shapes = $this->shape($area);

            $this->assertNotSame([], $shapes, $area);

            foreach ($shapes as $shape) {
                $this->assertStringStartsWith('GET ', $shape, "{$area} may only read: {$shape}");
            }

            $this->assertContains('GET grades/report/summary', $shapes, $area);

        }
    }

    public function testTheChairpersonHasTheInstructorsGradebookExceptFinalizing(): void
    {
        $instructor = $this->shape('instructor');
        $chair = $this->shape('chairperson');

        $this->assertSame(
            ['POST grades/finalization'],
            array_values(array_diff($instructor, $chair)),
            'only finalizing is the instructor\'s alone; the chairperson can see where grades stand and reopen them'
        );

        $this->assertSame([], array_values(array_diff($chair, $instructor)), 'and nothing the instructor lacks');
    }

    /*
    |--------------------------------------------------------------------------
    | Strangers
    |--------------------------------------------------------------------------
    */

    public function testAnInstructorOfAnotherSectionReachesNoGradeRouteOfMine(): void
    {
        $this->as($this->stranger);

        $reached = [];

        foreach ($this->gradeRoutes('instructor', $this->mine) as [$verb, $uri]) {
            if ($this->send($verb, $uri)['status'] !== 404) {
                $reached[] = "{$verb} {$uri}";
            }
        }

        $this->assertSame([], $reached, 'these grade routes answered someone who does not teach the section');
    }

    public function testAStudentReachesNoInstructorGradeRoute(): void
    {
        $this->as($this->ann);

        $reached = [];

        foreach ($this->gradeRoutes('instructor', $this->mine) as [$verb, $uri]) {
            $status = $this->send($verb, $uri)['status'];

            if (!in_array($status, [302, 403, 404], true)) {
                $reached[] = "{$status} {$verb} {$uri}";
            }
        }

        $this->assertSame([], $reached);
    }

    public function testAStudentOfAnotherSectionHasNoGradesOrReportHere(): void
    {
        $this->as($this->outsider);

        foreach (['grades', 'grades/report/mine'] as $path) {
            $this->assertSame(404, $this->send('GET', "student/api/courses/{$this->mine}/{$path}")['status'], $path);
        }
    }

    public function testAVisitorIsSentToSignIn(): void
    {
        auth()->logout();
        $this->withSession([]);

        $status = $this->send('GET', "instructor/api/courses/{$this->mine}/grades/roster")['status'];

        $this->assertContains($status, [302, 401, 403]);
    }

    /*
    |--------------------------------------------------------------------------
    | Another section's ids are never honoured
    |--------------------------------------------------------------------------
    */

    public function testIdsFromAnotherSectionAreRefusedThroughMine(): void
    {
        $this->as($this->teacher);

        $base = "instructor/api/courses/{$this->mine}/grades";

        $foreign = [
            'rename their item' => ['PATCH', "{$base}/scheme/items/{$this->theirItem}", ['name' => 'Mine now']],
            'remove their item' => ['DELETE', "{$base}/scheme/items/{$this->theirItem}", []],
            'restore their item' => ['POST', "{$base}/scheme/items/{$this->theirItem}/restore", []],
            'resync their item' => ['POST', "{$base}/scheme/items/{$this->theirItem}/resync", []],
            'rename their category' => ['PATCH', "{$base}/scheme/categories/{$this->theirCategory}", ['name' => 'Mine now']],
            'remove their category' => ['DELETE', "{$base}/scheme/categories/{$this->theirCategory}", []],
            'restore their category' => ['POST', "{$base}/scheme/categories/{$this->theirCategory}/restore", []],
            'add an item to their category' => ['POST', "{$base}/scheme/categories/{$this->theirCategory}/items", ['name' => 'Sneaky', 'max_points' => 10]],
            'reorder their items' => ['PATCH', "{$base}/scheme/categories/{$this->theirCategory}/items/order", ['ids' => [$this->theirItem]]],
            'edit their period' => ['PATCH', "{$base}/scheme/periods/{$this->theirPeriod}", ['name' => 'Mine now']],
            'remove their period' => ['DELETE', "{$base}/scheme/periods/{$this->theirPeriod}", []],
            'score their item' => ['PATCH', "{$base}/roster/items/{$this->theirItem}/students/{$this->ann->id}", ['score' => 50]],
            'score someone not in my section' => ['PATCH', "{$base}/roster/items/{$this->myItem}/students/{$this->outsider->id}", ['score' => 50]],
            'override someone not in my section' => ['PATCH', "{$base}/roster/students/{$this->outsider->id}/override", ['percentage' => 90, 'reason' => 'x']],
            'mark someone not in my section Incomplete' => ['PATCH', "{$base}/roster/students/{$this->outsider->id}/incomplete", ['incomplete' => true, 'reason' => 'x']],
            'curve their item' => ['POST', "{$base}/curve", ['scope' => 'item', 'item_id' => $this->theirItem, 'method' => 'add_points', 'input' => ['points' => 5]]],
            'preview a curve of their item' => ['POST', "{$base}/curve/preview", ['scope' => 'item', 'item_id' => $this->theirItem, 'method' => 'add_points', 'input' => ['points' => 5]]],
            'remove a curve that is not mine' => ['DELETE', "{$base}/curve/999999", []],
            'report on a student not in my section' => ['GET', "{$base}/report/student/{$this->outsider->id}", []],
        ];

        $honoured = [];

        foreach ($foreign as $what => [$verb, $uri, $body]) {

            $result = $this->send($verb, $uri, $body);

            $refused = $result['status'] === 404 || ($result['json']['success'] ?? true) === false;

            if (!$refused) {
                $honoured[] = $what;
            }

        }

        $this->assertSame([], $honoured, 'these were honoured through my section');

        // And nothing of theirs changed.
        $this->assertSame('Midterm', db_connect()->table('grade_items')->where('id', $this->theirItem)->get()->getRowArray()['name']);
        $this->assertSame('Active', db_connect()->table('grade_items')->where('id', $this->theirItem)->get()->getRowArray()['status']);
        $this->assertSame(0, db_connect()->table('grade_adjustments')->countAllResults());
        $this->assertSame(0, db_connect()->table('gradebook_item_scores')->where('student_id', $this->outsider->id)->countAllResults());
    }

    public function testMyOwnIdsStillWork(): void
    {
        $this->as($this->teacher);

        $base = "instructor/api/courses/{$this->mine}/grades";

        $renamed = $this->send('PATCH', "{$base}/scheme/items/{$this->myItem}", ['name' => 'Midterm exam']);
        $scored = $this->send('PATCH', "{$base}/roster/items/{$this->myItem}/students/{$this->ann->id}", ['score' => 80]);

        $this->assertSame(200, $renamed['status']);
        $this->assertTrue($renamed['json']['success']);
        $this->assertTrue($scored['json']['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | A closed section
    |--------------------------------------------------------------------------
    */

    public function testAClosedSectionRefusesEveryGradeChangeButStillShowsAndExports(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->mine)->update(['status' => 'completed']);

        $this->as($this->teacher);

        $base = "instructor/api/courses/{$this->mine}/grades";

        $changes = [
            ['PATCH', "{$base}/roster/items/{$this->myItem}/students/{$this->ann->id}", ['score' => 50]],
            ['PATCH', "{$base}/scheme", ['passing_percentage' => 60]],
            ['POST', "{$base}/scheme/categories", ['name' => 'Projects', 'weight_percentage' => 0]],
            ['PATCH', "{$base}/roster/students/{$this->ann->id}/override", ['percentage' => 90, 'reason' => 'x']],
            ['PATCH', "{$base}/roster/students/{$this->ann->id}/incomplete", ['incomplete' => true, 'reason' => 'x']],
            ['POST', "{$base}/recalculate", []],
            ['POST', "{$base}/finalization", []],
            ['POST', "{$base}/curve", ['scope' => 'final', 'method' => 'add_points', 'input' => ['points' => 5]]],
        ];

        foreach ($changes as [$verb, $uri, $body]) {

            $result = $this->send($verb, $uri, $body);

            $this->assertSame(403, $result['status'], "{$verb} {$uri}");
            $this->assertSame('offering_closed', $result['json']['error'] ?? null, "{$verb} {$uri}");

        }

        foreach (['', '/roster', '/scheme', '/curve', '/finalization', '/report/class?format=csv', '/report/summary?format=html'] as $read) {
            $this->assertNotSame(403, $this->send('GET', $base . $read)['status'], "GET {$base}{$read}");
        }
    }

    public function testANeighbouringActiveSectionStaysWritable(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->theirs)->update(['status' => 'completed']);

        $this->as($this->teacher);

        $result = $this->send('PATCH', "instructor/api/courses/{$this->mine}/grades/roster/items/{$this->myItem}/students/{$this->ann->id}", ['score' => 70]);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['json']['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | Finalized grades over HTTP
    |--------------------------------------------------------------------------
    */

    public function testFinalizingOverHttpLocksTheGradebookUntilItIsReopened(): void
    {
        $this->as($this->teacher);

        $base = "instructor/api/courses/{$this->mine}/grades";

        $this->assertTrue($this->send('PATCH', "{$base}/roster/items/{$this->myItem}/students/{$this->ann->id}", ['score' => 80])['json']['success']);

        $finalized = $this->send('POST', "{$base}/finalization");

        $this->assertTrue($finalized['json']['success'], $finalized['json']['message'] ?? '');

        $locked = $this->send('PATCH', "{$base}/roster/items/{$this->myItem}/students/{$this->ann->id}", ['score' => 10]);

        $this->assertFalse($locked['json']['success']);
        $this->assertStringContainsString('finalized', $locked['json']['message']);

        $this->assertFalse($this->send('POST', "{$base}/finalization/reopen", ['reason' => ''])['json']['success'], 'a reason is required');
        $this->assertTrue($this->send('POST', "{$base}/finalization/reopen", ['reason' => 'A score was wrong'])['json']['success']);
        $this->assertTrue($this->send('PATCH', "{$base}/roster/items/{$this->myItem}/students/{$this->ann->id}", ['score' => 85])['json']['success']);
    }

    public function testTheCurveRoutesPreviewApplyAndRemoveOverHttp(): void
    {
        $this->as($this->teacher);

        $base = "instructor/api/courses/{$this->mine}/grades";

        $this->send('PATCH', "{$base}/roster/items/{$this->myItem}/students/{$this->ann->id}", ['score' => 60]);

        $request = ['scope' => 'item', 'item_id' => $this->myItem, 'method' => 'add_points', 'input' => ['points' => 10]];

        $preview = $this->send('POST', "{$base}/curve/preview", $request);

        $this->assertTrue($preview['json']['success']);
        $this->assertSame(0, db_connect()->table('grade_adjustments')->countAllResults(), 'a preview saves nothing');

        $this->assertTrue($this->send('POST', "{$base}/curve", $request + ['note' => 'Long exam'])['json']['success']);

        $live = $this->send('GET', "{$base}/curve")['json']['live'];

        $this->assertCount(1, $live);
        $this->assertTrue($this->send('DELETE', "{$base}/curve/{$live[0]['id']}")['json']['success']);
        $this->assertSame([], $this->send('GET', "{$base}/curve")['json']['live']);
    }
}
