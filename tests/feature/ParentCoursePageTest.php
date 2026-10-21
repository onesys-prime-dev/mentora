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
 * A child's course for the parent: the page shell with its four tabs, who
 * gets a 404, and that the tabs a parent must not see do not exist.
 *
 * @internal
 */
final class ParentCoursePageTest extends RouteTestCase
{
    private int $institution;

    private User $parent;

    private User $child;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        helper('url');
        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PCO');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->child       = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);

        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $this->parent->id, 'student_user_id' => $this->child->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** An offering the given student is in. */
    private function course(User $student, string $section = 'A', string $enrolment = 'enrolled', string $offeringStatus = 'active'): int
    {
        $offering = CourseFactory::offering($section);

        db_connect()->table('course_offerings')->where('id', $offering)->update(['status' => $offeringStatus]);
        CourseFactory::assignInstructor($offering, $this->teacher);
        CourseFactory::enroll($offering, $student, $enrolment);

        return $offering;
    }

    private function slug(int $offering): string
    {
        $title = db_connect()->table('course_offerings o')->select('c.course_title')->join('courses c', 'c.id = o.course_id')->where('o.id', $offering)->get()->getRowArray()['course_title'];

        return url_title($title, '-', true);
    }

    private function title(int $offering): string
    {
        return db_connect()->table('course_offerings o')->select('c.course_title')->join('courses c', 'c.id = o.course_id')->where('o.id', $offering)->get()->getRowArray()['course_title'];
    }

    private function uri(int $offering, string $feature = 'overview', ?int $child = null): string
    {
        return '/parent/children/' . ($child ?? $this->child->id) . '/courses/' . $offering . '/' . $this->slug($offering) . ($feature === '' ? '' : '/' . $feature);
    }

    /** @return array{int, string, string} status, body, redirect target */
    private function open(string $uri): array
    {
        try {
            $response = $this->call('GET', $uri)->response();

            return [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('Location')];
        } catch (PageNotFoundException) {
            return [404, '', ''];
        }
    }

    public function testALinkedChildsCourseOpensWithExactlyTheFourTabs(): void
    {
        $offering = $this->course($this->child);
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->uri($offering));

        $this->assertSame(200, $status);
        $this->assertStringContainsString($this->title($offering), $body);

        foreach (['overview', 'announcements', 'assessments', 'grades'] as $tab) {
            $this->assertStringContainsString('data-course-feature="' . $tab . '"', $body, $tab);
        }

        foreach (['modules', 'resources', 'discussions'] as $tab) {
            $this->assertStringNotContainsString('data-course-feature="' . $tab . '"', $body, $tab);
        }

        // The page's script config is JSON, which writes a slash as "\/".
        $this->assertStringContainsString(str_replace('/', '\/', 'parent/children/' . $this->child->id . '/api/courses/' . $offering), $body);
        $this->assertStringContainsString('View only', $body);
        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertStringContainsString('parent/children/' . $this->child->id, $body, 'a way back to the child\'s dashboard');
    }

    public function testEachOfTheFourTabsHasItsOwnAddress(): void
    {
        $offering = $this->course($this->child);
        $this->loggedInAs($this->parent);

        foreach (['overview', 'announcements', 'assessments', 'grades'] as $tab) {
            $this->assertSame(200, $this->open($this->uri($offering, $tab))[0], $tab);
        }
    }

    public function testTheCourseAddressGoesToTheOverview(): void
    {
        $offering = $this->course($this->child);
        $this->loggedInAs($this->parent);

        [$status, , $location] = $this->open($this->uri($offering, ''));

        $this->assertSame(302, $status);
        $this->assertStringEndsWith($this->slug($offering) . '/overview', $location);
    }

    public function testTheTabsAParentMustNotSeeDoNotExist(): void
    {
        $offering = $this->course($this->child);
        $this->loggedInAs($this->parent);

        foreach (['modules', 'resources', 'discussions', 'modules/items/5', 'grades/roster', 'assessments/5/view'] as $tab) {
            $this->assertSame(404, $this->open($this->uri($offering, $tab))[0], $tab);
        }
    }

    public function testACourseTheChildIsNotInIs404(): void
    {
        $this->course($this->child);
        $other = $this->course(PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution));
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->uri($other));

        $this->assertSame(404, $status);
        $this->assertStringNotContainsString($this->title($other), $body);
        $this->assertSame(404, $this->open('/parent/children/' . $this->child->id . '/courses/99999999/anything/overview')[0]);
        $this->assertSame(404, $this->open('/parent/children/' . $this->child->id . '/courses/abc/anything/overview')[0]);
    }

    public function testAnotherParentsChildCoursesAre404(): void
    {
        $tom   = PeopleFactory::person('student', 'Tom', 'Lee', null, $this->institution);
        $other = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $other->id, 'student_user_id' => $tom->id, 'created_at' => date('Y-m-d H:i:s')]);
        $offering = $this->course($tom);
        $this->loggedInAs($this->parent);

        $this->assertSame(404, $this->open($this->uri($offering, 'overview', (int) $tom->id))[0]);
    }

    public function testDroppedAndFinishedCoursesOpenReadOnly(): void
    {
        $dropped  = $this->course($this->child, 'B', 'dropped');
        $finished = $this->course($this->child, 'C', 'completed', 'completed');
        $this->loggedInAs($this->parent);

        $this->assertSame(200, $this->open($this->uri($dropped))[0]);

        [$status, $body] = $this->open($this->uri($finished));

        $this->assertSame(200, $status);
        $this->assertStringContainsString('data-offering-closed', $body);
    }

    public function testASwitchedOffPortalShowsNoCourse(): void
    {
        $offering = $this->course($this->child);
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->uri($offering));

        $this->assertSame(200, $status);
        $this->assertStringContainsString('parent portal is switched off', $body);
        $this->assertStringNotContainsString($this->title($offering), $body);
    }

    public function testEveryOtherRoleIsTurnedAway(): void
    {
        $offering = $this->course($this->child);

        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'admin'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            $this->assertSame(302, $this->open($this->uri($offering))[0], $role);
        }
    }

    public function testNothingCanBeChanged(): void
    {
        $offering = $this->course($this->child);
        $this->loggedInAs($this->parent);

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            try {
                $status = $this->call($verb, $this->uri($offering))->response()->getStatusCode();
            } catch (PageNotFoundException) {
                $status = 404;
            }

            $this->assertContains($status, [403, 404, 405], strtoupper($verb));
        }
    }
}
