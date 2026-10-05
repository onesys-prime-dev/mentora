<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentOverviewService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The Assessments tab's numbers and lists, per role: the student's status on
 * each assessment (and what is due, overdue, up next, newly marked) and the
 * instructor's grading queue, submission rate, missing work and drafts, all
 * scoped to one section.
 *
 * @internal
 */
final class AssessmentOverviewServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssessmentOverviewService $service;
    private int $offering;
    private int $courseId;
    private int $module;
    private User $instructor;
    private User $ada;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AssessmentOverviewService();
        $this->offering = CourseFactory::offering();
        $this->courseId = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'];
        $this->module = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->ada = CourseFactory::user('student');
        $this->bob = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->ada);
        CourseFactory::enroll($this->offering, $this->bob);
    }

    private function when(string $relative): string
    {
        return date('Y-m-d H:i:s', strtotime($relative));
    }

    /** An assessment of this section; open now, due in 30 days unless told otherwise. */
    private function assessment(string $type = 'Quiz', array $change = [], string $title = ''): int
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, $this->module, null, $type);

        $change = array_map(static fn ($v) => is_string($v) && preg_match('/^[+-]\d+ (minute|hour|day)s?$/', $v) ? date('Y-m-d H:i:s', strtotime($v)) : $v, $change);

        AssessmentFactory::open($id, $change + ['due_date' => $this->when('+30 days'), 'available_from' => $this->when('-1 day')] + ($title !== '' ? ['title' => $title] : []));

        return $id;
    }

    private function settings(int $id, array $settings): void
    {
        db_connect()->table('assessment_settings')->insert($settings + ['assessment_id' => $id, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function student(?User $user = null): array
    {
        return $this->service->build($user ?? $this->ada, $this->offering, $this->courseId);
    }

    private function instructorView(): array
    {
        return $this->service->build($this->instructor, $this->offering, $this->courseId);
    }

    /** @return array<int, array> rows by assessment id */
    private function rows(array $overview): array
    {
        return array_column($overview['assessments'], null, 'id');
    }

    private function metric(array $overview, string $label): array
    {
        foreach ($overview['metrics'] as $metric) {
            if ($metric['label'] === $label) {
                return $metric;
            }
        }

        $this->fail("no metric {$label}");
    }

    /*
    |--------------------------------------------------------------------------
    | Student: the status on each assessment
    |--------------------------------------------------------------------------
    */

    private function stateOf(int $assessment, ?User $student = null): string
    {
        return $this->rows($this->student($student))[$assessment]['student']['key'];
    }

    public function testAnUntouchedAssessmentIsNotStartedDueSoonOrOpensLater(): void
    {
        $far = $this->assessment();
        $soon = $this->assessment('Quiz', ['due_date' => '+3 days']);
        $later = $this->assessment('Quiz', ['available_from' => '+2 days']);

        $this->assertSame('not_started', $this->stateOf($far));
        $this->assertSame('due_soon', $this->stateOf($soon));
        $this->assertSame('upcoming', $this->stateOf($later));
        $this->assertStringStartsWith('Opens ', $this->rows($this->student())[$later]['student']['label']);
    }

    public function testAQuizInProgressCanBeResumedWhileItIsOpen(): void
    {
        $quiz = $this->assessment();
        AssessmentFactory::attempt($quiz, $this->ada, 'In Progress');

        $this->assertSame('in_progress', $this->stateOf($quiz));
    }

    public function testGradedWorkShowsItsScore(): void
    {
        $quiz = $this->assessment();
        AssessmentFactory::attempt($quiz, $this->ada, 'Graded', 8);

        $state = $this->rows($this->student())[$quiz]['student'];

        $this->assertSame('graded', $state['key']);
        $this->assertSame('success', $state['tone']);
        $this->assertSame(8.0, $state['score']);
        $this->assertTrue($state['submitted']);
    }

    public function testSubmittedAndBeingGradedAreTold(): void
    {
        $waiting = $this->assessment('Assignment');
        $marking = $this->assessment('Assignment');
        AssessmentFactory::attempt($waiting, $this->ada, 'Submitted');
        AssessmentFactory::attempt($marking, $this->ada, 'Grading');

        $this->assertSame('submitted', $this->stateOf($waiting));
        $this->assertSame('grading', $this->stateOf($marking));
    }

    public function testAPartlyGradedQuizShowsItsScoreSoFar(): void
    {
        $quiz = $this->assessment();
        AssessmentFactory::attempt($quiz, $this->ada, 'Partially Graded', 4);

        $state = $this->rows($this->student())[$quiz]['student'];

        $this->assertSame('partially_graded', $state['key']);
        $this->assertSame(4.0, $state['score']);
    }

    public function testAResubmittedAssignmentKeepsItsOldGradeVisible(): void
    {
        $assignment = $this->assessment('Assignment', ['attempt_limit' => 2]);
        AssessmentFactory::attempt($assignment, $this->ada, 'Graded', 15);
        AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

        $state = $this->rows($this->student())[$assignment]['student'];

        $this->assertSame('resubmitted', $state['key']);
        $this->assertSame(15.0, $state['score']);
    }

    public function testClosedMissedAndOverdueAreTold(): void
    {
        $closed = $this->assessment('Quiz', ['status' => 'Closed']);
        $over = $this->assessment('Quiz', ['due_date' => '-2 days']);
        $lateOk = $this->assessment('Quiz', ['due_date' => '-2 days']);
        $this->settings($lateOk, ['allow_late_submission' => 1]);

        $this->assertSame('closed', $this->stateOf($closed));
        $this->assertSame('missed', $this->stateOf($over));
        $this->assertSame('overdue', $this->stateOf($lateOk));
    }

    public function testAnOverdueAssessmentWithNoAttemptsLeftIsMissedEvenIfLateWorkIsAllowed(): void
    {
        $quiz = $this->assessment('Quiz', ['due_date' => '-2 days']);
        $this->settings($quiz, ['allow_late_submission' => 1]);
        AssessmentFactory::attempt($quiz, $this->ada, 'Cancelled');

        $this->assertSame('missed', $this->stateOf($quiz));
    }

    public function testAnItemBehindAnUnfinishedPrerequisiteIsLocked(): void
    {
        $unit = CourseFactory::unit($this->module);
        [$first, $second] = CourseFactory::items($unit, [[], ['type' => 'quiz', 'mode' => 'previous']]);
        $quiz = $this->assessment();
        db_connect()->table('assessments')->where('id', $quiz)->update(['learning_item_id' => $second]);

        $this->assertSame('locked', $this->stateOf($quiz));

        CourseFactory::progress($first, $this->ada);
        $this->assertSame('not_started', $this->stateOf($quiz));
    }

    public function testStudentsOnlySeeAssessmentsTheyMaySee(): void
    {
        $open = $this->assessment();
        $draft = $this->assessment('Quiz', ['status' => 'Draft']);
        $archived = $this->assessment('Quiz', ['status' => 'Archived']);

        $rows = $this->rows($this->student());

        $this->assertArrayHasKey($open, $rows);
        $this->assertArrayNotHasKey($draft, $rows);
        $this->assertArrayNotHasKey($archived, $rows);
    }

    public function testOnlyThisSectionsAssessmentsAreListed(): void
    {
        $mine = $this->assessment();
        $other = CourseFactory::offering('B');
        $theirs = CourseFactory::assessment($other, $this->instructor, CourseFactory::module($other));
        db_connect()->table('assessments')->where('id', $theirs)->update(['course_id' => $this->courseId, 'status' => 'Published']);

        $this->assertSame([$mine], array_keys($this->rows($this->student())));
        $this->assertSame([$mine], array_keys($this->rows($this->instructorView())));
    }

    public function testAssessmentsAreListedByDueDate(): void
    {
        $late = $this->assessment('Quiz', ['due_date' => '+20 days']);
        $early = $this->assessment('Quiz', ['due_date' => '+5 days']);
        $middle = $this->assessment('Quiz', ['due_date' => '+10 days']);

        $this->assertSame([$early, $middle, $late], array_map('intval', array_column($this->student()['assessments'], 'id')));
    }

    /*
    |--------------------------------------------------------------------------
    | Student: metrics
    |--------------------------------------------------------------------------
    */

    public function testDueThisWeekCountsOpenWorkDueWithinSevenDaysAndNamesTheNextOne(): void
    {
        $this->assessment('Quiz', ['due_date' => '+2 days'], 'Soon quiz');
        $this->assessment('Quiz', ['due_date' => '+6 days'], 'Later quiz');
        $this->assessment('Quiz', ['due_date' => '+9 days'], 'Not this week');
        $handedIn = $this->assessment('Quiz', ['due_date' => '+3 days'], 'Done already');
        AssessmentFactory::attempt($handedIn, $this->ada, 'Submitted');

        $metric = $this->metric($this->student(), 'Due This Week');

        $this->assertSame('2 tasks', $metric['value']);
        $this->assertStringStartsWith('Next: Soon quiz · in 2 days', $metric['detail']);
    }

    public function testAClosedAssessmentIsNotDueThisWeekEvenIfItsDeadlineIsAhead(): void
    {
        $this->assessment('Quiz', ['due_date' => '+2 days', 'status' => 'Closed']);

        $this->assertSame('0 tasks', $this->metric($this->student(), 'Due This Week')['value']);
    }

    public function testNothingDueThisWeek(): void
    {
        $this->assessment();

        $metric = $this->metric($this->student(), 'Due This Week');

        $this->assertSame('0 tasks', $metric['value']);
        $this->assertSame('Nothing due in the next 7 days', $metric['detail']);
    }

    public function testASingleTaskIsNotPluralised(): void
    {
        $this->assessment('Quiz', ['due_date' => '+2 days']);

        $this->assertSame('1 task', $this->metric($this->student(), 'Due This Week')['value']);
    }

    public function testOverdueCountsUnsubmittedPastDueWorkAndSaysWhichStillAcceptLateWork(): void
    {
        $this->assessment('Quiz', ['due_date' => '-2 days']);
        $late = $this->assessment('Quiz', ['due_date' => '-1 days']);
        $this->settings($late, ['allow_late_submission' => 1]);

        $metric = $this->metric($this->student(), 'Overdue');

        $this->assertSame('2 tasks', $metric['value']);
        $this->assertSame('danger', $metric['tone']);
        $this->assertSame('1 still accepts late work', $metric['detail']);
    }

    public function testAllCaughtUpAndNoLongerAcceptingSubmissions(): void
    {
        $this->assertSame("You're all caught up", $this->metric($this->student(), 'Overdue')['detail']);

        $this->assessment('Quiz', ['due_date' => '-2 days']);
        $metric = $this->metric($this->student(), 'Overdue');
        $this->assertSame('No longer accepting submissions', $metric['detail']);
    }

    public function testCompletedCountsHandedInWorkAgainstEverythingVisible(): void
    {
        $one = $this->assessment();
        $this->assessment();
        $this->assessment('Assignment');
        AssessmentFactory::attempt($one, $this->ada, 'Graded', 5);

        $metric = $this->metric($this->student(), 'Completed');

        $this->assertSame('1 of 3', $metric['value']);
        $this->assertSame('submitted', $metric['detail']);
    }

    public function testOneStudentsWorkDoesNotCountForAnother(): void
    {
        $quiz = $this->assessment();
        AssessmentFactory::attempt($quiz, $this->bob, 'Graded', 5);

        $this->assertSame('0 of 1', $this->metric($this->student($this->ada), 'Completed')['value']);
        $this->assertSame('1 of 1', $this->metric($this->student($this->bob), 'Completed')['value']);
    }

    /*
    |--------------------------------------------------------------------------
    | Student: up next and feedback
    |--------------------------------------------------------------------------
    */

    public function testUpNextPutsRunningQuizzesFirstThenLateWorkThenByDueDate(): void
    {
        $dueLater = $this->assessment('Quiz', ['due_date' => '+10 days']);
        $dueSooner = $this->assessment('Quiz', ['due_date' => '+4 days']);
        $late = $this->assessment('Quiz', ['due_date' => '-1 days']);
        $this->settings($late, ['allow_late_submission' => 1]);
        $running = $this->assessment('Quiz', ['due_date' => '+20 days']);
        AssessmentFactory::attempt($running, $this->ada, 'In Progress');
        $done = $this->assessment('Quiz', ['due_date' => '+1 days']);
        AssessmentFactory::attempt($done, $this->ada, 'Graded', 3);

        $upNext = $this->student()['upNext'];

        $this->assertSame([$running, $late, $dueSooner, $dueLater], array_map('intval', array_column($upNext, 'id')));
        $this->assertSame('Resume where you left off', $upNext[0]['nextDetail']);
        $this->assertStringStartsWith('Late · was due ', $upNext[1]['nextDetail']);
        $this->assertStringStartsWith('Due ', $upNext[2]['nextDetail']);
    }

    public function testARunningTimedQuizSaysItsTimerIsRunning(): void
    {
        $quiz = $this->assessment('Quiz', ['duration' => 30]);
        AssessmentFactory::attempt($quiz, $this->ada, 'In Progress');

        $this->assertSame('Resume · timer running', $this->student()['upNext'][0]['nextDetail']);
    }

    public function testUpNextShowsAtMostFiveAndNothingThatIsLockedMissedOrDone(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->assessment('Quiz', ['due_date' => "+{$i} days"]);
        }
        $this->assessment('Quiz', ['due_date' => '-3 days']);

        $this->assertCount(5, $this->student()['upNext']);
    }

    public function testFeedbackShowsTheLatestThreeGradedAttemptsThatHaveMessages(): void
    {
        $ids = [];
        foreach ([1, 2, 3, 4] as $n) {
            $ids[$n] = $this->assessment('Assignment', [], "Essay {$n}");
            $attempt = AssessmentFactory::attempt($ids[$n], $this->ada, 'Graded', 10 + $n, ['graded_at' => $this->when("-{$n} hours")]);
            db_connect()->table('assessment_feedbacks')->insert(['submission_id' => $attempt, 'author_id' => $this->instructor->id, 'author_role' => 'instructor', 'message' => "Note {$n}", 'created_at' => date('Y-m-d H:i:s')]);
        }
        $plain = $this->assessment('Assignment', [], 'No message');
        AssessmentFactory::attempt($plain, $this->ada, 'Graded', 9, ['graded_at' => $this->when('-10 minutes')]);
        $ungraded = $this->assessment('Assignment');
        $pending = AssessmentFactory::attempt($ungraded, $this->ada, 'Submitted');
        db_connect()->table('assessment_feedbacks')->insert(['submission_id' => $pending, 'author_id' => $this->instructor->id, 'author_role' => 'instructor', 'message' => 'Early comment', 'created_at' => date('Y-m-d H:i:s')]);

        $feedback = $this->student()['feedback'];

        $this->assertSame(['Essay 1', 'Essay 2', 'Essay 3'], array_column(array_column($feedback, 'assessment'), 'title'), 'newest first, three at most, no message or ungraded work');
        $this->assertSame(['Note 1', 'Note 2', 'Note 3'], array_column($feedback, 'feedback'));
        $this->assertSame([11.0, 12.0, 13.0], array_column($feedback, 'score'));
        $this->assertSame('1h ago', $feedback[0]['when']);
    }

    public function testFeedbackShowsTheLastMessageAndHowManyThereAre(): void
    {
        $id = $this->assessment('Assignment');
        $attempt = AssessmentFactory::attempt($id, $this->ada, 'Graded', 5);
        foreach (['First', '  Second  '] as $message) {
            db_connect()->table('assessment_feedbacks')->insert(['submission_id' => $attempt, 'author_id' => $this->instructor->id, 'author_role' => 'instructor', 'message' => $message, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $feedback = $this->student()['feedback'][0];

        $this->assertSame('Second', $feedback['feedback']);
        $this->assertSame(2, $feedback['message_count']);
    }

    public function testAMessageOnWorkThatIsNotGradedYetIsNotFeedbackYet(): void
    {
        $id = $this->assessment('Assignment');
        $attempt = AssessmentFactory::attempt($id, $this->ada, 'Submitted');
        db_connect()->table('assessment_feedbacks')->insert(['submission_id' => $attempt, 'author_id' => $this->instructor->id, 'author_role' => 'instructor', 'message' => 'Early comment', 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([], $this->student()['feedback']);
    }

    public function testFeedbackIsOnlyTheStudentsOwn(): void
    {
        $id = $this->assessment('Assignment');
        $attempt = AssessmentFactory::attempt($id, $this->bob, 'Graded', 5);
        db_connect()->table('assessment_feedbacks')->insert(['submission_id' => $attempt, 'author_id' => $this->instructor->id, 'author_role' => 'instructor', 'message' => 'For Bob', 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([], $this->student($this->ada)['feedback']);
    }

    public function testAStudentWithNothingVisibleGetsEmptyListsAndZeroMetrics(): void
    {
        $overview = $this->student();

        $this->assertSame('student', $overview['role']);
        $this->assertSame([], $overview['assessments']);
        $this->assertSame([], $overview['upNext']);
        $this->assertSame([], $overview['feedback']);
        $this->assertSame('0 of 0', $this->metric($overview, 'Completed')['value']);
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor
    |--------------------------------------------------------------------------
    */

    public function testTheGradingQueueCountsWaitingWorkAndNamesTheOldest(): void
    {
        $quiz = $this->assessment();
        AssessmentFactory::attempt($quiz, $this->ada, 'Submitted', 0, ['submitted_at' => $this->when('-3 days')]);
        AssessmentFactory::attempt($quiz, $this->bob, 'Submitted', 0, ['submitted_at' => $this->when('-2 hours')]);
        $assignment = $this->assessment('Assignment');
        AssessmentFactory::attempt($assignment, $this->ada, 'Graded', 10);

        $overview = $this->instructorView();
        $metric = $this->metric($overview, 'To Grade');

        $this->assertSame('instructor', $overview['role']);
        $this->assertSame('2 submissions', $metric['value']);
        $this->assertSame('warning', $metric['tone']);
        $this->assertSame('Oldest waiting 3 days', $metric['detail']);
        $this->assertSame(2, $overview['toGradeTotal']);
    }

    public function testAnEmptyQueueSaysSo(): void
    {
        $metric = $this->metric($this->instructorView(), 'To Grade');

        $this->assertSame('0 submissions', $metric['value']);
        $this->assertSame('neutral', $metric['tone']);
        $this->assertSame('Nothing waiting', $metric['detail']);
    }

    public function testEachAssessmentCarriesItsSectionCounts(): void
    {
        $quiz = $this->assessment();
        AssessmentFactory::attempt($quiz, $this->ada, 'Submitted');
        AssessmentFactory::attempt($quiz, $this->bob, 'Graded', 4);
        $outsider = CourseFactory::user('student');
        CourseFactory::enroll(CourseFactory::offering('B'), $outsider);
        AssessmentFactory::attempt($quiz, $outsider, 'Submitted');

        $section = $this->rows($this->instructorView())[$quiz]['section'];

        $this->assertSame(2, $section['students']);
        $this->assertSame(2, $section['submitted']);
        $this->assertSame(1, $section['toGrade']);
        $this->assertSame((int) $this->ada->id, $section['firstWaitingStudent']);
        $this->assertNotNull($section['waitingSince']);
    }

    public function testAQuizAttemptThatCouldStillChangeTheGradeCountsAsWaiting(): void
    {
        $quiz = $this->assessment();
        AssessmentFactory::attempt($quiz, $this->ada, 'Graded', 9);
        AssessmentFactory::attempt($quiz, $this->ada, 'Submitted');

        $this->assertSame(1, $this->rows($this->instructorView())[$quiz]['section']['toGrade']);
    }

    public function testArchivedAssessmentsLeaveTheGradingQueue(): void
    {
        $quiz = $this->assessment('Quiz', ['status' => 'Archived']);
        AssessmentFactory::attempt($quiz, $this->ada, 'Submitted');

        $overview = $this->instructorView();

        $this->assertSame(0, $overview['toGradeTotal']);
        $this->assertSame(0, $overview['readyCount']);
        $this->assertSame(1, $this->rows($overview)[$quiz]['section']['toGrade'], 'still counted on its own row');
    }

    public function testDueThisWeekOnlyCountsPublishedAssessmentsDueWithinSevenDays(): void
    {
        $this->assessment('Quiz', ['due_date' => '+2 days'], 'Next up');
        $this->assessment('Quiz', ['due_date' => '+4 days']);
        $this->assessment('Quiz', ['due_date' => '+3 days', 'status' => 'Draft']);
        $this->assessment('Quiz', ['due_date' => '+12 days']);

        $metric = $this->metric($this->instructorView(), 'Due This Week');

        $this->assertSame('2 assessments', $metric['value']);
        $this->assertStringStartsWith('Next: Next up · in 2 days', $metric['detail']);
    }

    public function testSubmissionRateIsHandedInAgainstExpectedOnPastDueWork(): void
    {
        $one = $this->assessment('Quiz', ['due_date' => '-3 days']);
        $two = $this->assessment('Assignment', ['due_date' => '-1 days']);
        $this->assessment('Quiz', ['due_date' => '+3 days']);
        AssessmentFactory::attempt($one, $this->ada, 'Graded', 5);
        AssessmentFactory::attempt($one, $this->bob, 'Submitted');
        AssessmentFactory::attempt($two, $this->ada, 'Submitted');

        $metric = $this->metric($this->instructorView(), 'Submission Rate');

        $this->assertSame('75%', $metric['value'], '3 of 4 expected');
        $this->assertSame('on 2 past-due assessments', $metric['detail']);
    }

    public function testAPastDueDraftIsNotExpectedToHaveSubmissions(): void
    {
        $this->assessment('Quiz', ['due_date' => '-3 days', 'status' => 'Draft']);

        $this->assertSame('—', $this->metric($this->instructorView(), 'Submission Rate')['value']);
        $this->assertSame('None', $this->metric($this->instructorView(), 'Missing Work')['value']);
    }

    public function testWithNothingPastDueThereIsNoRate(): void
    {
        $this->assessment();

        $metric = $this->metric($this->instructorView(), 'Submission Rate');

        $this->assertSame('—', $metric['value']);
        $this->assertSame('No past-due assessments yet', $metric['detail']);
    }

    public function testMissingWorkCountsWhatWasDueAndNeverArrivedAndFromHowManyStudents(): void
    {
        $one = $this->assessment('Quiz', ['due_date' => '-3 days']);
        $two = $this->assessment('Quiz', ['due_date' => '-1 days']);
        AssessmentFactory::attempt($one, $this->ada, 'Graded', 5);
        AssessmentFactory::attempt($two, $this->ada, 'Submitted');

        $metric = $this->metric($this->instructorView(), 'Missing Work');

        $this->assertSame('2 submissions', $metric['value'], "Bob handed in neither");
        $this->assertSame('from 1 student', $metric['detail']);
        $this->assertSame('danger', $metric['tone']);
    }

    public function testNoMissingWorkSaysEveryoneIsCaughtUpAndHowMuchIsStillOpen(): void
    {
        $past = $this->assessment('Quiz', ['due_date' => '-3 days']);
        AssessmentFactory::attempt($past, $this->ada, 'Graded', 5);
        AssessmentFactory::attempt($past, $this->bob, 'Graded', 5);
        $this->assessment();
        $this->assessment();

        $metric = $this->metric($this->instructorView(), 'Missing Work');

        $this->assertSame('None', $metric['value']);
        $this->assertSame('Everyone is caught up · 2 assessments still open', $metric['detail']);
    }

    public function testNothingIsMissingBeforeAnythingIsDue(): void
    {
        $this->assessment();

        $metric = $this->metric($this->instructorView(), 'Missing Work');

        $this->assertSame('None', $metric['value']);
        $this->assertSame('Nothing past due yet · 1 assessment still open', $metric['detail']);
    }

    public function testDraftsListUrgentOnesFirstByDueDateThenTheMostRecentlyEdited(): void
    {
        $urgentLater = $this->assessment('Quiz', ['status' => 'Draft', 'due_date' => '+5 days']);
        $urgentSooner = $this->assessment('Quiz', ['status' => 'Draft', 'due_date' => '-1 days']);
        $relaxedOld = $this->assessment('Quiz', ['status' => 'Draft', 'due_date' => '+40 days', 'updated_at' => $this->when('-5 days')]);
        $relaxedNew = $this->assessment('Quiz', ['status' => 'Draft', 'due_date' => '+50 days', 'updated_at' => $this->when('-1 hours')]);
        $this->assessment();

        $overview = $this->instructorView();

        $this->assertSame([$urgentSooner, $urgentLater, $relaxedNew, $relaxedOld], array_map('intval', array_column($overview['drafts'], 'id')));
        $this->assertSame(4, $overview['draftCount']);
        $this->assertSame('Past due — still a draft', $overview['drafts'][0]['dueWarning']);
        $this->assertStringContainsString('still a draft', $overview['drafts'][1]['dueWarning']);
        $this->assertNull($overview['drafts'][2]['dueWarning']);
        $this->assertSame('1h ago', $overview['drafts'][2]['edited']);
    }

    public function testTheSidebarsShowAtMostFiveButCountAll(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $draft = $this->assessment('Quiz', ['status' => 'Draft']);
            $ready = $this->assessment();
            AssessmentFactory::attempt($ready, $this->ada, 'Submitted');
        }

        $overview = $this->instructorView();

        $this->assertCount(5, $overview['drafts']);
        $this->assertSame(7, $overview['draftCount']);
        $this->assertCount(5, $overview['readyForGrading']);
        $this->assertSame(7, $overview['readyCount']);
        $this->assertNotNull($draft);
    }

    public function testReadyForGradingListsTheLongestWaitingFirst(): void
    {
        $newer = $this->assessment();
        $older = $this->assessment();
        $none = $this->assessment();
        AssessmentFactory::attempt($newer, $this->ada, 'Submitted', 0, ['submitted_at' => $this->when('-1 hours')]);
        AssessmentFactory::attempt($older, $this->ada, 'Submitted', 0, ['submitted_at' => $this->when('-2 days')]);
        AssessmentFactory::attempt($none, $this->ada, 'Graded', 5);

        $ready = $this->instructorView()['readyForGrading'];

        $this->assertSame([$older, $newer], array_map('intval', array_column($ready, 'id')));
        $this->assertSame('2 days', $ready[0]['waiting']);
        $this->assertSame('1 hour', $ready[1]['waiting']);
    }

    /*
    |--------------------------------------------------------------------------
    | Shared pieces
    |--------------------------------------------------------------------------
    */

    public function testTheSectionsStudentsAreTheEnrolledOnes(): void
    {
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $dropped, 'dropped');

        $ids = $this->service->sectionStudentIds($this->offering);

        sort($ids);
        $this->assertSame([(int) $this->ada->id, (int) $this->bob->id], $ids);
    }

    public function testLatestAttemptsGroupsByAssessmentAndStudent(): void
    {
        $quiz = $this->assessment();
        $first = AssessmentFactory::attempt($quiz, $this->ada, 'Graded', 3);
        $second = AssessmentFactory::attempt($quiz, $this->ada, 'Submitted');
        $running = AssessmentFactory::attempt($quiz, $this->bob, 'In Progress');

        $map = $this->service->latestAttempts([$quiz], [$this->ada->id, $this->bob->id]);

        $ada = $map[$quiz][(int) $this->ada->id];
        $this->assertSame(2, $ada['finishedCount']);
        $this->assertSame($second, (int) $ada['finished']['id']);
        $this->assertNull($ada['inProgress']);
        $this->assertSame([$first, $second], array_map('intval', array_column($ada['attempts'], 'id')));
        $bob = $map[$quiz][(int) $this->bob->id];
        $this->assertSame(0, $bob['finishedCount']);
        $this->assertNull($bob['finished']);
        $this->assertSame($running, (int) $bob['inProgress']['id']);
        $this->assertSame([], $this->service->latestAttempts([], [1]));
        $this->assertSame([], $this->service->latestAttempts([$quiz], []));
    }

    /** @return iterable<string, array{0: int, 1: string}> */
    public static function dueLabels(): iterable
    {
        yield 'today' => [0, 'today 9:30 AM'];
        yield 'tomorrow' => [1, 'tomorrow 9:30 AM'];
        yield 'in three days' => [3, 'in 3 days'];
        yield 'in six days' => [6, 'in 6 days'];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('dueLabels')]
    public function testDueLabels(int $daysAhead, string $expected): void
    {
        $now = strtotime('2026-10-05 08:00:00');
        $due = strtotime("2026-10-05 09:30:00 +{$daysAhead} days");

        $this->assertSame($expected, $this->service->dueLabel($due, $now));
    }

    public function testFurtherDatesAreShownAsADayAndTheTimeCanBeLeftOut(): void
    {
        $now = strtotime('2026-10-05 08:00:00');

        $this->assertSame('Oct 12', $this->service->dueLabel(strtotime('2026-10-12 09:30:00'), $now));
        $this->assertSame('Sep 20', $this->service->dueLabel(strtotime('2026-09-20 09:30:00'), $now), 'past dates show their own date');
        $this->assertSame('today', $this->service->dueLabel(strtotime('2026-10-05 23:00:00'), $now, false));
        $this->assertSame('tomorrow', $this->service->dueLabel(strtotime('2026-10-06 01:00:00'), $now, false));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function relativeTimes(): iterable
    {
        yield 'just now' => ['-20 seconds', 'just now'];
        yield 'minutes' => ['-5 minutes', '5 min ago'];
        yield 'hours' => ['-3 hours', '3h ago'];
        yield 'yesterday' => ['-30 hours', 'Yesterday'];
        yield 'days' => ['-4 days', '4 days ago'];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('relativeTimes')]
    public function testRelativeTimes(string $when, string $expected): void
    {
        $this->assertSame($expected, $this->service->relativeTime(date('Y-m-d H:i:s', strtotime($when))));
    }

    public function testOldOrMissingTimesAreShownAsADateOrNothing(): void
    {
        $this->assertSame(date('M j', strtotime('-20 days')), $this->service->relativeTime(date('Y-m-d H:i:s', strtotime('-20 days'))));
        $this->assertSame('', $this->service->relativeTime(null));
        $this->assertSame('', $this->service->relativeTime(''));
        $this->assertSame('', $this->service->relativeTime('not a date'));
    }
}
