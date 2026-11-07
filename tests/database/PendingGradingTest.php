<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\Grades\PendingGrading;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The one rule for work waiting to be graded: pending work that can still
 * change the grade (any ungraded quiz attempt, an assignment's latest
 * submission), from enrolled or completed students, on assessments that are
 * not archived. It must agree with the Assessments tab.
 *
 * @internal
 */
final class PendingGradingTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offering;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->student = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->student);
    }

    private function assessment(string $type = 'Quiz', string $status = 'Published', ?int $offering = null): int
    {
        $offering ??= $this->offering;
        $id = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering), null, $type);
        db_connect()->table('assessments')->where('id', $id)->update(['status' => $status]);

        return $id;
    }

    /** @return array<int, array<string, mixed>> keyed by assessment id */
    private function listed(int $offering): array
    {
        $out = [];

        foreach ((new PendingGrading())->forOfferings([$offering])['assessments'] as $row) {
            $out[$row['assessment_id']] = $row;
        }

        return $out;
    }

    public function testAnAssignmentCountsOnlyItsLatestSubmission(): void
    {
        $assignment = $this->assessment('Assignment');
        AssessmentFactory::attempt($assignment, $this->student, 'Submitted');
        AssessmentFactory::attempt($assignment, $this->student, 'Graded', 8);

        $result = (new PendingGrading())->forOfferings([$this->offering]);

        $this->assertSame([], $result['assessments']);
        $this->assertSame([$this->offering => 0], $result['byOffering']);

        AssessmentFactory::attempt($assignment, $this->student, 'Submitted');

        $result = (new PendingGrading())->forOfferings([$this->offering]);

        $this->assertSame(1, $result['assessments'][0]['waiting']);
        $this->assertSame($assignment, $result['assessments'][0]['assessment_id']);
        $this->assertSame($this->offering, $result['assessments'][0]['offering_id']);
        $this->assertSame('assignment', $result['assessments'][0]['type']);
        $this->assertSame((int) $this->student->id, $result['assessments'][0]['first_student_id']);
        $this->assertSame(1, $result['byOffering'][$this->offering]);
    }

    public function testEveryUngradedQuizAttemptCounts(): void
    {
        $quiz = $this->assessment('Quiz');
        AssessmentFactory::attempt($quiz, $this->student, 'Submitted');
        AssessmentFactory::attempt($quiz, $this->student, 'Submitted');

        $this->assertSame(2, $this->listed($this->offering)[$quiz]['waiting']);
        $this->assertSame('quiz', $this->listed($this->offering)[$quiz]['type']);
    }

    public function testArchivedAssessmentsAndDroppedStudentsAreLeftOut(): void
    {
        $archived = $this->assessment('Quiz', 'Archived');
        AssessmentFactory::attempt($archived, $this->student, 'Submitted');

        $live = $this->assessment('Quiz');
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $dropped, 'dropped');
        AssessmentFactory::attempt($live, $dropped, 'Submitted');

        $result = (new PendingGrading())->forOfferings([$this->offering]);

        $this->assertSame([], $result['assessments']);
        $this->assertSame(0, $result['byOffering'][$this->offering]);
    }

    public function testLateAndOldestAreReported(): void
    {
        $quiz = $this->assessment('Quiz');
        db_connect()->table('assessments')->where('id', $quiz)->update(['due_date' => '2026-10-01 23:59:00']);
        AssessmentFactory::attempt($quiz, $this->student, 'Submitted', 0, ['submitted_at' => '2026-10-02 08:00:00']);
        AssessmentFactory::attempt($quiz, $this->student, 'Submitted', 0, ['submitted_at' => '2026-09-30 08:00:00']);

        $row = $this->listed($this->offering)[$quiz];

        $this->assertSame(2, $row['waiting']);
        $this->assertSame(1, $row['late']);
        $this->assertSame(strtotime('2026-09-30 08:00:00'), $row['oldest_at']);
    }

    public function testItAgreesWithTheAssessmentsTab(): void
    {
        $second = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $second);

        $quiz = $this->assessment('Quiz');
        db_connect()->table('assessments')->where('id', $quiz)->update(['due_date' => '2026-10-01 23:59:00']);
        AssessmentFactory::attempt($quiz, $this->student, 'Submitted', 0, ['submitted_at' => '2026-10-02 08:00:00']);
        AssessmentFactory::attempt($quiz, $this->student, 'Submitted', 0, ['submitted_at' => '2026-09-30 08:00:00']);

        $assignment = $this->assessment('Assignment');
        AssessmentFactory::attempt($assignment, $second, 'Submitted');
        AssessmentFactory::attempt($assignment, $second, 'Graded', 5);

        $archived = $this->assessment('Quiz', 'Archived');
        AssessmentFactory::attempt($archived, $second, 'Submitted');

        $courseId = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'];
        $tab = (new AssessmentOverviewService())->build($this->instructor, $this->offering, $courseId);
        $listed = $this->listed($this->offering);

        $this->assertNotEmpty($tab['assessments']);

        foreach ($tab['assessments'] as $row) {
            if ($row['status'] === 'Archived') {
                continue;
            }

            $this->assertSame($listed[(int) $row['id']]['waiting'] ?? 0, $row['section']['toGrade'], "assessment {$row['id']}");
        }
    }

    public function testTheQueryCountDoesNotGrowWithSections(): void
    {
        $offerings = [$this->offering];

        for ($i = 0; $i < 7; $i++) {
            $offering = CourseFactory::offering('S' . $i);
            $student = CourseFactory::user('student');
            CourseFactory::enroll($offering, $student);
            AssessmentFactory::attempt($this->assessment('Quiz', 'Published', $offering), $student, 'Submitted');
            $offerings[] = $offering;
        }

        $service = new PendingGrading();
        $service->forOfferings($offerings); // warm up

        $two = $this->queriesOf(static fn () => $service->forOfferings(array_slice($offerings, 0, 2)));
        $eight = $this->queriesOf(static fn () => $service->forOfferings($offerings));

        $this->assertGreaterThan(0, $two);
        $this->assertSame($two, $eight);
    }

    private function queriesOf(callable $work): int
    {
        $queries = 0;

        $listener = static function () use (&$queries): void {
            $queries++;
        };

        Events::on('DBQuery', $listener);
        $work();
        Events::removeListener('DBQuery', $listener);

        return $queries;
    }
}
