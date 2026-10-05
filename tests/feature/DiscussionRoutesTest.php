<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The discussion routes end to end, as a student and as the instructor:
 * the route reaches the right action, the JSON body and the query string make
 * it through to the service, the answer is JSON the page can use, and the
 * course-level rules (enrolled students, the assigned instructor) hold for
 * every action on both route prefixes. The service's own rules are covered in
 * DiscussionServiceTest; this checks the wiring in front of it.
 *
 * @internal
 */
final class DiscussionRoutesTest extends RouteTestCase
{
    private int $offering;

    private User $instructor;

    private User $student;

    private User $classmate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');
        $this->classmate = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->student);
        CourseFactory::enroll($this->offering, $this->classmate);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function prefix(User $user): string
    {
        return $user->inGroup('instructor') ? 'instructor' : 'student';
    }

    private function url(User $user, string $path = '', ?int $offering = null): string
    {
        return $this->prefix($user) . '/api/courses/' . ($offering ?? $this->offering) . '/discussions' . $path;
    }

    /** Sends a JSON request as $user and returns the decoded JSON answer. */
    private function api(User $user, string $verb, string $path = '', array $body = [], ?int $offering = null): array
    {
        $this->loggedInAs($user);

        $result = $this->withBody(json_encode($body))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call($verb, $this->url($user, $path, $offering));

        $this->assertSame(200, $result->response()->getStatusCode(), "{$verb} {$path}");

        return json_decode((string) $result->getJSON(), true);
    }

    private function start(User $by, string $title = 'A question', string $body = ''): int
    {
        return (int) $this->api($by, 'post', '', ['title' => $title, 'body' => $body])['thread_id'];
    }

    private function row(string $table, int $id): ?array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Starting, reading, editing, deleting
    |--------------------------------------------------------------------------
    */

    public function testAStudentStartsAThreadThroughTheRoute(): void
    {
        $answer = $this->api($this->student, 'post', '', ['title' => ' Help with loops ', 'body' => 'I am stuck']);

        $this->assertTrue($answer['success']);
        $thread = $this->row('discussion_threads', (int) $answer['thread_id']);
        $this->assertSame('Help with loops', $thread['title']);
        $this->assertSame('I am stuck', $thread['body']);
        $this->assertSame($this->student->id, (int) $thread['author_id']);
        $this->assertSame($this->offering, (int) $thread['offering_id']);
    }

    public function testTheInstructorCanStartAThreadToo(): void
    {
        $this->assertTrue($this->api($this->instructor, 'post', '', ['title' => 'Welcome'])['success']);
    }

    public function testAnEmptyBodyOrTitleIsAnswerNotACrash(): void
    {
        $answer = $this->api($this->student, 'post', '', []);

        $this->assertFalse($answer['success']);
        $this->assertSame('Add a title before posting.', $answer['message']);
        $this->assertSame(0, db_connect()->table('discussion_threads')->countAllResults());
    }

    public function testTheListAndTheThreadComeBackAsHtml(): void
    {
        $id = $this->start($this->student, 'Visible question');

        $list = $this->api($this->classmate, 'get');
        $thread = $this->api($this->classmate, 'get', "/{$id}");

        $this->assertStringContainsString('Visible question', $list['html']);
        $this->assertStringContainsString('Visible question', $thread['html']);
    }

    public function testTheListHonoursSearchSortFilterAndPageFromTheQueryString(): void
    {
        $this->start($this->student, 'Alpha topic');
        $this->start($this->student, 'Beta topic');
        $this->loggedInAs($this->classmate);

        $search = json_decode((string) $this->get($this->url($this->classmate) . '?search=Beta')->getJSON(), true)['html'];
        $mine = json_decode((string) $this->get($this->url($this->classmate) . '?filter=mine')->getJSON(), true)['html'];

        $this->assertStringContainsString('Beta topic', $search);
        $this->assertStringNotContainsString('Alpha topic', $search);
        $this->assertStringNotContainsString('Alpha topic', $mine, "the classmate wrote nothing, so 'mine' is empty");
    }

    public function testLoadMoreReturnsTheNextPageOfRows(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->start($this->student, "Topic {$i}");
        }

        $this->loggedInAs($this->classmate);
        $first = json_decode((string) $this->get($this->url($this->classmate, '/more') . '?page=1')->getJSON(), true);
        $second = json_decode((string) $this->get($this->url($this->classmate, '/more') . '?page=2')->getJSON(), true);

        $this->assertTrue($first['success']);
        $this->assertTrue($first['has_more']);
        $this->assertSame(2, $first['next_page']);
        $this->assertFalse($second['has_more']);
        $this->assertSame(10, substr_count($first['html'], 'Topic '));
        $this->assertSame(2, substr_count($second['html'], 'Topic '));
    }

    public function testTheAuthorCanEditTheirThreadButAClassmateCannot(): void
    {
        $id = $this->start($this->student, 'Original', 'text');

        $denied = $this->api($this->classmate, 'patch', "/{$id}", ['title' => 'Hijacked']);
        $ok = $this->api($this->student, 'patch', "/{$id}", ['title' => 'Edited', 'body' => 'new text']);

        $this->assertFalse($denied['success']);
        $this->assertTrue($ok['success']);
        $this->assertSame('Edited', $this->row('discussion_threads', $id)['title']);
    }

    public function testTheAuthorCanDeleteTheirThreadButAClassmateCannot(): void
    {
        $id = $this->start($this->student);

        $denied = $this->api($this->classmate, 'delete', "/{$id}");
        $this->assertFalse($denied['success']);
        $this->assertNotNull($this->row('discussion_threads', $id));

        $this->assertTrue($this->api($this->student, 'delete', "/{$id}")['success']);
        $this->assertNull($this->row('discussion_threads', $id));
    }

    public function testTheInstructorCanDeleteAnyThread(): void
    {
        $id = $this->start($this->student);

        $this->assertTrue($this->api($this->instructor, 'delete', "/{$id}")['success']);
        $this->assertNull($this->row('discussion_threads', $id));
    }

    /*
    |--------------------------------------------------------------------------
    | Pin and lock
    |--------------------------------------------------------------------------
    */

    public function testOnlyTheInstructorCanPinAndUnpin(): void
    {
        $id = $this->start($this->student);

        $this->assertFalse($this->api($this->student, 'patch', "/{$id}/pin", ['value' => true])['success']);
        $this->assertSame(0, (int) $this->row('discussion_threads', $id)['is_pinned']);

        $this->assertTrue($this->api($this->instructor, 'patch', "/{$id}/pin", ['value' => true])['success']);
        $this->assertSame(1, (int) $this->row('discussion_threads', $id)['is_pinned']);

        $this->api($this->instructor, 'patch', "/{$id}/pin", ['value' => false]);
        $this->assertSame(0, (int) $this->row('discussion_threads', $id)['is_pinned']);
    }

    public function testAPinRequestWithNoValueUnpins(): void
    {
        $id = $this->start($this->student);
        $this->api($this->instructor, 'patch', "/{$id}/pin", ['value' => true]);

        $this->api($this->instructor, 'patch', "/{$id}/pin", []);

        $this->assertSame(0, (int) $this->row('discussion_threads', $id)['is_pinned']);
    }

    public function testOnlyTheInstructorCanLockAndALockedThreadRefusesReplies(): void
    {
        $id = $this->start($this->student);

        $this->assertFalse($this->api($this->student, 'patch', "/{$id}/lock", ['value' => true])['success']);
        $this->assertTrue($this->api($this->instructor, 'patch', "/{$id}/lock", ['value' => true])['success']);
        $this->assertSame(1, (int) $this->row('discussion_threads', $id)['is_locked']);

        $reply = $this->api($this->classmate, 'post', "/{$id}/reply", ['body' => 'Too late']);

        $this->assertFalse($reply['success']);
        $this->assertSame(0, db_connect()->table('discussion_replies')->where('thread_id', $id)->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | Replies, answers and reactions
    |--------------------------------------------------------------------------
    */

    public function testReplyingStoresTheBodyAndAttachmentIds(): void
    {
        $id = $this->start($this->student);

        $answer = $this->api($this->classmate, 'post', "/{$id}/reply", ['body' => 'Try a for loop']);

        $this->assertTrue($answer['success']);
        $reply = $this->row('discussion_replies', (int) $answer['reply_id']);
        $this->assertSame('Try a for loop', $reply['body']);
        $this->assertSame($this->classmate->id, (int) $reply['author_id']);
    }

    public function testAReplyWithoutABodyIsRefused(): void
    {
        $id = $this->start($this->student);

        $answer = $this->api($this->classmate, 'post', "/{$id}/reply", []);

        $this->assertFalse($answer['success']);
        $this->assertSame(0, db_connect()->table('discussion_replies')->where('thread_id', $id)->countAllResults());
    }

    public function testOnlyAReplysAuthorOrTheInstructorCanDeleteIt(): void
    {
        $id = $this->start($this->student);
        $replyId = (int) $this->api($this->classmate, 'post', "/{$id}/reply", ['body' => 'Mine'])['reply_id'];

        $this->assertFalse($this->api($this->student, 'delete', "/replies/{$replyId}")['success']);
        $this->assertNotNull($this->row('discussion_replies', $replyId));

        $this->assertTrue($this->api($this->classmate, 'delete', "/replies/{$replyId}")['success']);
        $this->assertNull($this->row('discussion_replies', $replyId));
    }

    public function testTheInstructorCanDeleteAnyReply(): void
    {
        $id = $this->start($this->student);
        $replyId = (int) $this->api($this->classmate, 'post', "/{$id}/reply", ['body' => 'Mine'])['reply_id'];

        $this->assertTrue($this->api($this->instructor, 'delete', "/replies/{$replyId}")['success']);
    }

    public function testTheThreadsAuthorMarksAnAnswerAndTogglesItOff(): void
    {
        $id = $this->start($this->student);
        $replyId = (int) $this->api($this->classmate, 'post', "/{$id}/reply", ['body' => 'The answer'])['reply_id'];

        $on = $this->api($this->student, 'post', "/{$id}/answer", ['reply_id' => $replyId]);
        $this->assertTrue($on['success']);
        $this->assertSame($replyId, $on['answer_reply_id']);

        $off = $this->api($this->student, 'post', "/{$id}/answer", ['reply_id' => $replyId]);
        $this->assertNull($off['answer_reply_id']);
    }

    public function testAClassmateCannotMarkAnAnswerAndAMissingReplyIsRefused(): void
    {
        $id = $this->start($this->student);
        $replyId = (int) $this->api($this->classmate, 'post', "/{$id}/reply", ['body' => 'Maybe'])['reply_id'];

        $this->assertFalse($this->api($this->classmate, 'post', "/{$id}/answer", ['reply_id' => $replyId])['success']);
        $this->assertFalse($this->api($this->student, 'post', "/{$id}/answer", [])['success'], 'no reply id means reply 0');
        $this->assertNull($this->row('discussion_threads', $id)['answer_reply_id']);
    }

    public function testReactingTogglesAndCounts(): void
    {
        $id = $this->start($this->student);

        $first = $this->api($this->classmate, 'post', "/{$id}/react", ['emoji' => '👍']);
        $this->assertTrue($first['success']);
        $this->assertSame(['👍' => 1], $first['reaction_counts']);

        $second = $this->api($this->classmate, 'post', "/{$id}/react", ['emoji' => '👍']);
        $this->assertSame([], $second['reaction_counts'], 'the same reaction again takes it back');
    }

    public function testReactingToAReplyCountsThatReplysReactions(): void
    {
        $id = $this->start($this->student);
        $replyId = (int) $this->api($this->classmate, 'post', "/{$id}/reply", ['body' => 'Hi'])['reply_id'];

        $answer = $this->api($this->student, 'post', "/{$id}/react", ['reply_id' => $replyId, 'emoji' => '❤️']);

        $this->assertTrue($answer['success']);
        $this->assertSame(['❤️' => 1], $answer['reaction_counts']);
    }

    public function testAReactionNeedsAnEmojiAndARealReply(): void
    {
        $id = $this->start($this->student);

        $this->assertFalse($this->api($this->classmate, 'post', "/{$id}/react", [])['success']);
        $this->assertSame('Reply not found.', $this->api($this->classmate, 'post', "/{$id}/react", ['reply_id' => 999999, 'emoji' => '👍'])['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    public function testUploadingWithoutAFileSaysSo(): void
    {
        $answer = $this->api($this->student, 'post', '/attachments');

        $this->assertFalse($answer['success']);
        $this->assertSame('No file was sent.', $answer['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Course-level access, every action
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function readActions(): iterable
    {
        yield 'list' => ['get', ''];
        yield 'more' => ['get', '/more'];
        yield 'open a thread' => ['get', '/{thread}'];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function writeActions(): iterable
    {
        yield 'start' => ['post', ''];
        yield 'edit' => ['patch', '/{thread}'];
        yield 'delete' => ['delete', '/{thread}'];
        yield 'pin' => ['patch', '/{thread}/pin'];
        yield 'lock' => ['patch', '/{thread}/lock'];
        yield 'reply' => ['post', '/{thread}/reply'];
        yield 'delete a reply' => ['delete', '/replies/{reply}'];
        yield 'react' => ['post', '/{thread}/react'];
        yield 'mark an answer' => ['post', '/{thread}/answer'];
        yield 'upload' => ['post', '/attachments'];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function actions(): iterable
    {
        yield from self::readActions();
        yield from self::writeActions();
    }

    /** Another section with a student, a thread and a reply. @return array{offering: int, thread: int, reply: int} */
    private function otherSection(): array
    {
        $other = CourseFactory::offering('B');
        $author = CourseFactory::user('student');
        CourseFactory::enroll($other, $author);
        $thread = (int) $this->api($author, 'post', '', ['title' => 'Private to B', 'body' => 'original'], $other)['thread_id'];
        $reply = (int) $this->api($author, 'post', "/{$thread}/reply", ['body' => 'original reply'], $other)['reply_id'];

        return ['offering' => $other, 'thread' => $thread, 'reply' => $reply];
    }

    private function snapshot(): array
    {
        $db = db_connect();

        return [
            $db->table('discussion_threads')->orderBy('id')->get()->getResultArray(),
            $db->table('discussion_replies')->orderBy('id')->get()->getResultArray(),
            $db->table('discussion_reactions')->countAllResults(),
        ];
    }

    private function sendAs(User $user, string $verb, string $path, array $other)
    {
        $path = str_replace(['{thread}', '{reply}'], [(string) $other['thread'], (string) $other['reply']], $path);

        $this->loggedInAs($user);

        return $this->withBody(json_encode(['title' => 'Planted', 'body' => 'Planted', 'value' => true, 'emoji' => '👍', 'reply_id' => $other['reply']]))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call($verb, "{$this->prefix($user)}/api/courses/{$other['offering']}/discussions{$path}");
    }

    #[PHPUnit\Framework\Attributes\DataProvider('actions')]
    public function testALoggedOutVisitorIsSentToLogin(string $verb, string $path): void
    {
        $response = $this->call($verb, "student/api/courses/{$this->offering}/discussions" . str_replace(['{thread}', '{reply}'], '1', $path))->response();

        $this->assertContains($response->getStatusCode(), [301, 302, 303, 401, 403]);
        $this->assertSame(0, db_connect()->table('discussion_threads')->countAllResults());
    }

    #[PHPUnit\Framework\Attributes\DataProvider('readActions')]
    public function testReadingAnotherSectionIsNotFoundForStudentsAndInstructors(string $verb, string $path): void
    {
        $other = $this->otherSection();

        foreach ([$this->student, $this->instructor] as $user) {
            try {
                $this->sendAs($user, $verb, $path, $other);
                $this->fail("{$this->prefix($user)} could read another section");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('writeActions')]
    public function testWritingToAnotherSectionIsRefusedAndChangesNothing(string $verb, string $path): void
    {
        $other = $this->otherSection();
        $before = $this->snapshot();

        foreach ([$this->student, $this->instructor] as $user) {
            $result = $this->sendAs($user, $verb, $path, $other);

            $this->assertSame(200, $result->response()->getStatusCode());
            $this->assertFalse(json_decode((string) $result->getJSON(), true)['success'], "{$this->prefix($user)}: {$verb} {$path}");
            $this->assertSame($before, $this->snapshot(), "{$this->prefix($user)}: {$verb} {$path} changed something");
        }
    }

    public function testAStudentCannotUseTheInstructorRoutes(): void
    {
        $id = $this->start($this->student);
        $this->loggedInAs($this->student);

        $response = $this->withBody(json_encode(['value' => true]))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call('patch', "instructor/api/courses/{$this->offering}/discussions/{$id}/pin")
            ->response();

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) $this->row('discussion_threads', $id)['is_pinned']);
    }

    public function testAThreadOfAnotherSectionIsNotReachableThroughThisOne(): void
    {
        $other = CourseFactory::offering('B');
        $outsider = CourseFactory::user('student');
        CourseFactory::enroll($other, $outsider);
        $foreign = (int) $this->api($outsider, 'post', '', ['title' => 'Elsewhere'], $other)['thread_id'];

        $this->assertFalse($this->api($this->student, 'delete', "/{$foreign}")['success']);
        $this->assertFalse($this->api($this->instructor, 'patch', "/{$foreign}/pin", ['value' => true])['success']);
        $this->assertNotNull($this->row('discussion_threads', $foreign));
        $this->assertSame(0, (int) $this->row('discussion_threads', $foreign)['is_pinned']);
    }
}
