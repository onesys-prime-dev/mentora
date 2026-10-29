<?php

declare(strict_types=1);

use App\Services\Courses\DiscussionService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Course discussions: who may start, read, edit, delete, pin, lock, reply to,
 * react to and mark an answer on a thread, what the list shows each person,
 * unread tracking, and how a discussion learning item's thread behaves.
 *
 * @internal
 */
final class DiscussionServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private DiscussionService $service;

    private int $offering;

    private User $instructor;

    private User $author;

    private User $classmate;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DiscussionService();

        $this->offering = CourseFactory::offering();

        $this->instructor = CourseFactory::user('instructor');
        $this->author = CourseFactory::user('student');
        $this->classmate = CourseFactory::user('student');
        $this->outsider = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->author);
        CourseFactory::enroll($this->offering, $this->classmate);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** A thread started by $by (the author unless told otherwise). @return int thread id */
    private function thread(string $title = 'A question', ?User $by = null, string $body = ''): int
    {
        return $this->service->createThread($by ?? $this->author, $this->offering, ['title' => $title, 'body' => $body])['thread_id'];
    }

    /** @return list<int> thread ids in the order $user sees them */
    private function ids(User $user, array $filters = []): array
    {
        return array_map('intval', array_column($this->service->listThreads($user, $this->offering, $filters)['threads'], 'id'));
    }

    private function row(string $table, int $id): array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function replyTo(int $threadId, ?User $by = null, string $text = 'An answer'): int
    {
        return $this->service->reply($by ?? $this->classmate, $this->offering, $threadId, $text)['reply_id'];
    }

    /** A discussion learning item, optionally still a draft, with its thread. @return array{item: int, thread: int} */
    private function discussionItem(string $status = 'published'): array
    {
        $unit = CourseFactory::unit(CourseFactory::module($this->offering));
        [$item] = CourseFactory::items($unit, [['type' => 'discussion', 'status' => $status]]);

        return ['item' => $item, 'thread' => CourseFactory::thread($this->offering, $this->instructor, $item)];
    }

    /*
    |--------------------------------------------------------------------------
    | Who can see the course's discussions
    |--------------------------------------------------------------------------
    */

    /** An author with two roles is still one author: each thread and reply shows once (QA finding F-02, as D-13). */
    public function testAThreadAndAReplyShowOnceWhateverTheirAuthorsRoles(): void
    {
        $this->instructor->addGroup('chairperson');

        $id = $this->thread('From someone with two roles', $this->instructor);
        $this->replyTo($id, $this->instructor);

        $this->assertSame([$id], $this->ids($this->author));

        $thread = $this->service->getThread($this->author, $this->offering, $id);
        $this->assertCount(1, $thread['replies']);
        $this->assertSame('instructor', $thread['thread']['author_role']);
        $this->assertSame('instructor', $thread['replies'][0]['author_role']);
    }

    public function testAStudentWhoIsNotEnrolledCannotReadOrStart(): void
    {
        $id = $this->thread();

        $this->assertSame(['success' => false, 'message' => 'Not authorized.'], $this->service->createThread($this->outsider, $this->offering, ['title' => 'Hi']));

        $this->expectException(PageNotFoundException::class);
        $this->service->listThreads($this->outsider, $this->offering);
    }

    public function testAnOutsiderCannotOpenAThread(): void
    {
        $id = $this->thread();

        $this->expectException(PageNotFoundException::class);
        $this->service->getThread($this->outsider, $this->offering, $id);
    }

    public function testAnInstructorFromAnotherSectionHasNoAccess(): void
    {
        $other = CourseFactory::user('instructor');
        CourseFactory::assignInstructor(CourseFactory::offering('B'), $other);
        $id = $this->thread();

        $this->assertFalse($this->service->createThread($other, $this->offering, ['title' => 'Hi'])['success']);
        $this->assertSame('Only the instructor can pin discussions.', $this->service->setPinned($other, $this->offering, $id, true)['message']);
    }

    public function testAThreadFromAnotherOfferingIsNotFound(): void
    {
        $id = $this->thread();
        $otherOffering = CourseFactory::offering('B');
        $stranger = CourseFactory::user('student');
        CourseFactory::enroll($otherOffering, $stranger);

        $this->expectException(PageNotFoundException::class);
        $this->service->getThread($stranger, $otherOffering, $id);
    }

    /*
    |--------------------------------------------------------------------------
    | Starting, editing and deleting
    |--------------------------------------------------------------------------
    */

    public function testStudentsAndInstructorsCanStartAThread(): void
    {
        foreach ([$this->author, $this->instructor] as $user) {
            $result = $this->service->createThread($user, $this->offering, ['title' => '  Ask  ', 'body' => '{"blocks":[]}']);

            $this->assertTrue($result['success']);
            $row = $this->row('discussion_threads', $result['thread_id']);
            $this->assertSame('Ask', $row['title'], 'title is trimmed');
            $this->assertSame('{"blocks":[]}', $row['body']);
            $this->assertSame((int) $user->id, (int) $row['author_id']);
            $this->assertNotNull($row['last_activity_at']);
        }
    }

    public function testATitleIsRequiredAndCappedAt255Characters(): void
    {
        $this->assertSame('Add a title before posting.', $this->service->createThread($this->author, $this->offering, ['title' => '   '])['message']);
        $this->assertSame('Titles can be at most 255 characters.', $this->service->createThread($this->author, $this->offering, ['title' => str_repeat('a', 256)])['message']);
        $this->assertTrue($this->service->createThread($this->author, $this->offering, ['title' => str_repeat('a', 255)])['success']);
    }

    public function testOnlyTheAuthorCanEditAThread(): void
    {
        $id = $this->thread('Old title', null, 'old');

        $byOther = $this->service->updateThread($this->classmate, $this->offering, $id, ['title' => 'Hijacked']);
        $this->assertSame('You can only edit your own discussion.', $byOther['message']);

        $byInstructor = $this->service->updateThread($this->instructor, $this->offering, $id, ['title' => 'Hijacked']);
        $this->assertSame('You can only edit your own discussion.', $byInstructor['message']);

        $this->assertTrue($this->service->updateThread($this->author, $this->offering, $id, ['title' => 'New title', 'body' => 'new'])['success']);
        $this->assertSame('New title', $this->row('discussion_threads', $id)['title']);
        $this->assertSame('new', $this->row('discussion_threads', $id)['body']);
    }

    public function testAnEditNeedsATitleAndCutsAnOverlongOneTo255(): void
    {
        $id = $this->thread();

        $this->assertSame('Add a title before saving.', $this->service->updateThread($this->author, $this->offering, $id, ['title' => ' '])['message']);

        $this->service->updateThread($this->author, $this->offering, $id, ['title' => str_repeat('b', 300)]);
        $this->assertSame(255, mb_strlen($this->row('discussion_threads', $id)['title']));
    }

    public function testEditingAThreadThatDoesNotExistIsRefused(): void
    {
        $this->assertSame('Discussion not found.', $this->service->updateThread($this->author, $this->offering, 999999, ['title' => 'x'])['message']);
    }

    public function testTheAuthorOrTheInstructorCanDeleteAThreadAndItsReplies(): void
    {
        $mine = $this->thread();
        $reply = $this->replyTo($mine);

        $this->assertTrue($this->service->deleteThread($this->author, $this->offering, $mine)['success']);
        $this->assertNull(db_connect()->table('discussion_threads')->where('id', $mine)->get()->getRowArray());
        $this->assertNull(db_connect()->table('discussion_replies')->where('id', $reply)->get()->getRowArray(), 'replies go with the thread');

        $theirs = $this->thread();
        $this->assertTrue($this->service->deleteThread($this->instructor, $this->offering, $theirs)['success']);
    }

    public function testAnotherStudentCannotDeleteAThread(): void
    {
        $id = $this->thread();

        $result = $this->service->deleteThread($this->classmate, $this->offering, $id);

        $this->assertSame('You can only delete your own discussion.', $result['message']);
        $this->assertNotNull($this->row('discussion_threads', $id));
    }

    /*
    |--------------------------------------------------------------------------
    | Pinning and locking
    |--------------------------------------------------------------------------
    */

    public function testOnlyTheInstructorCanPinAndLock(): void
    {
        $id = $this->thread();

        $this->assertSame('Only the instructor can pin discussions.', $this->service->setPinned($this->author, $this->offering, $id, true)['message']);
        $this->assertSame('Only the instructor can lock discussions.', $this->service->setLocked($this->author, $this->offering, $id, true)['message']);

        $this->assertSame(['success' => true, 'is_pinned' => true], $this->service->setPinned($this->instructor, $this->offering, $id, true));
        $this->assertSame(['success' => true, 'is_locked' => true], $this->service->setLocked($this->instructor, $this->offering, $id, true));
        $this->assertSame(['success' => true, 'is_pinned' => false], $this->service->setPinned($this->instructor, $this->offering, $id, false));
    }

    public function testModeratingAThreadThatDoesNotExistIsRefused(): void
    {
        $this->assertSame('Discussion not found.', $this->service->setPinned($this->instructor, $this->offering, 999999, true)['message']);
    }

    public function testALockedThreadTakesNoRepliesUntilItIsUnlocked(): void
    {
        $id = $this->thread();
        $this->service->setLocked($this->instructor, $this->offering, $id, true);

        $refused = $this->service->reply($this->classmate, $this->offering, $id, 'Hello');
        $this->assertSame('This discussion is locked.', $refused['message']);

        $this->service->setLocked($this->instructor, $this->offering, $id, false);
        $this->assertTrue($this->service->reply($this->classmate, $this->offering, $id, 'Hello')['success']);
    }

    public function testPermissionsAreWorkedOutPerViewer(): void
    {
        $id = $this->thread();

        $asAuthor = $this->service->getThread($this->author, $this->offering, $id)['thread'];
        $this->assertTrue($asAuthor['can_edit']);
        $this->assertTrue($asAuthor['can_delete']);
        $this->assertTrue($asAuthor['can_mark_answer']);
        $this->assertFalse($asAuthor['can_pin']);
        $this->assertFalse($asAuthor['can_lock']);

        $asClassmate = $this->service->getThread($this->classmate, $this->offering, $id)['thread'];
        $this->assertFalse($asClassmate['can_edit']);
        $this->assertFalse($asClassmate['can_delete']);
        $this->assertFalse($asClassmate['can_mark_answer']);

        $asInstructor = $this->service->getThread($this->instructor, $this->offering, $id)['thread'];
        $this->assertFalse($asInstructor['can_edit'], 'the instructor cannot rewrite a student\'s post');
        $this->assertTrue($asInstructor['can_delete']);
        $this->assertTrue($asInstructor['can_pin']);
        $this->assertTrue($asInstructor['can_lock']);
        $this->assertTrue($asInstructor['can_mark_answer']);
    }

    /*
    |--------------------------------------------------------------------------
    | Replies
    |--------------------------------------------------------------------------
    */

    public function testAReplyIsCountedAndRefreshesTheThreadsActivity(): void
    {
        $id = $this->thread();
        $before = $this->row('discussion_threads', $id)['last_activity_at'];
        usleep(2000);

        $result = $this->service->reply($this->classmate, $this->offering, $id, 'My answer');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['reply_count']);
        $this->assertStringContainsString('My answer', $result['html']);
        $this->assertSame(1, (int) $this->row('discussion_threads', $id)['reply_count']);
        $this->assertGreaterThan($before, $this->row('discussion_threads', $id)['last_activity_at']);
    }

    public function testAnEmptyReplyIsRefused(): void
    {
        $id = $this->thread();

        $this->assertSame('Write a reply first.', $this->service->reply($this->classmate, $this->offering, $id, "  \n ")['message']);
        $this->assertSame(0, (int) $this->row('discussion_threads', $id)['reply_count']);
    }

    public function testOnlyPeopleInTheCourseCanReply(): void
    {
        $id = $this->thread();

        $this->assertSame('Discussion not found.', $this->service->reply($this->outsider, $this->offering, $id, 'Hi')['message']);
    }

    public function testOnlyAStudentsReplyOnADiscussionItemCompletesIt(): void
    {
        $linked = $this->discussionItem()['thread'];
        $general = $this->thread();

        $this->assertTrue($this->service->reply($this->classmate, $this->offering, $linked, 'Hi')['completes_item']);
        $this->assertFalse($this->service->reply($this->instructor, $this->offering, $linked, 'Welcome')['completes_item']);
        $this->assertFalse($this->service->reply($this->classmate, $this->offering, $general, 'Hi')['completes_item']);
    }

    public function testTheAuthorOrTheInstructorCanDeleteAReplyAndTheCountGoesDown(): void
    {
        $id = $this->thread();
        $first = $this->replyTo($id, $this->classmate, 'one');
        $second = $this->replyTo($id, $this->classmate, 'two');

        $byAuthor = $this->service->deleteReply($this->classmate, $this->offering, $first);
        $this->assertSame(['success' => true, 'reply_count' => 1], $byAuthor);

        $byInstructor = $this->service->deleteReply($this->instructor, $this->offering, $second);
        $this->assertSame(0, $byInstructor['reply_count']);
    }

    public function testAnotherStudentCannotDeleteSomeoneElsesReply(): void
    {
        $id = $this->thread();
        $reply = $this->replyTo($id, $this->classmate);

        $result = $this->service->deleteReply($this->author, $this->offering, $reply);

        $this->assertSame('You can only delete your own reply.', $result['message']);
        $this->assertNotNull($this->row('discussion_replies', $reply));
    }

    public function testDeletingAReplyThatDoesNotExistIsRefused(): void
    {
        $this->assertSame('Reply not found.', $this->service->deleteReply($this->author, $this->offering, 999999)['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Marking an answer
    |--------------------------------------------------------------------------
    */

    public function testTheAuthorOrInstructorCanMarkAndUnmarkAnAnswer(): void
    {
        $id = $this->thread();
        $reply = $this->replyTo($id);

        $this->assertSame(['success' => true, 'answer_reply_id' => $reply], $this->service->markAnswer($this->author, $this->offering, $id, $reply));
        $this->assertSame($reply, (int) $this->row('discussion_threads', $id)['answer_reply_id']);

        $this->assertSame(['success' => true, 'answer_reply_id' => null], $this->service->markAnswer($this->instructor, $this->offering, $id, $reply), 'marking it again clears it');
        $this->assertNull($this->row('discussion_threads', $id)['answer_reply_id']);
    }

    public function testOtherStudentsCannotMarkAnAnswer(): void
    {
        $id = $this->thread();
        $reply = $this->replyTo($id);

        $result = $this->service->markAnswer($this->classmate, $this->offering, $id, $reply);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('author or the instructor', $result['message']);
    }

    public function testAReplyFromAnotherThreadCannotBeMarkedAsTheAnswer(): void
    {
        $first = $this->thread('First');
        $second = $this->thread('Second');
        $foreign = $this->replyTo($second);

        $this->assertSame('Reply not found.', $this->service->markAnswer($this->author, $this->offering, $first, $foreign)['message']);
    }

    public function testDeletingTheAnswerClearsItOnTheThread(): void
    {
        $id = $this->thread();
        $reply = $this->replyTo($id);
        $this->service->markAnswer($this->author, $this->offering, $id, $reply);

        $this->service->deleteReply($this->instructor, $this->offering, $reply);

        $this->assertNull($this->row('discussion_threads', $id)['answer_reply_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    */

    public function testReactingTogglesAddsChangesAndRemoves(): void
    {
        $id = $this->thread();

        $added = $this->service->react($this->classmate, $this->offering, $id, null, '👍');
        $this->assertSame('added', $added['action']);
        $this->assertSame(['👍' => 1], $added['reaction_counts']);

        $changed = $this->service->react($this->classmate, $this->offering, $id, null, '❤️');
        $this->assertSame('changed', $changed['action']);
        $this->assertSame(['❤️' => 1], $changed['reaction_counts'], 'one reaction per person, so the old one is replaced');

        $removed = $this->service->react($this->classmate, $this->offering, $id, null, '❤️');
        $this->assertSame('removed', $removed['action']);
        $this->assertNull($removed['emoji']);
        $this->assertSame([], $removed['reaction_counts']);
    }

    public function testReactionsAreCountedAcrossPeopleAndKeptApartFromReplies(): void
    {
        $id = $this->thread();
        $reply = $this->replyTo($id);

        $this->service->react($this->author, $this->offering, $id, null, '👍');
        $this->service->react($this->classmate, $this->offering, $id, null, '👍');
        $onReply = $this->service->react($this->author, $this->offering, $id, $reply, '😮');

        $this->assertSame(['😮' => 1], $onReply['reaction_counts'], 'a reply has its own count');

        $thread = $this->service->getThread($this->author, $this->offering, $id);
        $this->assertSame(['👍' => 2], $thread['thread']['reaction_counts']);
        $this->assertSame('👍', $thread['thread']['own_reaction']);
        $this->assertSame(['😮' => 1], $thread['replies'][0]['reaction_counts']);
    }

    public function testInvalidReactionsAreRefused(): void
    {
        $id = $this->thread();
        $otherThreadReply = $this->replyTo($this->thread('Other'));

        $this->assertSame('Pick a reaction.', $this->service->react($this->author, $this->offering, $id, null, '  ')['message']);
        $this->assertSame('Pick a reaction.', $this->service->react($this->author, $this->offering, $id, null, str_repeat('x', 33))['message']);
        $this->assertSame('Reply not found.', $this->service->react($this->author, $this->offering, $id, $otherThreadReply, '👍')['message']);
        $this->assertSame('Discussion not found.', $this->service->react($this->outsider, $this->offering, $id, null, '👍')['message']);
    }

    /** A discussion offers four reactions and no picker, so only those four are taken (QA finding F-05, as D-20). */
    public function testOnlyTheFourOfferedReactionsAreTaken(): void
    {
        $id = $this->thread();

        foreach (['abc', 'hello', '🎉', '👍👍', 'like'] as $bad) {
            $this->assertSame('Pick a reaction.', $this->service->react($this->author, $this->offering, $id, null, $bad)['message'], $bad);
        }

        $this->assertSame(0, db_connect()->table('discussion_reactions')->countAllResults());

        foreach (['👍', '❤️', '😂', '😮'] as $good) {
            $this->assertTrue($this->service->react($this->classmate, $this->offering, $id, null, $good)['success'], $good);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The list: order, search, filters, paging
    |--------------------------------------------------------------------------
    */

    public function testPinnedThreadsComeFirstThenTheMostRecentlyActive(): void
    {
        $old = $this->thread('Old');
        usleep(2000);
        $newer = $this->thread('Newer');
        usleep(2000);
        $pinned = $this->thread('Pinned');
        $this->service->setPinned($this->instructor, $this->offering, $pinned, true);
        usleep(2000);
        $this->replyTo($old);

        $this->assertSame([$pinned, $old, $newer], $this->ids($this->author));
    }

    public function testSortByNewestAndByMostReplies(): void
    {
        $first = $this->thread('First');
        usleep(2000);
        $second = $this->thread('Second');
        $this->replyTo($first);
        $this->replyTo($first);

        $this->assertSame([$second, $first], $this->ids($this->author, ['sort' => 'newest']));
        $this->assertSame([$first, $second], $this->ids($this->author, ['sort' => 'replies']));
    }

    public function testSearchLooksInTitlesAndBodies(): void
    {
        $inTitle = $this->thread('About recursion');
        $inBody = $this->thread('Homework', null, 'stuck on recursion again');
        $this->thread('Unrelated');

        $found = $this->ids($this->author, ['search' => ' recursion ']);

        $this->assertEqualsCanonicalizing([$inTitle, $inBody], $found);
        $this->assertSame([], $this->ids($this->author, ['search' => 'zzzz']));
    }

    public function testTheFiltersNarrowTheList(): void
    {
        $mine = $this->thread('Mine');
        $theirs = $this->thread('Theirs', $this->classmate);
        $pinned = $this->thread('Pinned', $this->classmate);
        $this->service->setPinned($this->instructor, $this->offering, $pinned, true);
        $answered = $this->thread('Answered', $this->classmate);
        $answer = $this->replyTo($answered, $this->author);
        $this->service->markAnswer($this->classmate, $this->offering, $answered, $answer);
        $linked = $this->discussionItem()['thread'];

        $this->assertSame([$pinned], $this->ids($this->author, ['filter' => 'pinned']));
        $this->assertSame([$mine], $this->ids($this->author, ['filter' => 'mine']));
        $this->assertSame([$linked], $this->ids($this->author, ['filter' => 'modules']));
        $unanswered = $this->ids($this->author, ['filter' => 'unanswered']);
        $this->assertNotContains($answered, $unanswered);
        $this->assertContains($theirs, $unanswered);
    }

    public function testAnUnknownFilterOrSortFallsBackToTheDefaults(): void
    {
        $id = $this->thread();

        $this->assertSame([$id], $this->ids($this->author, ['filter' => 'bogus', 'sort' => 'bogus']));
    }

    public function testTheListPagesTenAtATime(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->thread("Thread {$i}");
        }

        $first = $this->service->listThreads($this->author, $this->offering, ['sort' => 'newest']);
        $second = $this->service->listThreads($this->author, $this->offering, ['sort' => 'newest', 'page' => 2]);

        $this->assertCount(10, $first['threads']);
        $this->assertTrue($first['has_more']);
        $this->assertCount(2, $second['threads']);
        $this->assertFalse($second['has_more']);
        $this->assertSame(2, $second['page']);
    }

    public function testEachRowCarriesThePermissionsOfTheViewer(): void
    {
        $this->thread();

        $asStudent = $this->service->listThreads($this->author, $this->offering)['threads'][0];
        $asInstructor = $this->service->listThreads($this->instructor, $this->offering)['threads'][0];

        $this->assertFalse($asStudent['can_pin']);
        $this->assertTrue($asInstructor['can_pin']);
        $this->assertTrue($this->service->listThreads($this->instructor, $this->offering)['is_instructor']);
    }

    /*
    |--------------------------------------------------------------------------
    | A discussion learning item's thread
    |--------------------------------------------------------------------------
    */

    public function testStudentsDoNotSeeTheThreadOfADraftItemButInstructorsDo(): void
    {
        $draft = $this->discussionItem('draft')['thread'];
        $published = $this->discussionItem('published')['thread'];

        $this->assertSame([$published], $this->ids($this->author));
        $this->assertEqualsCanonicalizing([$draft, $published], $this->ids($this->instructor));
    }

    public function testStudentsCannotOpenTheThreadOfADraftItem(): void
    {
        $draft = $this->discussionItem('draft')['thread'];

        $this->assertNotNull($this->service->getThread($this->instructor, $this->offering, $draft));

        $this->expectException(PageNotFoundException::class);
        $this->service->getThread($this->author, $this->offering, $draft);
    }

    /*
    |--------------------------------------------------------------------------
    | Unread
    |--------------------------------------------------------------------------
    */

    private function unread(User $user, int $threadId): bool
    {
        foreach ($this->service->listThreads($user, $this->offering)['threads'] as $row) {
            if ((int) $row['id'] === $threadId) {
                return (bool) $row['is_unread'];
            }
        }

        $this->fail("Thread {$threadId} is not in the list");
    }

    public function testOpeningAThreadMarksItReadAndANewReplyMakesItUnreadAgain(): void
    {
        $id = $this->thread();

        $this->assertTrue($this->unread($this->classmate, $id), 'never opened');

        $this->service->getThread($this->classmate, $this->offering, $id);
        $this->assertFalse($this->unread($this->classmate, $id));

        usleep(2000);
        $this->replyTo($id, $this->author, 'More news');

        $this->assertTrue($this->unread($this->classmate, $id), 'new activity since they last looked');
        $this->assertFalse($this->unread($this->author, $id), 'the replier has seen their own reply');
    }

    public function testTheUnreadFilterListsOnlyWhatTheViewerHasNotSeen(): void
    {
        $seen = $this->thread('Seen');
        $unseen = $this->thread('Unseen');

        $this->service->getThread($this->classmate, $this->offering, $seen);

        $this->assertSame([$unseen], $this->ids($this->classmate, ['filter' => 'unread']));
    }

    /*
    |--------------------------------------------------------------------------
    | Screens
    |--------------------------------------------------------------------------
    */

    public function testTheScreensRender(): void
    {
        $id = $this->thread('Rendering check');
        $this->replyTo($id, $this->classmate, 'A reply to render');

        $list = $this->service->show($this->author, $this->offering);
        $this->assertStringContainsString('Rendering check', $list['html']);

        $thread = $this->service->showThread($this->author, $this->offering, $id);
        $this->assertStringContainsString('A reply to render', $thread['html']);

        $more = $this->service->loadMore($this->author, $this->offering);
        $this->assertTrue($more['success']);
        $this->assertStringContainsString('Rendering check', $more['html']);
    }
}
