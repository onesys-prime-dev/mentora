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
 * The four tabs' endpoints for a parent: they answer only for the parent's
 * own child and a course the child is in, nothing is read while the portal is
 * off, nothing can be changed, and the routes of every other tab do not exist.
 *
 * @internal
 */
final class ParentCourseTabsTest extends RouteTestCase
{
    private int $institution;

    private User $parent;

    private User $child;

    private User $teacher;

    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PCT2');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->child       = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);

        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $this->parent->id, 'student_user_id' => $this->child->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->offering = CourseFactory::offering('A');
        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->child);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function api(string $tab, ?int $offering = null, ?int $child = null): string
    {
        return '/parent/children/' . ($child ?? $this->child->id) . '/api/courses/' . ($offering ?? $this->offering) . '/' . $tab;
    }

    /** @return array{int, string} */
    private function open(string $uri): array
    {
        try {
            $response = $this->call('GET', $uri)->response();

            return [$response->getStatusCode(), (string) $response->getBody()];
        } catch (PageNotFoundException) {
            return [404, ''];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideTabs(): iterable
    {
        foreach (['overview', 'announcements', 'assessments', 'grades'] as $tab) {
            yield $tab => [$tab];
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideTabs')]
    public function testATabAnswersWithItsHtmlForTheParentsChild(string $tab): void
    {
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->api($tab));
        $json            = json_decode($body, true);

        $this->assertSame(200, $status);
        $this->assertIsString($json['html']);
        $this->assertNotSame('', $json['html']);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideTabs')]
    public function testACourseOrChildThatIsNotTheirsIs404(string $tab): void
    {
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        $theirs   = CourseFactory::offering('B');
        CourseFactory::enroll($theirs, $stranger);
        $this->loggedInAs($this->parent);

        $this->assertSame(404, $this->open($this->api($tab, $theirs))[0], 'a course the child is not in');
        $this->assertSame(404, $this->open($this->api($tab, $theirs, (int) $stranger->id))[0], 'a student who is not their child');
        $this->assertSame(404, $this->open($this->api($tab, 99999999))[0]);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideTabs')]
    public function testASwitchedOffPortalGivesNothing(string $tab): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->api($tab));

        $this->assertSame(403, $status);
        $this->assertStringNotContainsString('Tina Tan', $body);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideTabs')]
    public function testEveryOtherRoleIsTurnedAway(string $tab): void
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'admin'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            $this->assertSame(302, $this->open($this->api($tab))[0], $role);
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideTabs')]
    public function testNothingCanBeChanged(string $tab): void
    {
        $this->loggedInAs($this->parent);

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            try {
                $status = $this->call($verb, $this->api($tab))->response()->getStatusCode();
            } catch (PageNotFoundException) {
                $status = 404;
            }

            $this->assertContains($status, [403, 404, 405], strtoupper($verb));
        }
    }

    public function testTheOtherTabsOfTheStudentsWorkspaceDoNotExistForAParent(): void
    {
        $this->loggedInAs($this->parent);

        foreach (['modules', 'modules/1', 'modules/items/1', 'resources', 'discussions', 'discussions/1', 'assessments/1/view', 'assessments/1/take', 'grades/roster', 'grades/scheme', 'grades/export', 'grades/report/class'] as $path) {
            $this->assertSame(404, $this->open($this->api($path))[0], $path);
        }
    }
}
