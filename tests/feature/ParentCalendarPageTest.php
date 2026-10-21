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
 * The parent's read-only calendar for a child: the page, its JSON, who is
 * turned away, and that nothing private (the child's reminders) is in it.
 *
 * @internal
 */
final class ParentCalendarPageTest extends RouteTestCase
{
    private int $institution;

    private User $parent;

    private User $child;

    private User $teacher;

    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        // The settings service remembers values in memory; a switch a test sets must not outlive it.
        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PCP');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->child       = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);

        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $this->parent->id, 'student_user_id' => $this->child->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->offering = CourseFactory::offering('A');
        $module         = CourseFactory::module($this->offering);
        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->child);

        $id = CourseFactory::assessment($this->offering, $this->teacher, $module);
        db_connect()->table('assessments')->where('id', $id)->update(['title' => 'Quiz Three', 'status' => 'Published', 'due_date' => '2026-11-10 17:00:00']);

        db_connect()->table('reminders')->insert([
            'user_id' => $this->child->id, 'title' => 'Buy a gift', 'remind_at' => '2026-11-10 09:00:00', 'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
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

    private function eventsUri(int $child): string
    {
        return '/parent/children/' . $child . '/api/calendar/events?from=' . rawurlencode('2026-11-01 00:00:00') . '&to=' . rawurlencode('2026-11-30 23:59:59');
    }

    public function testThePageIsReadOnlyAndPointsAtTheParentsApi(): void
    {
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open('/parent/children/' . $this->child->id . '/calendar');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('data-role="parent"', $body);
        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertStringContainsString('parent/children/' . $this->child->id . '/api/calendar', $body);
        $this->assertStringContainsString('parent/dashboard', $body, 'a way back');
        $this->assertStringNotContainsString('Reminder', $body, 'reminders are private to the student');
        $this->assertStringNotContainsString('Buy a gift', $body);
    }

    public function testTheEventsAreTheChildsDeadlinesWithoutAnyReminder(): void
    {
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->eventsUri((int) $this->child->id));
        $data            = json_decode($body, true);

        $this->assertSame(200, $status);
        $this->assertSame(['Quiz Three'], array_column($data['events'], 'title'));
        $this->assertStringNotContainsString('Buy a gift', $body);
    }

    public function testABadDateRangeIsRefused(): void
    {
        $this->loggedInAs($this->parent);

        [$status] = $this->open('/parent/children/' . $this->child->id . '/api/calendar/events?from=nope&to=never');

        $this->assertSame(400, $status);
    }

    public function testAChildThatIsNotTheirsIs404OnBothRoutes(): void
    {
        $other  = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        $theirs = PeopleFactory::person('student', 'Tom', 'Lee', null, $this->institution);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $other->id, 'student_user_id' => $theirs->id, 'created_at' => date('Y-m-d H:i:s')]);
        $this->loggedInAs($this->parent);

        $this->assertSame(404, $this->open('/parent/children/' . $theirs->id . '/calendar')[0]);
        $this->assertSame(404, $this->open($this->eventsUri((int) $theirs->id))[0]);
    }

    public function testASwitchedOffPortalShowsNothing(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open('/parent/children/' . $this->child->id . '/calendar');
        [$jsonStatus, $jsonBody] = $this->open($this->eventsUri((int) $this->child->id));

        $this->assertSame(200, $status);
        $this->assertStringContainsString('parent portal is switched off', $body);
        $this->assertStringNotContainsString('Ann Lee', $body);
        $this->assertSame(403, $jsonStatus);
        $this->assertStringNotContainsString('Quiz Three', $jsonBody);
    }

    public function testTheCalendarMenuEntryOpensTheFirstChildsCalendar(): void
    {
        $this->loggedInAs($this->parent);

        $response = $this->call('GET', '/parent/calendar')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/parent/children/' . $this->child->id . '/calendar', $response->getHeaderLine('Location'));
    }

    public function testAParentWithNoChildrenGetsTheExplanationInsteadOfARedirect(): void
    {
        $this->loggedInAs(PeopleFactory::person('parent', 'Lone', 'Parent', null, $this->institution));

        [$status, $body] = $this->open('/parent/calendar');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('not linked to a student yet', $body);
    }

    public function testEveryOtherRoleIsTurnedAway(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'admin'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            foreach (['/parent/children/' . $this->child->id . '/calendar', $this->eventsUri((int) $this->child->id), '/parent/calendar'] as $uri) {
                $this->assertSame(302, $this->open($uri)[0], $role . ' ' . $uri);
            }
        }
    }

    public function testNothingCanBeChanged(): void
    {
        $this->loggedInAs($this->parent);

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            foreach (['/parent/children/' . $this->child->id . '/calendar', '/parent/children/' . $this->child->id . '/api/calendar/reminders'] as $uri) {
                try {
                    $status = $this->call($verb, $uri)->response()->getStatusCode();
                } catch (PageNotFoundException) {
                    $status = 404;
                }

                $this->assertContains($status, [403, 404, 405], strtoupper($verb) . ' ' . $uri);
            }
        }
    }
}
