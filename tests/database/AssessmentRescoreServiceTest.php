<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentRescoreService;
use App\Services\Courses\Grades\GradeItemSyncService;
use App\Services\Courses\Grades\GradingEngineService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * When a quiz's questions change, the scores stored on its attempts, the
 * gradebook item's maximum and the offering's grades are brought in step, so
 * a score can never stay above the quiz's maximum.
 *
 * @internal
 */
final class AssessmentRescoreServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssessmentRescoreService $service;

    private int $offering;

    private int $quiz;

    private int $first;

    private int $second;

    private User $teacher;

    private User $ann;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AssessmentRescoreService();

        $this->offering = CourseFactory::offering();
        $this->teacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->teacher);

        $this->quiz = CourseFactory::assessment($this->offering, $this->teacher, CourseFactory::module($this->offering));

        $this->first = AssessmentFactory::question($this->quiz, $this->teacher, 'Essay', 5.0);
        $this->second = AssessmentFactory::question($this->quiz, $this->teacher, 'Essay', 5.0);

        db_connect()->table('assessments')->where('id', $this->quiz)->update(['status' => 'Published', 'total_points' => 10]);

        $this->ann = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->ann);
    }

    /** An attempt of Ann's with an answer to each question. @return int the attempt id */
    private function attempt(string $status = 'Graded', float $storedScore = 10.0): int
    {
        $attempt = AssessmentFactory::attempt($this->quiz, $this->ann, $status, $storedScore, ['percentage' => 100]);

        AssessmentFactory::response($attempt, $this->first, ['score' => 5.0]);
        AssessmentFactory::response($attempt, $this->second, ['score' => 5.0]);

        return $attempt;
    }

    private function row(string $table, int $id): array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    /** A published scheme with this quiz as a gradebook item, Ann's attempt counted. */
    private function inGradebook(string $schemeStatus = 'Published'): int
    {
        $scheme = GradeFactory::scheme($this->offering, 75.0);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => $schemeStatus, 'needs_recalculation' => 0]);
        GradeFactory::category($scheme, 'Quizzes', 100);

        (new GradeItemSyncService())->syncFromAssessment($this->quiz);

        return $scheme;
    }

    public function testAnAttemptsScoreIsTheSumOfItsAnswersAndItsPercentageFollowsTheNewTotal(): void
    {
        $attempt = $this->attempt();

        // One question goes: 5 points are left, and the answer to the other is worth 5.
        db_connect()->table('assessment_questions')->where('id', $this->first)->delete();
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['total_points' => 5]);

        $this->service->afterQuestionsChanged($this->quiz);

        $row = $this->row('assessment_submissions', $attempt);
        $this->assertEquals(5.0, (float) $row['score'], 'the deleted question\'s answer went with it');
        $this->assertEquals(100.0, (float) $row['percentage']);
    }

    public function testALargerQuizLowersTheShareOfAnAttemptThatDidNotAnswerTheNewQuestion(): void
    {
        $attempt = $this->attempt();

        db_connect()->table('assessments')->where('id', $this->quiz)->update(['total_points' => 20]);

        $this->service->afterQuestionsChanged($this->quiz);

        $this->assertEquals(50.0, (float) $this->row('assessment_submissions', $attempt)['percentage']);
    }

    public function testAnAttemptStillInProgressIsLeftAlone(): void
    {
        $attempt = $this->attempt('In Progress', 3.0);

        $this->service->afterQuestionsChanged($this->quiz);

        $this->assertEquals(3.0, (float) $this->row('assessment_submissions', $attempt)['score']);
    }

    public function testTheGradebookItemTakesTheNewTotalAndTheGradesFollow(): void
    {
        $attempt = $this->attempt();
        $this->inGradebook();
        (new GradingEngineService())->recalculateOffering($this->offering);

        db_connect()->table('assessment_questions')->where('id', $this->first)->delete();
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['total_points' => 5]);

        $this->service->afterQuestionsChanged($this->quiz);

        $item = db_connect()->table('grade_items')->where('assessment_id', $this->quiz)->get()->getRowArray();
        $this->assertEquals(5.0, (float) $item['max_points']);

        $record = db_connect()->table('gradebook_records')->where('student_id', $this->ann->id)->get()->getRowArray();
        $this->assertEquals(100.0, (float) $record['calculated_percentage'], 'never above 100%');
        $this->assertEquals(5.0, (float) db_connect()->table('gradebook_item_scores')->where('grade_item_id', $item['id'])->get()->getRowArray()['raw_score']);
        $this->assertNotNull($attempt);
    }

    public function testADraftSchemeIsFlaggedInsteadOfRecalculated(): void
    {
        $this->attempt();
        $scheme = $this->inGradebook('Draft');
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['needs_recalculation' => 0]);

        $this->service->afterQuestionsChanged($this->quiz);

        $this->assertSame(1, (int) $this->row('grading_schemes', $scheme)['needs_recalculation']);
        $this->assertSame(0, db_connect()->table('gradebook_records')->countAllResults(), 'nobody\'s grade moved');
    }

    public function testAQuizThatIsNotInTheGradebookStillGetsItsAttemptsRescored(): void
    {
        $attempt = $this->attempt('Graded', 99.0);

        $this->service->afterQuestionsChanged($this->quiz);

        $this->assertEquals(10.0, (float) $this->row('assessment_submissions', $attempt)['score'], 'from the answers, not the stale stored total');
        $this->assertSame(0, db_connect()->table('grade_items')->countAllResults());
    }

    public function testAnArchivedGradeItemIsNotTouched(): void
    {
        $this->attempt();
        $this->inGradebook();
        db_connect()->table('grade_items')->where('assessment_id', $this->quiz)->update(['status' => 'Archived', 'max_points' => 7]);

        $this->service->afterQuestionsChanged($this->quiz);

        $this->assertEquals(7.0, (float) db_connect()->table('grade_items')->where('assessment_id', $this->quiz)->get()->getRowArray()['max_points']);
    }

    public function testAQuizThatIsGoneChangesNothing(): void
    {
        $this->service->afterQuestionsChanged(999999);

        $this->assertSame(0, db_connect()->table('assessment_submissions')->countAllResults());
    }
}
