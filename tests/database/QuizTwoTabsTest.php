<?php

declare(strict_types=1);

use App\Database\Migrations\OneResponsePerQuestion;
use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

require_once APPPATH . 'Database/Migrations/2026-11-16-000002_OneResponsePerQuestion.php';

/**
 * A quiz open in two tabs is one attempt; the last save of an answer wins; nothing lands after submit
 * (audit decisions 15.1, 15.2). The other tab is simulated by changing the database right after this tab
 * has read it.
 *
 * @internal
 */
final class QuizTwoTabsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $quiz;

    private int $question;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $offering      = CourseFactory::offering();
        $instructor    = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');
        CourseFactory::assignInstructor($offering, $instructor);
        CourseFactory::enroll($offering, $this->student);

        $this->quiz     = CourseFactory::assessment($offering, $instructor, CourseFactory::module($offering));
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 2]);
        $this->question = AssessmentFactory::question($this->quiz, $instructor, 'Essay', 5.0);
    }

    private function attempts(): array
    {
        return db_connect()->table('assessment_submissions')->where(['assessment_id' => $this->quiz, 'student_id' => $this->student->id])->get()->getResultArray();
    }

    /** A service whose next read is followed at once by the other tab's write. */
    private function racing(callable $otherTab): QuizAttemptService
    {
        return new class ($otherTab) extends QuizAttemptService {
            private $otherTab;

            public function __construct(callable $otherTab)
            {
                parent::__construct();
                $this->otherTab = $otherTab;
            }

            protected function inProgressAttempt(int $assessmentId, int $userId): ?array
            {
                $row = parent::inProgressAttempt($assessmentId, $userId);
                $this->race();

                return $row;
            }

            protected function submissionRow(int $submissionId): ?array
            {
                $row = parent::submissionRow($submissionId);
                $this->race();

                return $row;
            }

            private function race(): void
            {
                if ($this->otherTab !== null) {
                    ($this->otherTab)();
                    $this->otherTab = null;
                }
            }
        };
    }

    public function testASecondStartAtTheSameMomentResumesTheSameAttempt(): void
    {
        $other = null;

        $html = $this->racing(function () use (&$other): void {
            $other = AssessmentFactory::attempt($this->quiz, $this->student, 'In Progress');
        })->startAttempt($this->student, $this->quiz)['html'];

        $this->assertCount(1, $this->attempts(), 'one attempt');
        $this->assertStringContainsString('data-submission-id="' . $other . '"', $html, 'the same attempt resumed');
    }

    public function testTwoSavesOfOneQuestionLeaveOneRowWithTheLast(): void
    {
        $attempt = AssessmentFactory::attempt($this->quiz, $this->student, 'In Progress');

        // The other tab saves the same question right after this tab read the attempt.
        $this->racing(fn () => AssessmentFactory::response($attempt, $this->question, ['response_text' => 'first']))
            ->saveAnswer($this->student, $attempt, $this->question, ['response_text' => 'second']);

        $rows = db_connect()->table('assessment_question_responses')->where(['submission_id' => $attempt, 'question_id' => $this->question])->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('second', $rows[0]['response_text']);
    }

    public function testASaveAfterTheOtherTabSubmittedIsRefused(): void
    {
        $attempt = AssessmentFactory::attempt($this->quiz, $this->student, 'In Progress');

        $result = $this->racing(fn () => db_connect()->table('assessment_submissions')->where('id', $attempt)->update(['status' => 'Submitted', 'submitted_at' => date('Y-m-d H:i:s')]))
            ->saveAnswer($this->student, $attempt, $this->question, ['response_text' => 'too late']);

        $this->assertFalse($result['success']);
        $this->assertSame('This attempt is no longer in progress.', $result['message']);
        $this->assertSame(0, db_connect()->table('assessment_question_responses')->where('submission_id', $attempt)->countAllResults());
    }

    public function testASecondSubmitIsRefusedAndChangesNothing(): void
    {
        $attempt = AssessmentFactory::attempt($this->quiz, $this->student, 'In Progress');

        $result = $this->racing(fn () => db_connect()->table('assessment_submissions')->where('id', $attempt)->update(['status' => 'Graded', 'score' => 4, 'submitted_at' => '2026-10-01 08:00:00']))
            ->submitAttempt($this->student, $attempt);

        $this->assertFalse($result['success']);
        $this->assertSame('This attempt has already been submitted.', $result['message']);
        $row = db_connect()->table('assessment_submissions')->where('id', $attempt)->get()->getRowArray();
        $this->assertSame('Graded', $row['status']);
        $this->assertSame('2026-10-01 08:00:00', $row['submitted_at']);
    }

    public function testTheDatabaseKeepsOneAnswerPerQuestion(): void
    {
        $attempt = AssessmentFactory::attempt($this->quiz, $this->student, 'In Progress');
        AssessmentFactory::response($attempt, $this->question, ['response_text' => 'one']);

        try {
            $added = db_connect()->table('assessment_question_responses')->insert(['submission_id' => $attempt, 'question_id' => $this->question, 'response_text' => 'two', 'created_at' => date('Y-m-d H:i:s')]);
        } catch (DatabaseException) {
            $added = false;
        }

        $this->assertFalse($added);
        $this->assertSame(1, db_connect()->table('assessment_question_responses')->where('submission_id', $attempt)->countAllResults());
    }

    public function testTheMigrationKeepsTheNewestDuplicate(): void
    {
        $this->assertSame([4], OneResponsePerQuestion::duplicatesToDelete([
            ['id' => 4, 'submission_id' => 1, 'question_id' => 9, 'updated_at' => '2026-10-01 08:00:00'],
            ['id' => 5, 'submission_id' => 1, 'question_id' => 9, 'updated_at' => '2026-10-02 08:00:00'],
            ['id' => 6, 'submission_id' => 1, 'question_id' => 10, 'updated_at' => '2026-10-01 08:00:00'],
        ]));
    }
}
