<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentFeedbackService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * A submission's feedback thread: the instructor can post any time; a student
 * can reply only to their own graded submission and only after the instructor
 * has written first; repeating the same grading note does not duplicate it.
 *
 * @internal
 */
final class AssessmentFeedbackServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssessmentFeedbackService $service;
    private User $instructor;
    private User $ada;
    private User $bob;
    private int $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AssessmentFeedbackService();
        $offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->ada = CourseFactory::user('student');
        $this->bob = CourseFactory::user('student');
        CourseFactory::enroll($offering, $this->ada);
        CourseFactory::enroll($offering, $this->bob);
        $this->quiz = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
    }

    private function thread(int $submissionId): array
    {
        return db_connect()->table('assessment_feedbacks')->where('submission_id', $submissionId)->orderBy('id')->get()->getResultArray();
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada);

        $this->assertSame(['success' => false, 'message' => 'Feedback message cannot be empty.'], $this->service->postMessage($this->instructor, $submission, "  \n "));
        $this->assertSame([], $this->thread($submission));
    }

    public function testAMissingSubmissionIsRefused(): void
    {
        $this->assertSame('Submission not found.', $this->service->postMessage($this->instructor, 999999, 'Hello')['message']);
    }

    public function testTheInstructorCanPostEvenBeforeTheWorkIsGraded(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'Submitted');

        $result = $this->service->postMessage($this->instructor, $submission, '  Nice start  ');

        $this->assertTrue($result['success']);
        $thread = $this->thread($submission);
        $this->assertCount(1, $thread);
        $this->assertSame('instructor', $thread[0]['author_role']);
        $this->assertSame('Nice start', $thread[0]['message']);
        $this->assertSame($this->instructor->id, (int) $thread[0]['author_id']);
        $this->assertCount(1, $result['thread']);
    }

    public function testAStudentCannotReplyBeforeTheWorkIsGraded(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'Submitted');

        $result = $this->service->postMessage($this->ada, $submission, 'Why?');

        $this->assertSame('You can reply once this submission has been graded.', $result['message']);
        $this->assertSame([], $this->thread($submission));
    }

    public function testAStudentCannotReplyUntilTheInstructorHasWritten(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 5);

        $result = $this->service->postMessage($this->ada, $submission, 'Why?');

        $this->assertStringContainsString("Wait for your instructor's feedback", $result['message']);
        $this->assertSame([], $this->thread($submission));
    }

    public function testAStudentRepliesAfterTheInstructorHasWrittenOnGradedWork(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 5);
        $this->service->postMessage($this->instructor, $submission, 'See question 3.');

        $result = $this->service->postMessage($this->ada, $submission, 'Thank you');

        $this->assertTrue($result['success']);
        $thread = $this->thread($submission);
        $this->assertSame(['instructor', 'student'], array_column($thread, 'author_role'));
        $this->assertCount(2, $result['thread']);
    }

    public function testAStudentCannotPostOnSomeoneElsesSubmission(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 5);
        $this->service->postMessage($this->instructor, $submission, 'Good');

        $result = $this->service->postMessage($this->bob, $submission, 'Not mine');

        $this->assertSame('You can only reply to your own submission.', $result['message']);
        $this->assertCount(1, $this->thread($submission));
    }

    public function testAppendingAGradingNoteAddsAnInstructorMessage(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 5);

        $this->service->appendInstructorMessage($this->instructor, $submission, '  Well done  ');

        $thread = $this->thread($submission);
        $this->assertCount(1, $thread);
        $this->assertSame('Well done', $thread[0]['message']);
        $this->assertSame('instructor', $thread[0]['author_role']);
    }

    public function testRepeatingTheSameGradingNoteDoesNotDuplicateItButANewOneIsAdded(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 5);

        $this->service->appendInstructorMessage($this->instructor, $submission, 'Well done');
        $this->service->appendInstructorMessage($this->instructor, $submission, ' Well done ');
        $this->assertCount(1, $this->thread($submission));

        $this->service->appendInstructorMessage($this->instructor, $submission, 'Updated after regrade');
        $this->assertCount(2, $this->thread($submission));
    }

    public function testABlankGradingNoteAddsNothing(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 5);

        $this->service->appendInstructorMessage($this->instructor, $submission, '   ');

        $this->assertSame([], $this->thread($submission));
    }

    public function testTheSameNoteAfterAStudentReplyIsStillNotDuplicated(): void
    {
        $submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 5);
        $this->service->appendInstructorMessage($this->instructor, $submission, 'Well done');
        $this->service->postMessage($this->ada, $submission, 'Thanks');

        $this->service->appendInstructorMessage($this->instructor, $submission, 'Well done');

        $this->assertCount(2, $this->thread($submission), 'the last instructor message is compared, not the last message');
    }
}
