<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\GradeWorkService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The instructor's grading: starting and reopening work, grading an
 * assignment by points or rubric, grading a quiz question by question
 * (auto-graded questions are corrected, written ones get points), what each
 * action does to the submission total, the feedback thread and the
 * gradebook, and the Grade Work page itself.
 *
 * @internal
 */
final class GradeWorkServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradeWorkService $service;
    private int $offeringId;
    private int $moduleId;
    private User $instructor;
    private User $ada;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GradeWorkService();
        $this->offeringId = CourseFactory::offering();
        $this->moduleId = CourseFactory::module($this->offeringId);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offeringId, $this->instructor);
        $this->ada = $this->student('Ada', 'Adams');
        $this->bob = $this->student('Bob', 'Brown');
    }

    private function student(string $first, string $last, ?int $offering = null): User
    {
        $student = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => $first, 'last_name' => $last]);
        CourseFactory::enroll($offering ?? $this->offeringId, $student);

        return $student;
    }

    private function assessment(string $type, float $points): int
    {
        $id = CourseFactory::assessment($this->offeringId, $this->instructor, $this->moduleId, null, $type);
        AssessmentFactory::open($id, ['total_points' => $points]);

        return $id;
    }

    private function row(int $id): array
    {
        return db_connect()->table('assessment_submissions')->where('id', $id)->get()->getRowArray();
    }

    /** A gradebook item for an assessment, so score changes can be followed. @return int item id */
    private function gradebookItem(int $assessmentId, float $max): int
    {
        $scheme = GradeFactory::scheme($this->offeringId);

        return GradeFactory::item($scheme, GradeFactory::category($scheme, 'Work', 100), 'Item', $max, 1, $assessmentId);
    }

    private function gradebookScore(int $itemId, User $student): ?array
    {
        return db_connect()->table('gradebook_item_scores')->where('grade_item_id', $itemId)->where('student_id', $student->id)->get()->getRowArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Starting and reopening
    |--------------------------------------------------------------------------
    */

    public function testOpeningSubmittedOrPartlyGradedWorkMovesItToGrading(): void
    {
        $assignment = $this->assessment('Assignment', 20);

        foreach (['Submitted', 'Partially Graded'] as $status) {
            $submission = AssessmentFactory::attempt($assignment, $this->ada, $status);

            $result = $this->service->startGrading($this->instructor, $submission);

            $this->assertTrue($result['success']);
            $this->assertSame('Grading', $this->row($submission)['status'], $status);
        }
    }

    public function testOpeningOtherWorkLeavesItAsItIs(): void
    {
        $assignment = $this->assessment('Assignment', 20);

        foreach (['Graded', 'In Progress', 'Grading', 'Returned'] as $status) {
            $submission = AssessmentFactory::attempt($assignment, $this->ada, $status);

            $this->service->startGrading($this->instructor, $submission);

            $this->assertSame($status, $this->row($submission)['status']);
        }
    }

    public function testStartingMissingWorkIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Submission not found.'], $this->service->startGrading($this->instructor, 999999));
    }

    public function testReopeningGradedWorkKeepsTheScoreButStopsItCounting(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Graded', 15);

        $result = $this->service->reopenGrading($this->instructor, $submission);

        $this->assertTrue($result['success']);
        $row = $this->row($submission);
        $this->assertSame('Grading', $row['status']);
        $this->assertSame(0, (int) $row['is_graded']);
        $this->assertEquals(15, $row['score'], 'the score stays so the form opens pre-filled');
    }

    public function testOnlyGradedWorkCanBeReopened(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

        $this->assertSame('Only graded work can be reopened.', $this->service->reopenGrading($this->instructor, $submission)['message']);
        $this->assertSame('Submitted', $this->row($submission)['status']);
        $this->assertSame('Submission not found.', $this->service->reopenGrading($this->instructor, 999999)['message']);
    }

    public function testReopeningTakesTheScoreOutOfTheGradebookOrFallsBackToTheEarlierGrade(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $item = $this->gradebookItem($assignment, 20);
        $first = AssessmentFactory::attempt($assignment, $this->ada, 'Graded', 10);
        $second = AssessmentFactory::attempt($assignment, $this->ada, 'Graded', 18);
        (new App\Services\Courses\Grades\GradebookSyncService())->syncItemScore($assignment, $this->ada->id);
        $this->assertEquals(18, $this->gradebookScore($item, $this->ada)['raw_score'], 'the latest graded submission counts');

        $this->service->reopenGrading($this->instructor, $second);
        $this->assertEquals(10, $this->gradebookScore($item, $this->ada)['raw_score'], 'the earlier grade stands while the newer one is reopened');

        $this->service->reopenGrading($this->instructor, $first);
        $this->assertNull($this->gradebookScore($item, $this->ada), 'nothing counts any more');
    }

    /*
    |--------------------------------------------------------------------------
    | Grading an assignment
    |--------------------------------------------------------------------------
    */

    public function testGradingAnAssignmentByPoints(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $item = $this->gradebookItem($assignment, 20);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

        $result = $this->service->submitGrade($this->instructor, $submission, ['score' => 15, 'feedback' => '  Good work ']);

        $this->assertTrue($result['success']);
        $row = $this->row($submission);
        $this->assertSame('Graded', $row['status']);
        $this->assertSame(1, (int) $row['is_graded']);
        $this->assertEquals(15, $row['score']);
        $this->assertEquals(75, $row['percentage']);
        $this->assertSame('Default', $row['grading_mode']);
        $this->assertNull($row['rubric_scores']);
        $this->assertSame($this->instructor->id, (int) $row['graded_by']);
        $this->assertNotNull($row['graded_at']);
        $this->assertEquals(15, $this->gradebookScore($item, $this->ada)['raw_score']);
        $thread = db_connect()->table('assessment_feedbacks')->where('submission_id', $submission)->get()->getResultArray();
        $this->assertSame(['Good work'], array_column($thread, 'message'));
    }

    public function testAScoreOutsideTheAssignmentsPointsIsPulledBackIntoRange(): void
    {
        $assignment = $this->assessment('Assignment', 20);

        foreach ([[50, 20], [-5, 0], ['abc', 0], [null, 0]] as [$given, $stored]) {
            $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

            $this->service->submitGrade($this->instructor, $submission, ['score' => $given]);

            $this->assertEquals($stored, $this->row($submission)['score'], (string) json_encode($given));
        }
    }

    public function testAnAssignmentWorthNothingHasNoPercentage(): void
    {
        $assignment = $this->assessment('Assignment', 0);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

        $this->service->submitGrade($this->instructor, $submission, ['score' => 5]);

        $this->assertEquals(0, $this->row($submission)['percentage']);
    }

    public function testGradingWithoutAFeedbackFieldAddsNoMessage(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

        $this->service->submitGrade($this->instructor, $submission, ['score' => 10]);

        $this->assertSame(0, db_connect()->table('assessment_feedbacks')->where('submission_id', $submission)->countAllResults());
    }

    public function testGradingByRubricSumsTheCriteriaEachLimitedToItsMaximum(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $rubric = AssessmentFactory::rubric($assignment, $this->instructor, [10, 10]);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');
        [$one, $two] = $rubric['criteria'];

        $this->service->submitGrade($this->instructor, $submission, [
            'grading_mode' => 'Rubric',
            'rubric_scores' => [$one => 7.5, $two => 99],
            'score' => 3,
        ]);

        $row = $this->row($submission);
        $this->assertSame('Rubric', $row['grading_mode']);
        $this->assertEquals(17.5, $row['score'], 'the typed score is ignored, 99 is cut to 10');
        $this->assertEquals(87.5, $row['percentage']);
        $this->assertEquals([(string) $one => 7.5, (string) $two => 10], json_decode($row['rubric_scores'], true));
    }

    public function testACriterionLeftBlankScoresZero(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $rubric = AssessmentFactory::rubric($assignment, $this->instructor, [10, 10]);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

        $this->service->submitGrade($this->instructor, $submission, ['grading_mode' => 'Rubric', 'rubric_scores' => [$rubric['criteria'][0] => 4]]);

        $this->assertEquals(4, $this->row($submission)['score']);
    }

    public function testRubricGradingNeedsARubric(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

        $result = $this->service->submitGrade($this->instructor, $submission, ['grading_mode' => 'Rubric', 'score' => 5]);

        $this->assertSame(['success' => false, 'message' => 'No rubric is linked to this assessment.'], $result);
        $this->assertSame('Submitted', $this->row($submission)['status']);
    }

    public function testAnUnknownGradingModeIsTreatedAsPoints(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

        $this->service->submitGrade($this->instructor, $submission, ['grading_mode' => 'Anything', 'score' => 9]);

        $this->assertSame('Default', $this->row($submission)['grading_mode']);
        $this->assertEquals(9, $this->row($submission)['score']);
    }

    public function testGradingMissingWorkIsRefused(): void
    {
        $this->assertSame('Submission not found.', $this->service->submitGrade($this->instructor, 999999, ['score' => 5])['message']);
    }

    public function testRegradingReplacesTheScoreAndTheGradebook(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $item = $this->gradebookItem($assignment, 20);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');
        $this->service->submitGrade($this->instructor, $submission, ['score' => 10, 'feedback' => 'First look']);

        $this->service->reopenGrading($this->instructor, $submission);
        $this->service->submitGrade($this->instructor, $submission, ['score' => 16, 'feedback' => 'First look']);

        $this->assertEquals(16, $this->gradebookScore($item, $this->ada)['raw_score']);
        $this->assertSame(1, db_connect()->table('assessment_feedbacks')->where('submission_id', $submission)->countAllResults(), 'the same note is not repeated');
    }

    /*
    |--------------------------------------------------------------------------
    | Grading a quiz
    |--------------------------------------------------------------------------
    */

    /**
     * A quiz of a multiple choice (4 pts), a keyed short answer (2) and an
     * essay (6), with Ada's submitted attempt: the choice right, the short
     * answer wrong, the essay answered.
     *
     * @return array<string, mixed>
     */
    private function quiz(): array
    {
        $quiz = $this->assessment('Quiz', 12);
        $item = $this->gradebookItem($quiz, 12);
        $mc = AssessmentFactory::multipleChoice($quiz, $this->instructor, [false, true], 4);
        $short = AssessmentFactory::shortAnswer($quiz, $this->instructor, ['Paris'], 2);
        $essay = AssessmentFactory::question($quiz, $this->instructor, 'Essay', 6);
        $attempt = AssessmentFactory::attempt($quiz, $this->ada, 'Partially Graded', 4);

        $r = [
            'mc' => AssessmentFactory::response($attempt, $mc['question'], ['selected_choice_id' => $mc['choices'][1], 'score' => 4, 'is_auto_graded' => 1]),
            'short' => AssessmentFactory::response($attempt, $short, ['response_text' => 'Lyon', 'score' => 0, 'is_auto_graded' => 1]),
            'essay' => AssessmentFactory::response($attempt, $essay, ['response_text' => 'My essay']),
        ];

        return compact('quiz', 'item', 'mc', 'short', 'essay', 'attempt', 'r');
    }

    private function response(int $id): array
    {
        return db_connect()->table('assessment_question_responses')->where('id', $id)->get()->getRowArray();
    }

    public function testAWrittenQuestionIsGivenPointsAndTheTotalFollows(): void
    {
        $q = $this->quiz();

        $result = $this->service->gradeQuestion($this->instructor, $q['attempt'], $q['essay'], ['mode' => 'points', 'points' => 4.5]);

        $this->assertTrue($result['success']);
        $this->assertEquals(4.5, $this->response($q['r']['essay'])['score']);
        $this->assertSame(0, (int) $this->response($q['r']['essay'])['is_auto_graded']);
        $this->assertEquals(8.5, $this->row($q['attempt'])['score'], '4 + 0 + 4.5');
        $this->assertEquals(70.83, $this->row($q['attempt'])['percentage']);
    }

    public function testPointsMustBeBetweenZeroAndTheQuestionsWorth(): void
    {
        $q = $this->quiz();

        foreach ([-1, 6.5] as $points) {
            $result = $this->service->gradeQuestion($this->instructor, $q['attempt'], $q['essay'], ['mode' => 'points', 'points' => $points]);

            $this->assertFalse($result['success'], (string) $points);
        }

        $this->assertSame('Points must be between 0 and 6.', $this->service->gradeQuestion($this->instructor, $q['attempt'], $q['essay'], ['mode' => 'points', 'points' => 7])['message']);
        $this->assertEquals(0, $this->response($q['r']['essay'])['score']);
    }

    public function testAnAutoGradedQuestionIsCorrectedWithCorrectOrIncorrect(): void
    {
        $q = $this->quiz();

        $this->service->gradeQuestion($this->instructor, $q['attempt'], $q['short'], ['mode' => 'correct']);
        $this->assertEquals(2, $this->response($q['r']['short'])['score']);
        $this->assertSame(0, (int) $this->response($q['r']['short'])['is_auto_graded'], 'now a manual decision');
        $this->assertEquals(6, $this->row($q['attempt'])['score']);

        $this->service->gradeQuestion($this->instructor, $q['attempt'], $q['mc']['question'], ['mode' => 'incorrect']);
        $this->assertEquals(0, $this->response($q['r']['mc'])['score']);
        $this->assertEquals(2, $this->row($q['attempt'])['score']);
    }

    public function testAManualOverrideCanBeRevertedToTheAutoGrade(): void
    {
        $q = $this->quiz();
        $this->service->gradeQuestion($this->instructor, $q['attempt'], $q['short'], ['mode' => 'correct']);

        $result = $this->service->gradeQuestion($this->instructor, $q['attempt'], $q['short'], ['mode' => 'auto']);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $this->response($q['r']['short'])['score'], "'Lyon' is still wrong");
        $this->assertSame(1, (int) $this->response($q['r']['short'])['is_auto_graded']);
    }

    public function testEachGradingModeOnlyAppliesToTheRightKindOfQuestion(): void
    {
        $q = $this->quiz();
        $grade = fn (int $question, array $data) => $this->service->gradeQuestion($this->instructor, $q['attempt'], $question, $data);

        $this->assertStringContainsString('only applies to auto-gradable', $grade($q['essay'], ['mode' => 'correct'])['message']);
        $this->assertStringContainsString('no auto-grade to revert', $grade($q['essay'], ['mode' => 'auto'])['message']);
        $this->assertStringContainsString('use Correct/Incorrect', $grade($q['mc']['question'], ['mode' => 'points', 'points' => 1])['message']);
        $this->assertSame('Unknown grading mode.', $grade($q['mc']['question'], ['mode' => 'bonus'])['message']);
        $this->assertSame('Unknown grading mode.', $grade($q['mc']['question'], [])['message']);
    }

    public function testAQuestionOfAnotherQuizOrWithNoAnswerCannotBeGraded(): void
    {
        $q = $this->quiz();
        $otherQuiz = $this->assessment('Quiz', 5);
        $foreign = AssessmentFactory::question($otherQuiz, $this->instructor, 'Essay', 5);
        $unanswered = AssessmentFactory::question($q['quiz'], $this->instructor, 'Essay', 1);

        $this->assertSame('Question not found.', $this->service->gradeQuestion($this->instructor, $q['attempt'], $foreign, ['mode' => 'points', 'points' => 1])['message']);
        $this->assertSame('No response from this student to grade.', $this->service->gradeQuestion($this->instructor, $q['attempt'], $unanswered, ['mode' => 'points', 'points' => 1])['message']);
        $this->assertSame('Submission not found.', $this->service->gradeQuestion($this->instructor, 999999, $q['essay'], ['mode' => 'points', 'points' => 1])['message']);
    }

    public function testQuestionByQuestionGradingOnlyAppliesToQuizzes(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        $submission = AssessmentFactory::attempt($assignment, $this->ada, 'Submitted');

        $this->assertSame('Per-question grading only applies to quizzes.', $this->service->gradeQuestion($this->instructor, $submission, 1, ['mode' => 'points', 'points' => 1])['message']);
    }

    public function testGradingAQuestionKeepsTheGradebookInStepOnlyOnceTheQuizIsGraded(): void
    {
        $q = $this->quiz();
        $this->service->gradeQuestion($this->instructor, $q['attempt'], $q['essay'], ['mode' => 'points', 'points' => 6]);
        $this->assertNull($this->gradebookScore($q['item'], $this->ada), 'still partially graded: not counted yet');

        $this->service->submitGrade($this->instructor, $q['attempt'], []);

        $this->assertEquals(10, $this->gradebookScore($q['item'], $this->ada)['raw_score']);
    }

    public function testSubmittingAQuizGradeTotalsTheQuestionScoresIgnoringAnyTypedScore(): void
    {
        $q = $this->quiz();
        $this->service->gradeQuestion($this->instructor, $q['attempt'], $q['essay'], ['mode' => 'points', 'points' => 3]);

        $result = $this->service->submitGrade($this->instructor, $q['attempt'], ['score' => 12, 'grading_mode' => 'Rubric', 'feedback' => 'Review the short answer']);

        $this->assertTrue($result['success']);
        $row = $this->row($q['attempt']);
        $this->assertSame('Graded', $row['status']);
        $this->assertSame(1, (int) $row['is_graded']);
        $this->assertEquals(7, $row['score'], '4 + 0 + 3');
        $this->assertEquals(58.33, $row['percentage']);
        $this->assertNull($row['grading_mode']);
        $this->assertSame(1, db_connect()->table('assessment_feedbacks')->where('submission_id', $q['attempt'])->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | The Grade Work page
    |--------------------------------------------------------------------------
    */

    public function testTheGradeWorkPageListsTheSectionsStudentsAndOpensOne(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        AssessmentFactory::attempt($assignment, $this->ada, 'Submitted', 0, ['submission_text' => 'Ada essay text']);
        AssessmentFactory::attempt($assignment, $this->bob, 'Submitted', 0, ['submission_text' => 'Bob essay text']);
        $other = CourseFactory::offering('B');
        $this->student('Zed', 'Zimmer', $other);

        $html = $this->service->gradeWork($this->instructor, $this->offeringId, $assignment, $this->bob->id)['html'];

        $this->assertStringContainsString('Ada Adams', $html);
        $this->assertStringContainsString('Bob Brown', $html);
        $this->assertStringNotContainsString('Zed Zimmer', $html, 'only this section');
        $this->assertStringContainsString('Bob essay text', $html, 'the chosen student is open');
        $this->assertStringNotContainsString('Ada essay text', $html);
    }

    public function testTheFirstStudentIsOpenWhenNoneIsChosen(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        AssessmentFactory::attempt($assignment, $this->ada, 'Submitted', 0, ['submission_text' => 'Ada essay text']);

        $html = $this->service->gradeWork($this->instructor, $this->offeringId, $assignment)['html'];

        $this->assertStringContainsString('Ada essay text', $html);
    }

    public function testAnAttemptOfAnotherStudentCannotBeOpenedThroughTheAttemptParameter(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        AssessmentFactory::attempt($assignment, $this->ada, 'Submitted', 0, ['submission_text' => 'Ada essay text']);
        $bobs = AssessmentFactory::attempt($assignment, $this->bob, 'Submitted', 0, ['submission_text' => 'Bob essay text']);

        $html = $this->service->gradeWork($this->instructor, $this->offeringId, $assignment, $this->ada->id, $bobs)['html'];

        $this->assertStringContainsString('Ada essay text', $html);
        $this->assertStringNotContainsString('Bob essay text', $html);
    }

    public function testAStudentWithSeveralAttemptsIsListedOnce(): void
    {
        $quiz = $this->assessment('Quiz', 10);
        AssessmentFactory::attempt($quiz, $this->ada, 'Graded', 5);
        $once = substr_count($this->service->gradeWork($this->instructor, $this->offeringId, $quiz, $this->ada->id)['html'], 'Ada Adams');

        AssessmentFactory::attempt($quiz, $this->ada, 'Graded', 6);
        AssessmentFactory::attempt($quiz, $this->ada, 'Submitted');
        $thrice = substr_count($this->service->gradeWork($this->instructor, $this->offeringId, $quiz, $this->ada->id)['html'], 'Ada Adams');

        $this->assertGreaterThan(0, $once);
        $this->assertSame($once, $thrice);
    }

    public function testStudentsWhoHaveNotSubmittedAreStillListed(): void
    {
        $assignment = $this->assessment('Assignment', 20);

        $html = $this->service->gradeWork($this->instructor, $this->offeringId, $assignment)['html'];

        $this->assertStringContainsString('Ada Adams', $html);
        $this->assertStringContainsString('Bob Brown', $html);
    }

    public function testDroppedStudentsAreNotListed(): void
    {
        $assignment = $this->assessment('Assignment', 20);
        db_connect()->table('enrollments')->where('student_user_id', $this->bob->id)->update(['status' => 'dropped']);

        $html = $this->service->gradeWork($this->instructor, $this->offeringId, $assignment)['html'];

        $this->assertStringNotContainsString('Bob Brown', $html);
    }

    public function testAQuizPageShowsTheStudentsAnswers(): void
    {
        $q = $this->quiz();

        $html = $this->service->gradeWork($this->instructor, $this->offeringId, $q['quiz'], $this->ada->id)['html'];

        $this->assertStringContainsString('My essay', $html);
        $this->assertStringContainsString('Lyon', $html);
    }

    public function testAnAssessmentThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->gradeWork($this->instructor, $this->offeringId, 999999);
    }
}
