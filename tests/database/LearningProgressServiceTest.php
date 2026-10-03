<?php

declare(strict_types=1);

use App\Services\Courses\LearningProgressService;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * How a student moves through a module: prerequisite locks, the completion
 * rule for each kind of item, video progress, and the instructor's dependency
 * settings. Every test runs in its own rolled-back transaction.
 *
 * @internal
 */
final class LearningProgressServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private LearningProgressService $service;

    private int $offeringId;

    private int $moduleId;

    private int $unitId;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LearningProgressService();

        $this->offeringId = CourseFactory::offering();
        $this->student = CourseFactory::user('student');
        $this->instructor = CourseFactory::user('instructor');

        CourseFactory::enroll($this->offeringId, $this->student);
        CourseFactory::assignInstructor($this->offeringId, $this->instructor);

        $this->moduleId = CourseFactory::module($this->offeringId);
        $this->unitId = CourseFactory::unit($this->moduleId);
    }

    /**
     * Items in this test's unit.
     *
     * @param list<array{title?: string, type?: string, status?: string, mode?: string}> $specs
     *
     * @return list<int>
     */
    private function items(array $specs): array
    {
        return CourseFactory::items($this->unitId, $specs);
    }

    /*
    |--------------------------------------------------------------------------
    | Locks
    |--------------------------------------------------------------------------
    */

    public function testAnItemWithoutPrerequisitesIsNeverLocked(): void
    {
        [, $second] = $this->items([[], []]);

        $this->assertNull($this->service->lockFor($this->student, $second));
    }

    public function testPreviousModeLocksUntilThePreviousItemIsCompleted(): void
    {
        [$first, $second] = $this->items([['title' => 'Intro'], ['mode' => 'previous']]);

        $lock = $this->service->lockFor($this->student, $second);

        $this->assertNotNull($lock);
        $this->assertSame([['id' => $first, 'title' => 'Intro']], $lock['prerequisites']);
        $this->assertSame('Complete “Intro” first.', $lock['message']);

        CourseFactory::progress($first, $this->student);

        $this->assertNull($this->service->lockFor($this->student, $second));
    }

    public function testStartedButUnfinishedDoesNotUnlock(): void
    {
        [$first, $second] = $this->items([[], ['mode' => 'previous']]);

        CourseFactory::progress($first, $this->student, 'in_progress', 60);

        $this->assertNotNull($this->service->lockFor($this->student, $second));
    }

    public function testTheFirstItemIsNotLockedEvenInPreviousMode(): void
    {
        [$first] = $this->items([['mode' => 'previous'], []]);

        $this->assertNull($this->service->lockFor($this->student, $first));
    }

    public function testCustomModeNeedsEveryListedPrerequisite(): void
    {
        [$a, $b, $c] = $this->items([['title' => 'A'], ['title' => 'B'], ['title' => 'C', 'mode' => 'custom']]);
        CourseFactory::requires($c, $a, $b);

        $lock = $this->service->lockFor($this->student, $c);
        $this->assertSame([$a, $b], array_column($lock['prerequisites'], 'id'));
        $this->assertSame('Complete “A” and “B” first.', $lock['message']);

        CourseFactory::progress($a, $this->student);

        $lock = $this->service->lockFor($this->student, $c);
        $this->assertSame([$b], array_column($lock['prerequisites'], 'id'));
        $this->assertSame('Complete “B” first.', $lock['message']);

        CourseFactory::progress($b, $this->student);

        $this->assertNull($this->service->lockFor($this->student, $c));
    }

    public function testACustomPrerequisiteThatComesLaterInTheUnitIsIgnored(): void
    {
        [$a, $b] = $this->items([['mode' => 'custom'], []]);
        CourseFactory::requires($a, $b);

        $this->assertNull($this->service->lockFor($this->student, $a));
    }

    public function testADraftItemIsSkippedAsAPrerequisite(): void
    {
        [$a, , $c] = $this->items([[], ['status' => 'draft'], ['mode' => 'previous']]);

        $lock = $this->service->lockFor($this->student, $c);

        $this->assertSame([$a], array_column($lock['prerequisites'], 'id'));
    }

    public function testAnUnpublishedItemHasNoLock(): void
    {
        [, $draft] = $this->items([[], ['status' => 'draft', 'mode' => 'previous']]);

        $this->assertNull($this->service->lockFor($this->student, $draft));
    }

    public function testInstructorsAreNeverLocked(): void
    {
        [, $second] = $this->items([[], ['mode' => 'previous']]);

        $this->assertNotNull($this->service->lockFor($this->student, $second));
        $this->assertNull($this->service->lockFor($this->instructor, $second));
    }

    public function testOneStudentsProgressDoesNotUnlockForAnother(): void
    {
        $other = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $other);

        [$first, $second] = $this->items([[], ['mode' => 'previous']]);

        CourseFactory::progress($first, $other);

        $this->assertNull($this->service->lockFor($other, $second));
        $this->assertNotNull($this->service->lockFor($this->student, $second));
    }

    /*
    |--------------------------------------------------------------------------
    | locksFor must agree with lockFor
    |--------------------------------------------------------------------------
    */

    public function testLocksForMatchesLockForForEveryItem(): void
    {
        [$a, $b, $c] = $this->items([[], ['mode' => 'previous'], ['mode' => 'custom']]);
        CourseFactory::requires($c, $a, $b);

        $otherUnit = CourseFactory::unit($this->moduleId, 'published', 2);
        [$d, $e] = CourseFactory::items($otherUnit, [[], ['mode' => 'previous']]);

        CourseFactory::progress($a, $this->student);

        $ids = [$a, $b, $c, $d, $e];

        foreach ([$this->student, $this->instructor] as $user) {
            $batch = $this->service->locksFor($user, $ids);

            foreach ($ids as $id) {
                $this->assertSame(
                    $this->service->lockFor($user, $id),
                    $batch[$id],
                    "locksFor and lockFor disagree for item {$id}"
                );
            }
        }

        $this->assertNull($this->service->locksFor($this->student, $ids)[$b], 'B is open once A is done');
        $this->assertNotNull($this->service->locksFor($this->student, $ids)[$c], 'C still waits on B');
        $this->assertNotNull($this->service->locksFor($this->student, $ids)[$e], 'E waits on D');
    }

    public function testLocksForWithNothingToLookUpReturnsNothing(): void
    {
        $this->assertSame([], $this->service->locksFor($this->student, []));
    }

    public function testLocksForGivesAnUnknownItemNoLock(): void
    {
        $this->assertSame([999999 => null], $this->service->locksFor($this->student, [999999]));
    }

    /*
    |--------------------------------------------------------------------------
    | Marking complete
    |--------------------------------------------------------------------------
    */

    public function testAReadingItemCompletesImmediately(): void
    {
        [$item] = $this->items([[]]);

        $result = $this->service->markComplete($this->student, $this->offeringId, $item);

        $this->assertTrue($result['success']);
        $this->assertSame('Marked as completed.', $result['message']);
        $this->assertSame($item, $result['itemId']);
        $this->assertSame(['progress' => 100, 'completed_items' => 1, 'total_items' => 1], $result['module']);
    }

    public function testCompletingTwiceKeepsOneRecord(): void
    {
        [$item] = $this->items([[]]);

        $this->service->markComplete($this->student, $this->offeringId, $item);
        $second = $this->service->markComplete($this->student, $this->offeringId, $item);

        $this->assertTrue($second['success']);
        $this->assertSame(1, db_connect()->table('learning_completions')->where('learning_item_id', $item)->countAllResults());
    }

    public function testModuleProgressCountsOnlyPublishedItems(): void
    {
        [$a] = $this->items([[], [], [], ['status' => 'draft']]);

        $result = $this->service->markComplete($this->student, $this->offeringId, $a);

        $this->assertSame(['progress' => 33, 'completed_items' => 1, 'total_items' => 3], $result['module']);
    }

    public function testCompletingAnItemReportsWhichItemsItUnlocked(): void
    {
        [$a, $b, $c] = $this->items([[], ['mode' => 'previous'], []]);

        $result = $this->service->markComplete($this->student, $this->offeringId, $a);

        $this->assertSame([$b], $result['unlockedItemIds']);
        $this->assertNotContains($c, $result['unlockedItemIds']);
    }

    public function testALockedItemCannotBeCompleted(): void
    {
        [, $second] = $this->items([['title' => 'Intro'], ['mode' => 'previous']]);

        $result = $this->service->markComplete($this->student, $this->offeringId, $second);

        $this->assertFalse($result['success']);
        $this->assertSame('Complete “Intro” first.', $result['message']);
    }

    public function testAStudentWhoIsNotEnrolledCannotComplete(): void
    {
        [$item] = $this->items([[]]);
        $stranger = CourseFactory::user('student');

        $result = $this->service->markComplete($stranger, $this->offeringId, $item);

        $this->assertFalse($result['success']);
        $this->assertSame('Learning item not found.', $result['message']);
    }

    public function testADroppedStudentCannotComplete(): void
    {
        [$item] = $this->items([[]]);
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $dropped, 'dropped');

        $this->assertFalse($this->service->markComplete($dropped, $this->offeringId, $item)['success']);
    }

    public function testAnInstructorCannotMarkComplete(): void
    {
        [$item] = $this->items([[]]);

        $this->assertFalse($this->service->markComplete($this->instructor, $this->offeringId, $item)['success']);
    }

    public function testAnItemFromAnotherOfferingIsNotFound(): void
    {
        $otherOffering = CourseFactory::offering('B');
        $otherUnit = CourseFactory::unit(CourseFactory::module($otherOffering));
        [$foreign] = CourseFactory::items($otherUnit, [[]]);

        $result = $this->service->markComplete($this->student, $this->offeringId, $foreign);

        $this->assertFalse($result['success']);
        $this->assertSame('Learning item not found.', $result['message']);
    }

    public function testADraftItemUnitOrModuleCannotBeCompleted(): void
    {
        [$draftItem] = $this->items([['status' => 'draft']]);

        $draftUnit = CourseFactory::unit($this->moduleId, 'draft', 2);
        [$inDraftUnit] = CourseFactory::items($draftUnit, [[]]);

        $draftModule = CourseFactory::module($this->offeringId, 'draft', 2);
        $unitInDraftModule = CourseFactory::unit($draftModule);
        [$inDraftModule] = CourseFactory::items($unitInDraftModule, [[]]);

        foreach ([$draftItem, $inDraftUnit, $inDraftModule] as $id) {
            $this->assertFalse(
                $this->service->markComplete($this->student, $this->offeringId, $id)['success'],
                "Item {$id} is not visible to students and must not be completable"
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Video progress and the 90% rule
    |--------------------------------------------------------------------------
    */

    public function testATrackableVideoNeedsNinetyPercentWatched(): void
    {
        [$video] = $this->items([['type' => 'video']]);
        CourseFactory::resource($video, 'video', 'https://www.youtube.com/watch?v=abc12345');

        $early = $this->service->saveVideoProgress($this->student, $this->offeringId, $video, 89);
        $this->assertSame(['success' => true, 'progress' => 89, 'canComplete' => false], $early);

        $refused = $this->service->markComplete($this->student, $this->offeringId, $video);
        $this->assertFalse($refused['success']);
        $this->assertSame('Watch at least 90% of the video to complete it.', $refused['message']);

        $enough = $this->service->saveVideoProgress($this->student, $this->offeringId, $video, 90);
        $this->assertTrue($enough['canComplete']);

        $this->assertTrue($this->service->markComplete($this->student, $this->offeringId, $video)['success']);
    }

    public function testVideoProgressOnlyGoesUp(): void
    {
        [$video] = $this->items([['type' => 'video']]);
        CourseFactory::resource($video, 'video', 'https://youtu.be/abcdef12');

        $this->service->saveVideoProgress($this->student, $this->offeringId, $video, 95);
        $replay = $this->service->saveVideoProgress($this->student, $this->offeringId, $video, 10);

        $this->assertSame(95, $replay['progress']);
    }

    public function testVideoProgressIsClampedToZeroAndOneHundred(): void
    {
        [$video] = $this->items([['type' => 'video']]);

        $this->assertSame(100, $this->service->saveVideoProgress($this->student, $this->offeringId, $video, 150)['progress']);
        $this->assertSame(100, $this->service->saveVideoProgress($this->student, $this->offeringId, $video, -5)['progress'], 'never lowers recorded progress');
    }

    public function testSavingVideoProgressDoesNotUndoACompletion(): void
    {
        [$video] = $this->items([['type' => 'video']]);
        CourseFactory::progress($video, $this->student);

        $this->service->saveVideoProgress($this->student, $this->offeringId, $video, 20);

        $row = db_connect()->table('learning_completions')->where('learning_item_id', $video)->get()->getRowArray();
        $this->assertSame('completed', $row['status']);
    }

    public function testAVideoOnAnUntrackableHostCompletesImmediately(): void
    {
        [$vimeo, $noResource] = $this->items([['type' => 'video'], ['type' => 'video']]);
        CourseFactory::resource($vimeo, 'video', 'https://vimeo.com/123456');

        $this->assertTrue($this->service->markComplete($this->student, $this->offeringId, $vimeo)['success']);
        $this->assertTrue($this->service->markComplete($this->student, $this->offeringId, $noResource)['success']);
    }

    public function testVideoProgressIsRejectedForNonVideosAndLockedVideos(): void
    {
        [$reading, $first, $lockedVideo] = $this->items([[], [], ['type' => 'video', 'mode' => 'previous']]);

        $notAVideo = $this->service->saveVideoProgress($this->student, $this->offeringId, $reading, 50);
        $this->assertSame(['success' => false, 'message' => 'Video not found.'], $notAVideo);

        $locked = $this->service->saveVideoProgress($this->student, $this->offeringId, $lockedVideo, 50);
        $this->assertSame(['success' => false, 'message' => 'This item is locked.'], $locked);
    }

    /*
    |--------------------------------------------------------------------------
    | Quiz, assignment and discussion rules
    |--------------------------------------------------------------------------
    */

    public function testAQuizOrAssignmentWithoutAnAssessmentCannotBeCompleted(): void
    {
        [$quiz, $assignment] = $this->items([['type' => 'quiz'], ['type' => 'assignment']]);

        $quizResult = $this->service->markComplete($this->student, $this->offeringId, $quiz);
        $assignmentResult = $this->service->markComplete($this->student, $this->offeringId, $assignment);

        $this->assertSame("This quiz isn't set up yet.", $quizResult['message']);
        $this->assertSame("This assignment isn't set up yet.", $assignmentResult['message']);
    }

    public function testADiscussionItemCompletesOnlyAfterTheStudentReplies(): void
    {
        [$item] = $this->items([['type' => 'discussion']]);

        $db = db_connect();
        $db->table('discussion_threads')->insert([
            'offering_id' => $this->offeringId,
            'learning_item_id' => $item,
            'author_id' => $this->instructor->id,
            'title' => 'Talk',
            'body' => '',
            'last_activity_at' => date('Y-m-d H:i:s'),
        ]);
        $threadId = (int) $db->insertID();

        $before = $this->service->markComplete($this->student, $this->offeringId, $item);
        $this->assertFalse($before['success']);
        $this->assertSame('Post a reply in the discussion to complete it.', $before['message']);

        // The instructor replying does not count for the student.
        $db->table('discussion_replies')->insert(['thread_id' => $threadId, 'author_id' => $this->instructor->id, 'body' => 'Welcome']);
        $this->assertFalse($this->service->markComplete($this->student, $this->offeringId, $item)['success']);

        $db->table('discussion_replies')->insert(['thread_id' => $threadId, 'author_id' => $this->student->id, 'body' => 'Hello']);
        $this->assertTrue($this->service->markComplete($this->student, $this->offeringId, $item)['success']);
    }

    #[DataProvider('provideYoutubeUrls')]
    public function testYoutubeIdsAreRecognised(string $url, ?string $expected): void
    {
        $this->assertSame($expected, $this->service->youtubeId($url));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideYoutubeUrls(): iterable
    {
        yield 'watch link' => ['https://www.youtube.com/watch?v=abc12345', 'abc12345'];
        yield 'watch link with other parameters first' => ['https://www.youtube.com/watch?t=5&v=abc12345', 'abc12345'];
        yield 'short link' => ['https://youtu.be/abcdef12', 'abcdef12'];
        yield 'embed link' => ['https://www.youtube.com/embed/abcdef12', 'abcdef12'];
        yield 'shorts link' => ['https://www.youtube.com/shorts/abcdef12', 'abcdef12'];
        yield 'another host' => ['https://vimeo.com/123456', null];
        yield 'empty' => ['', null];
    }

    public function testAnUploadedVideoIsTrackable(): void
    {
        $this->assertTrue($this->service->isTrackableVideo(1, ['file_id' => 5, 'resource_url' => null]));
        $this->assertFalse($this->service->isTrackableVideo(1, ['file_id' => null, 'resource_url' => 'https://vimeo.com/1']));
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor dependency settings
    |--------------------------------------------------------------------------
    */

    public function testDependencyOptionsListTheEarlierItemsAndThePreviousOne(): void
    {
        [$a, $b, $c] = $this->items([['title' => 'A'], ['title' => 'B'], ['title' => 'C']]);
        CourseFactory::requires($c, $a);

        $options = $this->service->dependencyOptions($this->offeringId, $c);

        $this->assertSame([$a, $b], array_column($options['candidates'], 'id'));
        $this->assertSame(['id' => $b, 'title' => 'B'], $options['previous']);
        $this->assertSame([$a], $options['prerequisiteIds']);
        $this->assertSame('none', $options['mode']);
    }

    public function testTheFirstItemHasNoCandidates(): void
    {
        [$a] = $this->items([[], []]);

        $options = $this->service->dependencyOptions($this->offeringId, $a);

        $this->assertSame([], $options['candidates']);
        $this->assertNull($options['previous']);
    }

    public function testDependencyOptionsForAnItemInAnotherOfferingAreNull(): void
    {
        [$item] = $this->items([[]]);

        $this->assertNull($this->service->dependencyOptions($this->offeringId + 999, $item));
    }

    public function testSavingAModeThatDoesNotExistIsRefused(): void
    {
        [$a] = $this->items([[]]);

        $result = $this->service->saveDependencies($this->offeringId, $a, 'whenever', []);

        $this->assertFalse($result['success']);
        $this->assertSame('Choose how this item should be locked.', $result['message']);
    }

    public function testThePreviousModeCannotBeSetOnTheFirstItem(): void
    {
        [$a] = $this->items([[], []]);

        $result = $this->service->saveDependencies($this->offeringId, $a, 'previous', []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('first item in its unit', $result['message']);
    }

    public function testSavingTheCustomModeKeepsOnlyEarlierItemsAndDropsDuplicates(): void
    {
        [$a, $b, $c, $d] = $this->items([[], [], [], []]);

        $result = $this->service->saveDependencies($this->offeringId, $c, 'custom', [$a, $a, $d, 987654]);

        $this->assertTrue($result['success']);
        $this->assertSame('custom', $result['mode']);
        $this->assertSame([$a], $result['prerequisiteIds']);

        $stored = db_connect()->table('learning_item_prerequisites')->where('learning_item_id', $c)->countAllResults();
        $this->assertSame(1, $stored);
        $this->assertNotContains($b, $result['prerequisiteIds']);
    }

    public function testACustomModeWithNothingUsableBecomesNone(): void
    {
        [$a, $b] = $this->items([[], []]);

        $result = $this->service->saveDependencies($this->offeringId, $a, 'custom', [$b]);

        $this->assertTrue($result['success']);
        $this->assertSame('none', $result['mode']);
        $this->assertSame('Dependencies removed.', $result['message']);
    }

    public function testSwitchingBackToNoneRemovesTheStoredPrerequisites(): void
    {
        [$a, $b] = $this->items([[], []]);
        $this->service->saveDependencies($this->offeringId, $b, 'custom', [$a]);

        $result = $this->service->saveDependencies($this->offeringId, $b, 'none', []);

        $this->assertSame('none', $result['mode']);
        $this->assertSame(0, db_connect()->table('learning_item_prerequisites')->where('learning_item_id', $b)->countAllResults());
        $this->assertSame('none', db_connect()->table('learning_items')->where('id', $b)->get()->getRowArray()['prerequisite_mode']);
    }

    public function testPruningDropsPrerequisitesThatAreNoLongerEarlier(): void
    {
        [$a, $b, $c] = $this->items([[], [], ['mode' => 'custom']]);
        CourseFactory::requires($c, $a, $b);

        // B is moved to the end of the unit, so it can no longer gate C.
        db_connect()->table('learning_items')->where('id', $b)->update(['order_index' => 10]);

        $this->service->pruneDependencies($this->unitId);

        $left = db_connect()->table('learning_item_prerequisites')->where('learning_item_id', $c)->get()->getResultArray();
        $this->assertSame([$a], array_map(static fn (array $row) => (int) $row['prerequisite_item_id'], $left));
        $this->assertSame('custom', db_connect()->table('learning_items')->where('id', $c)->get()->getRowArray()['prerequisite_mode']);
    }

    public function testAnItemLeftWithNoPrerequisitesFallsBackToNone(): void
    {
        [$a, $b] = $this->items([[], ['mode' => 'custom']]);
        CourseFactory::requires($b, $a);

        db_connect()->table('learning_items')->where('id', $a)->update(['order_index' => 10]);

        $this->service->pruneDependencies($this->unitId);

        $this->assertSame('none', db_connect()->table('learning_items')->where('id', $b)->get()->getRowArray()['prerequisite_mode']);
    }
}
