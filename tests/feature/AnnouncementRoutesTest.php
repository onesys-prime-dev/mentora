<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The announcement routes end to end: the JSON body and query string reach
 * the service, the answers are JSON the page can use, the scope filter keeps
 * people to their own sections on every action, and posting from the Calendar
 * works. The service's own rules are covered in AnnouncementServiceTest.
 *
 * @internal
 */
final class AnnouncementRoutesTest extends RouteTestCase
{
    private int $offering;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->student);
    }

    private function prefix(User $user): string
    {
        return $user->inGroup('instructor') ? 'instructor' : 'student';
    }

    private function send(User $user, string $verb, string $path = '', array $body = [], ?int $offering = null)
    {
        $this->loggedInAs($user);

        return $this->withBody(json_encode($body))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call($verb, $this->prefix($user) . '/api/courses/' . ($offering ?? $this->offering) . '/announcements' . $path);
    }

    private function api(User $user, string $verb, string $path = '', array $body = [], ?int $offering = null): array
    {
        $result = $this->send($user, $verb, $path, $body, $offering);

        $this->assertSame(200, $result->response()->getStatusCode(), "{$verb} {$path}");

        return json_decode((string) $result->getJSON(), true);
    }

    private function announce(string $subject = 'Welcome'): int
    {
        return (int) $this->api($this->instructor, 'post', '', ['subject' => $subject, 'message' => '{}'])['announcement_id'];
    }

    public function testTheInstructorPostsEditsPinsAndDeletes(): void
    {
        $id = $this->announce(' Quiz ');
        $this->assertSame('Quiz', db_connect()->table('announcements')->where('id', $id)->get()->getRowArray()['subject']);

        $this->assertTrue($this->api($this->instructor, 'patch', "/{$id}", ['subject' => 'Quiz moved'])['success']);
        $this->assertSame('Quiz moved', db_connect()->table('announcements')->where('id', $id)->get()->getRowArray()['subject']);

        $this->assertTrue($this->api($this->instructor, 'patch', "/{$id}/pin")['is_pinned']);

        $this->assertTrue($this->api($this->instructor, 'delete', "/{$id}")['success']);
        $this->assertSame(0, db_connect()->table('announcements')->countAllResults());
    }

    public function testStudentsReadReplyAndReactThroughTheirOwnRoutes(): void
    {
        $id = $this->announce('Read me');

        $page = $this->api($this->student, 'get');
        $this->assertStringContainsString('Read me', $page['html']);

        $more = $this->api($this->student, 'get', '/more?page=2');
        $this->assertTrue($more['success']);

        $reply = $this->api($this->student, 'post', "/{$id}/reply", ['message' => 'Thanks']);
        $this->assertTrue($reply['success']);

        $this->assertSame(['like' => 1], $this->api($this->student, 'post', "/{$id}/react", ['emoji' => 'like'])['reaction_counts']);

        $this->assertTrue($this->api($this->student, 'delete', "/replies/{$reply['reply_id']}")['success']);
    }

    public function testAStudentCannotUseTheInstructorPostRoute(): void
    {
        $this->loggedInAs($this->student);

        $response = $this->withBody(json_encode(['subject' => 'Hi']))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call('post', "instructor/api/courses/{$this->offering}/announcements")
            ->response();

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(0, db_connect()->table('announcements')->countAllResults());
    }

    public function testTheFocusAndSearchParametersReachTheFeed(): void
    {
        $this->announce('Apples');
        $this->announce('Bananas');

        $page = $this->api($this->student, 'get', '?search=Banana');

        $this->assertStringContainsString('Bananas', $page['html']);
        $this->assertStringNotContainsString('Apples', $page['html']);
    }

    public static function actions(): array
    {
        return [
            'show' => ['get', ''],
            'more' => ['get', '/more'],
            'reply' => ['post', '/1/reply'],
            'delete reply' => ['delete', '/replies/1'],
            'react' => ['post', '/1/react'],
        ];
    }

    public static function instructorActions(): array
    {
        return [
            'create' => ['post', ''],
            'update' => ['patch', '/1'],
            'delete' => ['delete', '/1'],
            'pin' => ['patch', '/1/pin'],
            'attach' => ['post', '/attachments'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('actions')]
    public function testAnotherSectionIsNotFoundForStudentsAndInstructors(string $verb, string $path): void
    {
        $other = CourseFactory::offering('B');

        foreach ([$this->student, $this->instructor] as $user) {
            try {
                $this->send($user, $verb, $path, [], $other);
                $this->fail("{$this->prefix($user)} reached {$verb} {$path} in another section");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('instructorActions')]
    public function testAnotherSectionIsNotFoundForInstructorOnlyActions(string $verb, string $path): void
    {
        $other = CourseFactory::offering('B');

        try {
            $this->send($this->instructor, $verb, $path, ['subject' => 'x'], $other);
            $this->fail("reached {$verb} {$path} in another section");
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, db_connect()->table('announcements')->countAllResults());
    }

    public function testALoggedOutVisitorIsSentToLogin(): void
    {
        $response = $this->call('get', "student/api/courses/{$this->offering}/announcements")->response();

        $this->assertContains($response->getStatusCode(), [301, 302, 303, 401, 403]);
    }

    public function testThePostFromTheCalendarCreatesAnAnnouncement(): void
    {
        $this->loggedInAs($this->instructor);

        $result = $this->withBody(json_encode(['offering_id' => $this->offering, 'title' => 'From calendar', 'body' => "line one\nline two"]))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call('post', 'instructor/api/calendar/announcements');

        $this->assertSame(201, $result->response()->getStatusCode());

        $row = db_connect()->table('announcements')->get()->getRowArray();
        $this->assertSame('From calendar', $row['subject']);
        $this->assertSame('line one line two', announcement_plain_text($row['message']));
    }

    public function testThePostFromTheCalendarKeepsTheComposersRichMessage(): void
    {
        $this->loggedInAs($this->instructor);

        $message = json_encode(['version' => 1, 'blocks' => [
            ['id' => 'block_001', 'type' => 'unordered-list', 'align' => 'left', 'items' => [
                [['type' => 'text', 'text' => 'first']],
                [['type' => 'text', 'text' => 'second']],
            ]],
        ]]);

        $result = $this->withBody(json_encode(['offering_id' => $this->offering, 'subject' => 'Rich', 'message' => $message]))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call('post', 'instructor/api/calendar/announcements');

        $this->assertSame(201, $result->response()->getStatusCode());

        $row = db_connect()->table('announcements')->get()->getRowArray();
        $this->assertSame('Rich', $row['subject']);
        $this->assertSame($message, $row['message']);
    }

    public function testThePostFromTheCalendarLandsOnTheChosenDayAndRefusesThePast(): void
    {
        $this->loggedInAs($this->instructor);

        $post = fn (string $date) => $this->withBody(json_encode(['offering_id' => $this->offering, 'subject' => 'Dated', 'message' => '{}', 'date' => $date]))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call('post', 'instructor/api/calendar/announcements');

        $future = date('Y-m-d', strtotime('+5 days'));

        $this->assertSame(201, $post($future)->response()->getStatusCode());
        $this->assertSame($future, substr(db_connect()->table('announcements')->get()->getRowArray()['created_at'], 0, 10));

        $this->assertSame(422, $post(date('Y-m-d', strtotime('-1 day')))->response()->getStatusCode());
        $this->assertSame(422, $post('not-a-date')->response()->getStatusCode());
        $this->assertSame(1, db_connect()->table('announcements')->countAllResults());
    }

    public function testTheCalendarPostIsRefusedForOfferingsTheInstructorDoesNotTeach(): void
    {
        $other = CourseFactory::offering('B');
        $this->loggedInAs($this->instructor);

        $result = $this->withBody(json_encode(['offering_id' => $other, 'title' => 'Nope', 'body' => 'x']))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call('post', 'instructor/api/calendar/announcements');

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertSame(0, db_connect()->table('announcements')->countAllResults());
    }
}
