<?php

declare(strict_types=1);

use App\Services\Courses\LearningProgressService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * A learning item that waits on a quiz or an assignment can also require a score on it: the assessment's own passing
 * score, or a percentage of the teacher's choosing. Handing the work in is not enough then; the counted grade has to reach it.
 *
 * @internal
 */
final class DependencyMinScoreTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private LearningProgressService $service;

    private int $offering;

    private int $module;

    private int $unit;

    private User $ada;

    private User $instructor;

    /** @var int the quiz item, the item that waits on it, and the quiz's assessment */
    private int $quizItem;

    private int $waiting;

    private int $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new LearningProgressService();
        $this->offering   = CourseFactory::offering();
        $this->ada        = CourseFactory::user('student');
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->ada);

        $this->module = CourseFactory::module($this->offering);
        $this->unit   = CourseFactory::unit($this->module);

        [$this->quizItem, $this->waiting] = CourseFactory::items($this->unit, [
            ['title' => 'Quiz 1', 'type' => 'quiz'],
            ['title' => 'Next', 'type' => 'reading'],
        ]);

        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $this->quizItem, 'Quiz');
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['total_points' => 50, 'status' => 'Published']);

        $this->service->saveDependencies($this->offering, $this->waiting, 'previous', []);
    }

    private function requireScore(string $mode, ?float $percent = null): void
    {
        $result = $this->service->saveDependencies($this->offering, $this->waiting, 'previous', [], ['mode' => $mode, 'percent' => $percent]);
        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));
    }

    private function handIn(float $percent, string $status = 'Graded'): void
    {
        AssessmentFactory::attempt($this->quiz, $this->ada, $status, $percent / 100 * 50, ['percentage' => $percent]);
        CourseFactory::progress($this->quizItem, $this->ada);
    }

    private function locked(): bool
    {
        return $this->service->lockFor($this->ada, $this->waiting) !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | What it requires
    |--------------------------------------------------------------------------
    */

    public function testWithNoScoreRequiredHandingItInIsEnough(): void
    {
        $this->handIn(10);

        $this->assertFalse($this->locked());
    }

    public function testACustomPercentageMustBeReached(): void
    {
        $this->requireScore('custom', 70);
        $this->handIn(50);

        $this->assertTrue($this->locked());

        AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 40, ['percentage' => 80]);

        $this->assertFalse($this->locked(), 'the best graded attempt counts');
    }

    public function testExactlyTheMinimumIsEnough(): void
    {
        $this->requireScore('custom', 70);
        $this->handIn(70);

        $this->assertFalse($this->locked());
    }

    public function testThePassingScoreOfTheAssessmentItself(): void
    {
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['passing_percentage' => 60]);
        $this->requireScore('passing');

        $this->handIn(55);
        $this->assertTrue($this->locked());

        AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 32.5, ['percentage' => 65]);
        $this->assertFalse($this->locked());
    }

    public function testAPassingScoreInPointsIsReadAsAPercentage(): void
    {
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['passing_percentage' => null, 'passing_score' => 30]);
        $this->requireScore('passing');

        $this->handIn(50); // 25 of 50 points: below 30
        $this->assertTrue($this->locked());

        AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 30, ['percentage' => 60]);
        $this->assertFalse($this->locked());
    }

    public function testAnAssessmentWithNoPassingScoreAsksForNothingMore(): void
    {
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['passing_percentage' => null, 'passing_score' => null]);
        $this->requireScore('passing');
        $this->handIn(5);

        $this->assertFalse($this->locked());
    }

    public function testWorkNotGradedYetDoesNotCount(): void
    {
        $this->requireScore('custom', 70);
        $this->handIn(95, 'Submitted');

        $this->assertTrue($this->locked());
    }

    public function testTheMessageSaysWhatScoreIsNeeded(): void
    {
        $this->requireScore('custom', 70);
        $this->handIn(50);

        $lock = $this->service->lockFor($this->ada, $this->waiting);

        $this->assertStringContainsString('“Quiz 1”', $lock['message']);
        $this->assertStringContainsString('70%', $lock['message']);
        $this->assertSame(70.0, (float) $lock['prerequisites'][0]['min_percent']);
    }

    public function testOnlyQuizzesAndAssignmentsAreHeldToIt(): void
    {
        [$reading, $after] = CourseFactory::items(CourseFactory::unit($this->module, 'published', 2), [['title' => 'Read', 'type' => 'reading'], ['title' => 'After']]);
        $this->service->saveDependencies($this->offering, $after, 'previous', [], ['mode' => 'custom', 'percent' => 90]);
        CourseFactory::progress($reading, $this->ada);

        $this->assertNull($this->service->lockFor($this->ada, $after));
    }

    public function testAnAssignmentCountsToo(): void
    {
        $unit = CourseFactory::unit($this->module, 'published', 2);
        [$assignmentItem, $after] = CourseFactory::items($unit, [['title' => 'Essay', 'type' => 'assignment'], ['title' => 'After']]);
        $assignment = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $assignmentItem, 'Assignment');
        db_connect()->table('assessments')->where('id', $assignment)->update(['total_points' => 20, 'status' => 'Published']);
        $this->service->saveDependencies($this->offering, $after, 'previous', [], ['mode' => 'custom', 'percent' => 75]);

        AssessmentFactory::attempt($assignment, $this->ada, 'Graded', 12, ['percentage' => 60]);
        CourseFactory::progress($assignmentItem, $this->ada);
        $this->assertNotNull($this->service->lockFor($this->ada, $after));

        AssessmentFactory::attempt($assignment, $this->ada, 'Graded', 18, ['percentage' => 90]);
        $this->assertNull($this->service->lockFor($this->ada, $after), 'the latest graded one counts for an assignment');
    }

    public function testACustomListHoldsEveryAssessmentToIt(): void
    {
        $unit = CourseFactory::unit($this->module, 'published', 2);
        [$q1, $q2, $after] = CourseFactory::items($unit, [['title' => 'Q1', 'type' => 'quiz'], ['title' => 'Q2', 'type' => 'quiz'], ['title' => 'After']]);
        $a1 = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $q1, 'Quiz');
        $a2 = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $q2, 'Quiz');
        db_connect()->table('assessments')->whereIn('id', [$a1, $a2])->update(['total_points' => 10, 'status' => 'Published']);
        $this->service->saveDependencies($this->offering, $after, 'custom', [$q1, $q2], ['mode' => 'custom', 'percent' => 70]);

        AssessmentFactory::attempt($a1, $this->ada, 'Graded', 9, ['percentage' => 90]);
        AssessmentFactory::attempt($a2, $this->ada, 'Graded', 5, ['percentage' => 50]);
        CourseFactory::progress($q1, $this->ada);
        CourseFactory::progress($q2, $this->ada);

        $lock = $this->service->lockFor($this->ada, $after);
        $this->assertSame(['Q2'], array_column($lock['prerequisites'], 'title'));
    }

    public function testAnInstructorIsNeverHeldToIt(): void
    {
        $this->requireScore('custom', 99);

        $this->assertNull($this->service->lockFor($this->instructor, $this->waiting));
    }

    public function testTheBatchLookupAgrees(): void
    {
        $this->requireScore('custom', 70);
        $this->handIn(50);

        $locks = $this->service->locksFor($this->ada, [$this->waiting]);

        $this->assertSame($this->service->lockFor($this->ada, $this->waiting)['message'], $locks[$this->waiting]['message']);
    }

    public function testTheTreeLocksItToo(): void
    {
        $this->requireScore('custom', 70);
        $this->handIn(50);

        $items = db_connect()->table('learning_items')->where('learning_unit_id', $this->unit)->orderBy('order_index')->get()->getResultArray();
        $tree  = ['id' => $this->module, 'learning_units' => [['id' => $this->unit, 'title' => 'U', 'learning_items' => $items]]];

        $this->service->annotateTree($this->ada, $tree);

        $this->assertTrue($tree['learning_units'][0]['learning_items'][1]['is_locked']);
    }

    /*
    |--------------------------------------------------------------------------
    | The modal's data and saving
    |--------------------------------------------------------------------------
    */

    public function testTheOptionsSayWhatIsRequiredAndWhatEachCandidateIs(): void
    {
        $this->requireScore('custom', 70);

        $options = $this->service->dependencyOptions($this->offering, $this->waiting);

        $this->assertSame(['mode' => 'custom', 'percent' => 70.0], $options['minScore']);
        $this->assertSame('quiz', $options['previous']['item_type']);
    }

    public function testNothingRequiredIsTheDefault(): void
    {
        $options = $this->service->dependencyOptions($this->offering, $this->waiting);

        $this->assertSame(['mode' => 'none', 'percent' => null], $options['minScore']);
    }

    public function testACustomPercentageHasToBeBetweenOneAndOneHundred(): void
    {
        foreach ([0, -5, 101, null] as $bad) {
            $result = $this->service->saveDependencies($this->offering, $this->waiting, 'previous', [], ['mode' => 'custom', 'percent' => $bad]);

            $this->assertFalse($result['success'], (string) json_encode($bad));
        }

        $this->assertFalse($this->service->saveDependencies($this->offering, $this->waiting, 'previous', [], ['mode' => 'sometimes'])['success']);
    }

    public function testTurningDependenciesOffTurnsTheScoreOffToo(): void
    {
        $this->requireScore('custom', 70);

        $this->service->saveDependencies($this->offering, $this->waiting, 'none', [], ['mode' => 'custom', 'percent' => 70]);

        $row = db_connect()->table('learning_items')->where('id', $this->waiting)->get()->getRowArray();
        $this->assertSame('none', $row['dependency_min_score_mode']);
        $this->assertNull($row['dependency_min_score_percent']);
    }

    public function testChoosingPassingStoresNoPercentage(): void
    {
        $this->service->saveDependencies($this->offering, $this->waiting, 'previous', [], ['mode' => 'passing', 'percent' => 80]);

        $row = db_connect()->table('learning_items')->where('id', $this->waiting)->get()->getRowArray();
        $this->assertSame('passing', $row['dependency_min_score_mode']);
        $this->assertNull($row['dependency_min_score_percent']);
    }

    public function testSavingWithoutTheScoreLeavesItAlone(): void
    {
        $this->requireScore('custom', 70);

        $this->service->saveDependencies($this->offering, $this->waiting, 'previous', []);

        $this->assertSame('custom', db_connect()->table('learning_items')->where('id', $this->waiting)->get()->getRowArray()['dependency_min_score_mode']);
    }

    public function testTheInstructorsLabelNamesTheScore(): void
    {
        $this->requireScore('custom', 70);

        $items = db_connect()->table('learning_items')->where('learning_unit_id', $this->unit)->orderBy('order_index')->get()->getResultArray();
        $tree  = ['id' => $this->module, 'learning_units' => [['id' => $this->unit, 'title' => 'U', 'learning_items' => $items]]];

        $this->service->annotateTree($this->instructor, $tree);
        $this->assertSame(['Quiz 1 (70%+)'], $tree['learning_units'][0]['learning_items'][1]['dependency_labels']);

        $this->requireScore('passing');
        $items = db_connect()->table('learning_items')->where('learning_unit_id', $this->unit)->orderBy('order_index')->get()->getResultArray();
        $tree  = ['id' => $this->module, 'learning_units' => [['id' => $this->unit, 'title' => 'U', 'learning_items' => $items]]];
        $this->service->annotateTree($this->instructor, $tree);
        $this->assertSame(['Quiz 1 (passing score)'], $tree['learning_units'][0]['learning_items'][1]['dependency_labels']);
    }
}
