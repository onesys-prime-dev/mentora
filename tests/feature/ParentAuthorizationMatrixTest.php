<?php

declare(strict_types=1);

use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The parent area against every role and every id: each parent route is
 * listed here (a new one fails until it is), other roles are turned away,
 * a child or course that is not the parent's answers 404, nothing but the
 * parent's own two forms accepts a write, and a switched-off portal shows
 * no child data.
 *
 * @internal
 */
final class ParentAuthorizationMatrixTest extends RouteTestCase
{
    /** Every GET route of the parent group as the route table spells it, with the address it is called at. {c} child, {o} offering, {s} course slug. */
    private const GET_ROUTES = [
        'parent'                                                       => '/parent',
        'parent/dashboard'                                             => '/parent/dashboard',
        'parent/children/(:num)'                                       => '/parent/children/{c}',
        'parent/profile'                                               => '/parent/profile',
        'parent/calendar'                                              => '/parent/calendar',
        'parent/children/(:num)/calendar'                              => '/parent/children/{c}/calendar',
        'parent/children/(:num)/api/calendar/events'                   => '/parent/children/{c}/api/calendar/events?from=2026-01-01&to=2026-12-31',
        'parent/children/(:num)/api/courses/(:num)/overview'           => '/parent/children/{c}/api/courses/{o}/overview',
        'parent/children/(:num)/api/courses/(:num)/announcements'      => '/parent/children/{c}/api/courses/{o}/announcements',
        'parent/children/(:num)/api/courses/(:num)/assessments'        => '/parent/children/{c}/api/courses/{o}/assessments',
        'parent/children/(:num)/api/courses/(:num)/grades'             => '/parent/children/{c}/api/courses/{o}/grades',
        'parent/children/(:num)/api/courses/(:num)/grades/report/mine' => '/parent/children/{c}/api/courses/{o}/grades/report/mine',
        'parent/children/(:num)/courses/(:num)/(:segment)'             => '/parent/children/{c}/courses/{o}/{s}',
        'parent/children/(:num)/courses/(:num)/(:segment)/(:segment)'  => '/parent/children/{c}/courses/{o}/{s}/overview',
    ];

    /** The only things a parent may post, both to their own account. */
    private const POST_ROUTES = [
        'parent/profile/notifications',
        'parent/profile/password-link',
    ];

    /** The routes whose address carries a child id. */
    private const CHILD_ROUTES = [
        'parent/children/(:num)',
        'parent/children/(:num)/calendar',
        'parent/children/(:num)/api/calendar/events',
        'parent/children/(:num)/api/courses/(:num)/overview',
        'parent/children/(:num)/api/courses/(:num)/announcements',
        'parent/children/(:num)/api/courses/(:num)/assessments',
        'parent/children/(:num)/api/courses/(:num)/grades',
        'parent/children/(:num)/api/courses/(:num)/grades/report/mine',
        'parent/children/(:num)/courses/(:num)/(:segment)',
        'parent/children/(:num)/courses/(:num)/(:segment)/(:segment)',
    ];

    private int $institution;

    private User $parent;

    private User $child;

    private User $stranger;

    private int $mine;

    private int $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PAM');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $other             = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        $this->child       = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->stranger    = PeopleFactory::person('student', 'Sam', 'Stranger', null, $this->institution);
        $teacher           = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $this->parent->id, 'student_user_id' => $this->child->id, 'created_at' => $now]);
        $db->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $other->id, 'student_user_id' => $this->stranger->id, 'created_at' => $now]);

        $this->mine   = CourseFactory::offering('A');
        $this->theirs = CourseFactory::offering('B');

        foreach ([$this->mine, $this->theirs] as $offering) {
            CourseFactory::assignInstructor($offering, $teacher);
        }

        CourseFactory::enroll($this->mine, $this->child);
        CourseFactory::enroll($this->theirs, $this->stranger);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function slug(int $offering): string
    {
        helper('url');

        return url_title((string) db_connect()->table('course_offerings o')->select('c.course_title')->join('courses c', 'c.id = o.course_id')->where('o.id', $offering)->get()->getRowArray()['course_title'], '-', true);
    }

    private function address(string $template, User $child, int $offering): string
    {
        return str_replace(['{c}', '{o}', '{s}'], [(string) $child->id, (string) $offering, $this->slug($offering)], $template);
    }

    /** @return array{int, string} status and body */
    private function fetch(string $uri): array
    {
        try {
            $response = $this->call('GET', $uri)->response();

            return [$response->getStatusCode(), (string) $response->getBody()];
        } catch (PageNotFoundException) {
            return [404, ''];
        }
    }

    /** @return list<string> the route table's patterns for a verb that belong to the parent area */
    private function parentRoutes(string $verb): array
    {
        $routes = Services::routes(false);
        $routes->loadRoutes();

        // The route table stores the placeholders as regular expressions; the lists above use the short names.
        $names = array_map(static fn (string $r): string => str_replace(['([0-9]+)', '([^/]+)'], ['(:num)', '(:segment)'], $r), array_keys($routes->getRoutes($verb)));

        return array_values(array_filter($names, static fn (string $r): bool => $r === 'parent' || str_starts_with($r, 'parent/')));
    }

    public function testEveryRouteOfTheParentAreaIsListedInThisTest(): void
    {
        $get = $this->parentRoutes('GET');
        $listed = array_keys(self::GET_ROUTES);
        $post = $this->parentRoutes('POST');
        $allowedPost = self::POST_ROUTES;

        sort($get);
        sort($listed);
        sort($post);
        sort($allowedPost);

        $this->assertSame($listed, $get, 'a parent GET route was added or removed: list it in GET_ROUTES');
        $this->assertSame($allowedPost, $post, 'a parent can only post their own account forms');

        foreach (['PUT', 'PATCH', 'DELETE'] as $verb) {
            $this->assertSame([], $this->parentRoutes($verb), $verb);
        }
    }

    public function testEveryOtherRoleIsTurnedAwayFromEveryParentRoute(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'dean', 'institutional_admin', 'admin'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            foreach (self::GET_ROUTES as $template) {
                [$status] = $this->fetch($this->address($template, $this->child, $this->mine));

                $this->assertContains($status, [302, 403, 404], "{$role} {$template}");
            }
        }
    }

    public function testASignedOutVisitorIsSentToTheLoginFromEveryParentRoute(): void
    {
        foreach (self::GET_ROUTES as $template) {
            $response = $this->call('GET', $this->address($template, $this->child, $this->mine))->response();

            $this->assertSame(302, $response->getStatusCode(), $template);
            $this->assertStringContainsString('login', $response->getHeaderLine('Location'), $template);
        }
    }

    public function testAChildWhoIsNotTheParentsIs404OnEveryChildRoute(): void
    {
        $this->loggedInAs($this->parent);

        foreach (self::CHILD_ROUTES as $pattern) {
            [$status, $body] = $this->fetch($this->address(self::GET_ROUTES[$pattern], $this->stranger, $this->theirs));

            $this->assertSame(404, $status, $pattern);
            $this->assertStringNotContainsString('Stranger', $body, $pattern);
        }
    }

    public function testACourseTheChildIsNotInIs404OnEveryCourseRoute(): void
    {
        $this->loggedInAs($this->parent);

        foreach (self::CHILD_ROUTES as $pattern) {
            if (! str_contains($pattern, 'courses/')) {
                continue;
            }

            [$status] = $this->fetch($this->address(self::GET_ROUTES[$pattern], $this->child, $this->theirs));

            $this->assertSame(404, $status, $pattern);
        }
    }

    public function testTheirOwnChildAndCourseAreServed(): void
    {
        $this->loggedInAs($this->parent);

        foreach (self::CHILD_ROUTES as $pattern) {
            [$status, $body] = $this->fetch($this->address(self::GET_ROUTES[$pattern], $this->child, $this->mine));

            if (str_ends_with($pattern, 'grades/report/mine')) {
                // Nothing is final yet, so the report is refused with a message (ParentGradeReportTest covers the PDF).
                $this->assertSame(404, $status, $pattern);
                $this->assertStringContainsString('final', (string) (json_decode($body, true)['message'] ?? ''), $pattern);

                continue;
            }

            $this->assertContains($status, [200, 302], $pattern);
        }
    }

    public function testNothingButTheTwoAccountFormsAcceptsAWrite(): void
    {
        $this->loggedInAs($this->parent);

        $counts = static fn (): array => [
            db_connect()->table('parent_student_links')->countAllResults(),
            db_connect()->table('enrollments')->countAllResults(),
            db_connect()->table('gradebook_records')->countAllResults(),
        ];
        $before = $counts();

        foreach (self::GET_ROUTES as $pattern => $template) {
            foreach (['post', 'put', 'patch', 'delete'] as $verb) {
                try {
                    $status = $this->call($verb, $this->address($template, $this->child, $this->mine))->response()->getStatusCode();
                } catch (PageNotFoundException) {
                    $status = 404;
                }

                $this->assertContains($status, [403, 404, 405], strtoupper($verb) . ' ' . $pattern);
            }
        }

        $this->assertSame($before, $counts());
    }

    public function testASwitchedOffPortalShowsNoChildDataOnAnyChildRoute(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($this->parent);

        foreach (self::CHILD_ROUTES as $pattern) {
            [$status, $body] = $this->fetch($this->address(self::GET_ROUTES[$pattern], $this->child, $this->mine));

            $this->assertLessThan(500, $status, $pattern);
            $this->assertStringNotContainsString('Ann Lee', $body, $pattern);
        }
    }
}
