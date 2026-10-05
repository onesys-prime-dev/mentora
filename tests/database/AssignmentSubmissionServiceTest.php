<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssignmentSubmissionService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Handing in an assignment: which ways of submitting the assignment allows,
 * who may submit, the open/closed/late rules, the submission limit, link
 * validation, and what a submission is recorded as.
 *
 * @internal
 */
final class AssignmentSubmissionServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssignmentSubmissionService $service;
    private int $offeringId;
    private int $moduleId;
    private int $assignment;
    private User $instructor;
    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AssignmentSubmissionService();
        $this->offeringId = CourseFactory::offering();
        $this->moduleId = CourseFactory::module($this->offeringId);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offeringId, $this->instructor);
        $this->ada = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $this->ada);

        $this->assignment = $this->assignment();
    }

    /** An open assignment accepting text and links, one submission. */
    private function assignment(array $assessment = [], array $settings = []): int
    {
        $id = CourseFactory::assessment($this->offeringId, $this->instructor, $this->moduleId, null, 'Assignment');
        AssessmentFactory::open($id, $assessment + ['attempt_limit' => 1]);
        db_connect()->table('assessment_settings')->insert($settings + [
            'assessment_id' => $id,
            'allow_file_upload' => 0,
            'allow_text_entry' => 1,
            'allow_link_entry' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function submissions(?int $assignment = null, ?User $student = null): array
    {
        return db_connect()->table('assessment_submissions')
            ->where('assessment_id', $assignment ?? $this->assignment)->where('student_id', ($student ?? $this->ada)->id)
            ->orderBy('id')->get()->getResultArray();
    }

    private function submit(array $data, ?int $assignment = null, ?User $student = null, ?UploadedFile $file = null): array
    {
        return $this->service->submit($student ?? $this->ada, $assignment ?? $this->assignment, $data, $file);
    }

    /*
    |--------------------------------------------------------------------------
    | What is recorded
    |--------------------------------------------------------------------------
    */

    public function testATextSubmissionIsRecordedAsSubmitted(): void
    {
        $result = $this->submit(['submission_text' => '  My essay  ']);

        $this->assertSame(['success' => true, 'message' => 'Your work was submitted.'], $result);
        $row = $this->submissions()[0];
        $this->assertSame('My essay', $row['submission_text']);
        $this->assertNull($row['submission_link']);
        $this->assertSame('Submitted', $row['status']);
        $this->assertSame(0, (int) $row['is_graded']);
        $this->assertSame(1, (int) $row['attempt_number']);
        $this->assertSame(0, (int) $row['is_late_submission']);
        $this->assertNotNull($row['submitted_at']);
    }

    public function testALinkSubmissionIsRecorded(): void
    {
        $this->submit(['submission_link' => ' https://example.com/my-work ']);

        $row = $this->submissions()[0];
        $this->assertSame('https://example.com/my-work', $row['submission_link']);
        $this->assertNull($row['submission_text']);
    }

    public function testTextAndLinkCanGoTogether(): void
    {
        $this->submit(['submission_text' => 'See the link', 'submission_link' => 'https://example.com']);

        $row = $this->submissions()[0];
        $this->assertSame('See the link', $row['submission_text']);
        $this->assertSame('https://example.com', $row['submission_link']);
    }

    public function testNothingToSubmitIsRefused(): void
    {
        $result = $this->submit(['submission_text' => '   ']);

        $this->assertSame(['success' => false, 'message' => 'Add your work before submitting.'], $result);
        $this->assertSame([], $this->submissions());
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function links(): iterable
    {
        yield 'https' => ['https://example.com/a?b=1', true];
        yield 'http' => ['http://example.com', true];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'ftp' => ['ftp://example.com/file', false];
        yield 'no scheme' => ['example.com/work', false];
        yield 'words' => ['my work is on drive', false];
    }

    #[DataProvider('links')]
    public function testOnlyWebLinksAreAccepted(string $link, bool $accepted): void
    {
        $result = $this->submit(['submission_link' => $link]);

        $this->assertSame($accepted, $result['success'], $link);
        $this->assertCount($accepted ? 1 : 0, $this->submissions());

        if (! $accepted) {
            $this->assertSame('Enter a full link starting with http:// or https://.', $result['message']);
        }
    }

    public function testASubmissionAfterTheDueDateWhereLateIsAllowedIsMarkedLate(): void
    {
        $late = $this->assignment(['due_date' => date('Y-m-d H:i:s', strtotime('-1 hour'))], ['allow_late_submission' => 1]);

        $this->assertTrue($this->submit(['submission_text' => 'Sorry'], $late)['success']);

        $this->assertSame(1, (int) $this->submissions($late)[0]['is_late_submission']);
    }

    /*
    |--------------------------------------------------------------------------
    | Which ways of submitting are allowed
    |--------------------------------------------------------------------------
    */

    public function testTextEntryMustBeSwitchedOn(): void
    {
        $linkOnly = $this->assignment([], ['allow_text_entry' => 0]);

        $result = $this->submit(['submission_text' => 'Essay'], $linkOnly);

        $this->assertSame("Text entry isn't allowed for this assignment.", $result['message']);
        $this->assertSame([], $this->submissions($linkOnly));
    }

    public function testLinkEntryMustBeSwitchedOn(): void
    {
        $textOnly = $this->assignment([], ['allow_link_entry' => 0]);

        $this->assertSame("Link submission isn't allowed for this assignment.", $this->submit(['submission_link' => 'https://example.com'], $textOnly)['message']);
    }

    public function testNoWayToSubmitMeansNoSubmission(): void
    {
        $none = $this->assignment([], ['allow_text_entry' => 0, 'allow_link_entry' => 0, 'allow_file_upload' => 0]);

        $result = $this->submit(['submission_text' => 'Essay'], $none);

        $this->assertFalse($result['success']);
        $this->assertSame("Your instructor hasn't turned on a way to submit yet.", $result['message']);
    }

    public function testAnUploadFailureIsReportedAndNothingIsSaved(): void
    {
        $withFiles = $this->assignment([], ['allow_file_upload' => 1]);
        $broken = new UploadedFile('', 'work.pdf', 'application/pdf', 0, UPLOAD_ERR_INI_SIZE);

        $result = $this->submit([], $withFiles, null, $broken);

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith("The file could not be uploaded:", $result['message']);
        $this->assertSame([], $this->submissions($withFiles));
    }

    public function testAFileFieldWithNoFileInItIsNotAnUpload(): void
    {
        $withFiles = $this->assignment([], ['allow_file_upload' => 1]);
        $empty = new UploadedFile('', '', '', 0, UPLOAD_ERR_NO_FILE);

        $result = $this->submit(['submission_text' => 'Just text'], $withFiles, null, $empty);

        $this->assertTrue($result['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | Who and when
    |--------------------------------------------------------------------------
    */

    public function testAQuizIsNotAnAssignment(): void
    {
        $quiz = CourseFactory::assessment($this->offeringId, $this->instructor, $this->moduleId);
        AssessmentFactory::open($quiz);

        $this->assertSame(['success' => false, 'message' => 'Assignment not found.'], $this->submit(['submission_text' => 'Hi'], $quiz));
    }

    public function testAnAssignmentThatDoesNotExistIsNotFound(): void
    {
        $this->assertSame('Assignment not found.', $this->submit(['submission_text' => 'Hi'], 999999)['message']);
    }

    public function testOnlyEnrolledStudentsCanSubmit(): void
    {
        $outsider = CourseFactory::user('student');
        CourseFactory::enroll(CourseFactory::offering('B'), $outsider);
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $dropped, 'dropped');

        foreach ([$outsider, $dropped, $this->instructor] as $user) {
            $result = $this->submit(['submission_text' => 'Hi'], null, $user);

            $this->assertSame('Only students enrolled in this course can submit.', $result['message']);
        }

        $this->assertSame(0, db_connect()->table('assessment_submissions')->where('assessment_id', $this->assignment)->countAllResults());
    }

    /** @return iterable<string, array{0: array, 1: string}> */
    public static function blockedStates(): iterable
    {
        yield 'a draft' => [['status' => 'Draft'], "This assignment isn't open for submissions yet."];
        yield 'closed' => [['status' => 'Closed'], 'This assignment is closed.'];
        yield 'not open yet' => [['available_from' => '+2 days'], 'Submissions open '];
        yield 'availability ended' => [['available_until' => '-1 hour'], 'Submissions closed '];
        yield 'past due, no late submissions' => [['due_date' => '-1 hour'], "The due date has passed and late submissions aren't allowed."];
    }

    #[DataProvider('blockedStates')]
    public function testSubmissionsAreRefusedWhenTheAssignmentIsNotOpen(array $change, string $messageStart): void
    {
        $change = array_map(static fn ($v) => str_starts_with((string) $v, '+') || str_starts_with((string) $v, '-') ? date('Y-m-d H:i:s', strtotime($v)) : $v, $change);
        $blocked = $this->assignment($change);

        $result = $this->submit(['submission_text' => 'Hi'], $blocked);

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith($messageStart, $result['message']);
        $this->assertSame([], $this->submissions($blocked));
    }

    public function testTheSubmissionLimitIsEnforcedAndNumbered(): void
    {
        $twice = $this->assignment(['attempt_limit' => 2]);

        $this->assertTrue($this->submit(['submission_text' => 'One'], $twice)['success']);
        $this->assertTrue($this->submit(['submission_text' => 'Two'], $twice)['success']);
        $third = $this->submit(['submission_text' => 'Three'], $twice);

        $this->assertFalse($third['success']);
        $this->assertSame("You've used all 2 submissions.", $third['message']);
        $this->assertSame([1, 2], array_map('intval', array_column($this->submissions($twice), 'attempt_number')));
    }

    public function testASingleSubmissionAssignmentSaysSoWhenYouTryAgain(): void
    {
        $this->submit(['submission_text' => 'One']);

        $this->assertSame("You've already submitted this assignment.", $this->submit(['submission_text' => 'Two'])['message']);
        $this->assertCount(1, $this->submissions());
    }

    public function testAnItemBehindAnUnfinishedPrerequisiteCannotBeSubmitted(): void
    {
        $unit = CourseFactory::unit($this->moduleId);
        [$first, $second] = CourseFactory::items($unit, [[], ['type' => 'assignment', 'mode' => 'previous']]);
        db_connect()->table('assessments')->where('id', $this->assignment)->update(['learning_item_id' => $second]);

        $this->assertFalse($this->submit(['submission_text' => 'Early'])['success']);
        $this->assertSame([], $this->submissions());

        CourseFactory::progress($first, $this->ada);
        $this->assertTrue($this->submit(['submission_text' => 'On time'])['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | The student's view of it
    |--------------------------------------------------------------------------
    */

    public function testTheStateShowsAttemptsLimitAndWhatIsAllowed(): void
    {
        $twice = $this->assignment(['attempt_limit' => 2], ['allow_link_entry' => 0]);
        $this->submit(['submission_text' => 'One'], $twice);

        $state = $this->service->getState($this->ada, $twice);

        $this->assertSame(1, $state['attemptsUsed']);
        $this->assertSame(2, $state['attemptLimit']);
        $this->assertSame(['file' => false, 'text' => true, 'link' => false], $state['allowed']);
        $this->assertTrue($state['canSubmit']);
        $this->assertNull($state['reason']);
        $this->assertSame('One', $state['latest']['submission_text']);
        $this->assertNull($state['counted'], 'not graded yet');
    }

    public function testTheLatestGradedSubmissionIsTheOneThatCounts(): void
    {
        $twice = $this->assignment(['attempt_limit' => 3]);
        AssessmentFactory::attempt($twice, $this->ada, 'Graded', 18);
        AssessmentFactory::attempt($twice, $this->ada, 'Graded', 12);
        AssessmentFactory::attempt($twice, $this->ada, 'Submitted');

        $state = $this->service->getState($this->ada, $twice);

        $this->assertEquals(12, $state['counted']['score']);
        $this->assertTrue($state['result']['resubmitted']);
        $this->assertSame(3, $state['attemptsUsed']);
        $this->assertSame('Submitted', $state['latest']['status'], 'newest first');
    }

    public function testAnInProgressRowIsNotASubmission(): void
    {
        AssessmentFactory::attempt($this->assignment, $this->ada, 'In Progress');

        $this->assertSame(0, $this->service->getState($this->ada, $this->assignment)['attemptsUsed']);
    }

    public function testAMissingAssignmentHasAReasonNotACrash(): void
    {
        $state = $this->service->getState($this->ada, 999999);

        $this->assertFalse($state['canSubmit']);
        $this->assertSame('This assignment could not be found.', $state['reason']);
    }
}
