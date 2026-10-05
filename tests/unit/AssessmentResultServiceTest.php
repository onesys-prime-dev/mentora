<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentResultService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Which attempt counts toward a grade: the best graded attempt for a quiz
 * (a tie goes to the later one), the latest graded submission for an
 * assignment, with ungraded and reopened work pending and a resubmission
 * leaving the previous grade standing.
 *
 * @internal
 */
final class AssessmentResultServiceTest extends CIUnitTestCase
{
    private AssessmentResultService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AssessmentResultService();
    }

    /** One attempt; graded unless told otherwise. */
    private function attempt(int $id, float $score, string $status = 'Graded', ?bool $graded = null): array
    {
        return [
            'id' => $id,
            'score' => $score,
            'status' => $status,
            'is_graded' => $graded ?? in_array($status, ['Graded', 'Returned'], true) ? 1 : 0,
        ];
    }

    private function ids(array $attempts): array
    {
        return array_map(static fn (array $a) => $a['id'], $attempts);
    }

    public function testNoAttemptsMeansNothingCounts(): void
    {
        $result = $this->service->resultFor(true, []);

        $this->assertNull($result['counted']);
        $this->assertNull($result['score']);
        $this->assertNull($result['latest']);
        $this->assertNull($result['partial']);
        $this->assertSame([], $result['pending']);
        $this->assertSame([], $result['finished']);
        $this->assertFalse($result['resubmitted']);
    }

    public function testPoliciesAreNamed(): void
    {
        $this->assertSame('highest', $this->service->resultFor(true, [])['policy']);
        $this->assertSame('latest', $this->service->resultFor(false, [])['policy']);
    }

    /*
    |--------------------------------------------------------------------------
    | Quiz: highest graded attempt
    |--------------------------------------------------------------------------
    */

    public function testAQuizCountsTheHighestGradedAttempt(): void
    {
        $result = $this->service->resultFor(true, [$this->attempt(1, 6), $this->attempt(2, 9), $this->attempt(3, 7)]);

        $this->assertSame(2, $result['counted']['id']);
        $this->assertSame(9.0, $result['score']);
        $this->assertSame(3, $result['latest']['id']);
    }

    public function testATieGoesToTheMoreRecentAttempt(): void
    {
        $result = $this->service->resultFor(true, [$this->attempt(1, 8), $this->attempt(2, 8)]);

        $this->assertSame(2, $result['counted']['id']);
    }

    public function testAttemptsAreJudgedByIdNotByTheOrderGiven(): void
    {
        $result = $this->service->resultFor(true, [$this->attempt(3, 5), $this->attempt(1, 5), $this->attempt(2, 5)]);

        $this->assertSame(3, $result['counted']['id']);
        $this->assertSame([1, 2, 3], $this->ids($result['finished']));
    }

    public function testAnUngradedQuizAttemptIsPendingAndNeverCountsYet(): void
    {
        $result = $this->service->resultFor(true, [$this->attempt(1, 4), $this->attempt(2, 0, 'Submitted')]);

        $this->assertSame(1, $result['counted']['id'], 'the graded attempt still counts');
        $this->assertSame([2], $this->ids($result['pending']));
        $this->assertFalse($result['resubmitted'], 'resubmitted is an assignment idea');
    }

    public function testOnlyUngradedAttemptsMeansNothingCountsYet(): void
    {
        $result = $this->service->resultFor(true, [$this->attempt(1, 0, 'Submitted')]);

        $this->assertNull($result['counted']);
        $this->assertNull($result['score']);
        $this->assertSame([1], $this->ids($result['pending']));
    }

    public function testAZeroScoreStillCountsAsAGrade(): void
    {
        $result = $this->service->resultFor(true, [$this->attempt(1, 0)]);

        $this->assertNotNull($result['counted']);
        $this->assertSame(0.0, $result['score']);
    }

    public function testAReopenedGradeIsPendingAndTheNextBestCounts(): void
    {
        // Attempt 2 was the best, then the instructor reopened it for grading.
        $result = $this->service->resultFor(true, [$this->attempt(1, 6), $this->attempt(2, 9, 'Grading', false)]);

        $this->assertSame(1, $result['counted']['id']);
        $this->assertSame([2], $this->ids($result['pending']));
    }

    public function testAnAttemptThatWasNeverHandedInIsIgnored(): void
    {
        $result = $this->service->resultFor(true, [
            $this->attempt(1, 3),
            $this->attempt(2, 0, 'In Progress'),
            $this->attempt(3, 9, 'Cancelled'),
        ]);

        $this->assertSame([1], $this->ids($result['finished']));
        $this->assertSame(1, $result['counted']['id']);
        $this->assertSame([], $result['pending']);
    }

    public function testReturnedWorkCountsLikeGradedWork(): void
    {
        $result = $this->service->resultFor(true, [$this->attempt(1, 7, 'Returned')]);

        $this->assertSame(1, $result['counted']['id']);
    }

    public function testAPartlyAutoGradedQuizExposesItsScoreSoFarButDoesNotCount(): void
    {
        $result = $this->service->resultFor(true, [$this->attempt(1, 5, 'Partially Graded')]);

        $this->assertNull($result['counted']);
        $this->assertSame(1, $result['partial']['id']);
        $this->assertSame([1], $this->ids($result['pending']));
    }

    public function testThePartialScoreIsTheLatestPartlyGradedAttempt(): void
    {
        $result = $this->service->resultFor(true, [$this->attempt(1, 5, 'Partially Graded'), $this->attempt(2, 8, 'Partially Graded')]);

        $this->assertSame(2, $result['partial']['id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Assignment: latest graded submission
    |--------------------------------------------------------------------------
    */

    public function testAnAssignmentCountsTheLatestGradedSubmissionEvenWhenLower(): void
    {
        $result = $this->service->resultFor(false, [$this->attempt(1, 18), $this->attempt(2, 11)]);

        $this->assertSame(2, $result['counted']['id']);
        $this->assertSame(11.0, $result['score']);
        $this->assertFalse($result['resubmitted']);
        $this->assertSame([], $result['pending']);
    }

    public function testANewSubmissionWaitingToBeGradedLeavesThePreviousGradeStanding(): void
    {
        $result = $this->service->resultFor(false, [$this->attempt(1, 15), $this->attempt(2, 0, 'Submitted')]);

        $this->assertSame(1, $result['counted']['id']);
        $this->assertSame(15.0, $result['score']);
        $this->assertTrue($result['resubmitted']);
        $this->assertSame([2], $this->ids($result['pending']));
    }

    public function testTheFirstSubmissionWaitingIsPendingButNotResubmitted(): void
    {
        $result = $this->service->resultFor(false, [$this->attempt(1, 0, 'Submitted')]);

        $this->assertNull($result['counted']);
        $this->assertFalse($result['resubmitted']);
        $this->assertSame([1], $this->ids($result['pending']));
    }

    public function testOnlyTheLatestUngradedSubmissionIsPendingForAnAssignment(): void
    {
        $result = $this->service->resultFor(false, [$this->attempt(1, 0, 'Submitted'), $this->attempt(2, 0, 'Submitted')]);

        $this->assertSame([2], $this->ids($result['pending']));
    }

    public function testAnAssignmentGradedAfterTheNewerOneIsReopenedStillCountsTheOlderGrade(): void
    {
        $result = $this->service->resultFor(false, [$this->attempt(1, 12), $this->attempt(2, 19, 'Grading', false)]);

        $this->assertSame(1, $result['counted']['id']);
        $this->assertTrue($result['resubmitted']);
    }

    public function testAPartlyGradedAssignmentSubmissionExposesItsPartialScore(): void
    {
        $result = $this->service->resultFor(false, [$this->attempt(1, 3, 'Partially Graded')]);

        $this->assertSame(1, $result['partial']['id']);
        $this->assertNull($result['counted']);
    }
}
