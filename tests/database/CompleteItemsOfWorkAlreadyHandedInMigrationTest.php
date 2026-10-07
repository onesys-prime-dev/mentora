<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The migration that completes quiz and assignment items for work students had
 * already handed in before handing in completed the item by itself. It writes
 * real data on deploy, so what it leaves alone matters as much as what it fixes.
 *
 * @internal
 */
final class CompleteItemsOfWorkAlreadyHandedInMigrationTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offeringId;

    private int $unitId;

    private User $instructor;

    private User $ada;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offeringId = CourseFactory::offering();
        $this->unitId = CourseFactory::unit(CourseFactory::module($this->offeringId));
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offeringId, $this->instructor);
        $this->ada = CourseFactory::user('student');
        $this->bob = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $this->ada);
        CourseFactory::enroll($this->offeringId, $this->bob);
    }

    /**
     * Runs the migration, as `php spark migrate` does on deploy, and fails if any of its statements
     * failed. By default a failed statement only returns false and nothing throws, so a migration
     * that errors on deploy (a duplicate key, say) would otherwise look fine here.
     */
    private function migrate(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-10-07-000001_CompleteItemsOfWorkAlreadyHandedIn.php';

        (new App\Database\Migrations\CompleteItemsOfWorkAlreadyHandedIn())->up();

        $this->assertTrue(db_connect()->transStatus(), 'every statement of the migration ran without a database error');
    }

    /** A quiz or assignment: its learning item and its assessment, tied together. @return array{int, int} item id, assessment id */
    private function work(string $type = 'quiz'): array
    {
        [$item] = CourseFactory::items($this->unitId, [['type' => $type]]);
        $assessment = CourseFactory::assessment($this->offeringId, $this->instructor, null, $item, ucfirst($type));

        return [$item, $assessment];
    }

    private function completions(int $item, ?User $student = null): array
    {
        $builder = db_connect()->table('learning_completions')->where('learning_item_id', $item);

        if ($student !== null) {
            $builder->where('student_user_id', $student->id);
        }

        return $builder->orderBy('id')->get()->getResultArray();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideStatusesOfWorkHandedIn(): iterable
    {
        foreach (['Submitted', 'Grading', 'Partially Graded', 'Graded', 'Returned'] as $status) {
            yield $status => [$status];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideStatusesOfWorkHandedIn')]
    public function testAQuizHandedInIsCompleted(string $status): void
    {
        [$item, $assessment] = $this->work('quiz');
        AssessmentFactory::attempt($assessment, $this->ada, $status);

        $this->migrate();

        $rows = $this->completions($item, $this->ada);
        $this->assertCount(1, $rows);
        $this->assertSame('completed', $rows[0]['status']);
        $this->assertNotNull($rows[0]['completed_at']);
    }

    public function testAnAssignmentHandedInIsCompleted(): void
    {
        [$item, $assessment] = $this->work('assignment');
        AssessmentFactory::attempt($assessment, $this->ada, 'Submitted');

        $this->migrate();

        $this->assertSame('completed', $this->completions($item, $this->ada)[0]['status']);
    }

    public function testTheCompletionIsDatedAtTheFirstHandIn(): void
    {
        [$item, $assessment] = $this->work();
        AssessmentFactory::attempt($assessment, $this->ada, 'Graded', 0.0, ['submitted_at' => '2026-09-20 10:00:00']);
        AssessmentFactory::attempt($assessment, $this->ada, 'Graded', 0.0, ['submitted_at' => '2026-09-25 15:30:00']);

        $this->migrate();

        $rows = $this->completions($item, $this->ada);
        $this->assertCount(1, $rows, 'two attempts still make one completion');
        $this->assertSame('2026-09-20 10:00:00', $rows[0]['completed_at']);
    }

    public function testAnAttemptStillInProgressOrCancelledIsNotAHandIn(): void
    {
        [$item, $assessment] = $this->work();
        AssessmentFactory::attempt($assessment, $this->ada, 'In Progress');
        AssessmentFactory::attempt($assessment, $this->bob, 'Cancelled');

        $this->migrate();

        $this->assertSame([], $this->completions($item));
    }

    public function testOnlyTheStudentsWhoHandedWorkInAreCompleted(): void
    {
        [$item, $assessment] = $this->work();
        AssessmentFactory::attempt($assessment, $this->ada, 'Submitted');

        $this->migrate();

        $this->assertCount(1, $this->completions($item, $this->ada));
        $this->assertSame([], $this->completions($item, $this->bob));
    }

    public function testOtherItemsOfTheSameStudentAreNotTouched(): void
    {
        [$quizItem, $quiz] = $this->work('quiz');
        [$otherItem] = $this->work('assignment');
        [$reading] = CourseFactory::items($this->unitId, [['type' => 'reading']]);
        AssessmentFactory::attempt($quiz, $this->ada, 'Submitted');

        $this->migrate();

        $this->assertCount(1, $this->completions($quizItem));
        $this->assertSame([], $this->completions($otherItem));
        $this->assertSame([], $this->completions($reading));
    }

    public function testAnAssessmentWithNoLearningItemIsIgnored(): void
    {
        $assessment = CourseFactory::assessment($this->offeringId, $this->instructor);
        AssessmentFactory::attempt($assessment, $this->ada, 'Submitted');

        $this->migrate();

        $this->assertSame(0, db_connect()->table('learning_completions')->countAllResults());
    }

    public function testAnExistingCompletionIsLeftAsItIs(): void
    {
        [$item, $assessment] = $this->work();
        AssessmentFactory::attempt($assessment, $this->ada, 'Submitted', 0.0, ['submitted_at' => '2026-09-20 10:00:00']);
        db_connect()->table('learning_completions')->insert([
            'learning_item_id' => $item,
            'student_user_id' => $this->ada->id,
            'status' => 'completed',
            'completed_at' => '2026-09-22 08:00:00',
            'progress_percent' => 0,
            'created_at' => '2026-09-22 08:00:00',
            'updated_at' => '2026-09-22 08:00:00',
        ]);

        $this->migrate();

        $rows = $this->completions($item, $this->ada);
        $this->assertCount(1, $rows);
        $this->assertSame('2026-09-22 08:00:00', $rows[0]['completed_at'], 'a completion the student made is not re-dated');
    }

    public function testAHalfRecordedCompletionIsCompleted(): void
    {
        [$item, $assessment] = $this->work();
        AssessmentFactory::attempt($assessment, $this->ada, 'Submitted', 0.0, ['submitted_at' => '2026-09-20 10:00:00']);
        CourseFactory::progress($item, $this->ada, 'in_progress', 0);

        $this->migrate();

        $rows = $this->completions($item, $this->ada);
        $this->assertCount(1, $rows);
        $this->assertSame('completed', $rows[0]['status']);
        $this->assertSame('2026-09-20 10:00:00', $rows[0]['completed_at']);
    }

    public function testRunningItTwiceChangesNothingTheSecondTime(): void
    {
        [$item, $assessment] = $this->work();
        AssessmentFactory::attempt($assessment, $this->ada, 'Submitted');
        $this->migrate();
        $first = $this->completions($item);

        $this->migrate();

        $this->assertSame($first, $this->completions($item));
    }

    public function testItRunsOnAnEmptyDatabase(): void
    {
        $this->migrate();

        $this->assertSame(0, db_connect()->table('learning_completions')->countAllResults());
    }
}
