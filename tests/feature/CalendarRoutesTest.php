<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The Calendar page, its events feed and the reminder actions, as both roles:
 * input is checked, answers carry the right status codes, and a reminder
 * only ever answers to its owner.
 *
 * @internal
 */
final class CalendarRoutesTest extends RouteTestCase
{
    private int $offering;

    private User $student;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
        $this->student = CourseFactory::user('student');
        $this->other = CourseFactory::user('student');

        CourseFactory::enroll($this->offering, $this->student);
    }

    private function prefix(User $user): string
    {
        return $user->inGroup('instructor') ? 'instructor' : 'student';
    }

    private function json(User $user, string $verb, string $path, array $body = [])
    {
        $this->loggedInAs($user);

        return $this->withBody(json_encode($body))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call($verb, $this->prefix($user) . '/api/calendar' . $path);
    }

    private function decoded($result): array
    {
        return json_decode((string) $result->getJSON(), true);
    }

    public function testTheEventsFeedReturnsTheRequestedRange(): void
    {
        $this->json($this->student, 'post', '/reminders', ['title' => 'In range', 'remind_at' => '2026-10-10 10:00:00']);
        $this->json($this->student, 'post', '/reminders', ['title' => 'Out of range', 'remind_at' => '2026-12-10 10:00:00']);

        $result = $this->json($this->student, 'get', '/events?from=2026-10-01+00:00:00&to=2026-10-31+23:59:59');
        $answer = $this->decoded($result);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertTrue($answer['success']);
        $this->assertSame(['In range'], array_column($answer['events'], 'title'));
        $this->assertSame([$this->offering], array_map('intval', array_column($answer['courses'], 'offering_id')));
    }

    public function testAnInvalidRangeIsA400(): void
    {
        foreach (['', '?from=nope&to=2026-10-31', '?from=2026-10-01', '?to=2026-10-31'] as $query) {
            $result = $this->json($this->student, 'get', '/events' . $query);

            $this->assertSame(400, $result->response()->getStatusCode(), $query);
            $this->assertFalse($this->decoded($result)['success']);
        }
    }

    public function testAReminderIsCreatedWith201AndAnInvalidOneWith422(): void
    {
        $created = $this->json($this->student, 'post', '/reminders', ['title' => 'Call TA', 'remind_at' => '2026-10-10 10:00:00']);

        $this->assertSame(201, $created->response()->getStatusCode());
        $this->assertSame('Call TA', $this->decoded($created)['reminder']['title']);

        $bad = $this->json($this->student, 'post', '/reminders', ['title' => '']);

        $this->assertSame(422, $bad->response()->getStatusCode());
        $this->assertSame(1, db_connect()->table('reminders')->countAllResults());
    }

    public function testTheOwnerMarksDoneAndDeletesWhileOthersGetA404(): void
    {
        $id = (int) $this->decoded($this->json($this->student, 'post', '/reminders', ['title' => 'Mine', 'remind_at' => '2026-10-10 10:00:00']))['reminder']['id'];

        $this->assertSame(404, $this->json($this->other, 'patch', "/reminders/{$id}/done")->response()->getStatusCode());
        $this->assertSame(404, $this->json($this->other, 'delete', "/reminders/{$id}")->response()->getStatusCode());
        $this->assertSame('pending', db_connect()->table('reminders')->where('id', $id)->get()->getRowArray()['status']);

        $this->assertSame(200, $this->json($this->student, 'patch', "/reminders/{$id}/done")->response()->getStatusCode());
        $this->assertSame('done', db_connect()->table('reminders')->where('id', $id)->get()->getRowArray()['status']);

        $this->assertSame(200, $this->json($this->student, 'delete', "/reminders/{$id}")->response()->getStatusCode());
        $this->assertSame(0, db_connect()->table('reminders')->countAllResults());
    }

    public function testBothRolesGetTheCalendarPage(): void
    {
        $teacher = CourseFactory::user('instructor');
        db_connect()->table('user_profiles')->insert(['user_id' => $teacher->id, 'first_name' => 'Tess', 'last_name' => 'Teacher']);
        db_connect()->table('auth_identities')->insert(['user_id' => $teacher->id, 'type' => 'email_password', 'secret' => 'tess@example.test', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);
        CourseFactory::assignInstructor($this->offering, $teacher);

        foreach ([$this->student, $teacher] as $user) {
            $this->loggedInAs($user);
            $result = $this->get($this->prefix($user) . '/calendar');

            $this->assertSame(200, $result->response()->getStatusCode(), $this->prefix($user));
            $this->assertStringContainsString('Calendar', (string) $result->response()->getBody());
        }
    }

    public function testALoggedOutVisitorIsSentToLogin(): void
    {
        foreach ([['get', 'student/calendar'], ['get', 'student/api/calendar/events?from=2026-10-01&to=2026-10-31'], ['post', 'student/api/calendar/reminders']] as [$verb, $url]) {
            $this->assertContains($this->call($verb, $url)->response()->getStatusCode(), [301, 302, 303, 401, 403], $url);
        }

        $this->assertSame(0, db_connect()->table('reminders')->countAllResults());
    }
}
