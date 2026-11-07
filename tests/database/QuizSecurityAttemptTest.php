<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentService;
use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * What a student meets when the quiz has the settings that are enforced: a password to enter before any attempt is
 * made (and a slowdown for guessing), and the questions in a shuffled order that is fixed for the attempt.
 *
 * @internal
 */
final class QuizSecurityAttemptTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuizAttemptService $service;

    private int $offering;

    private int $quiz;

    private User $instructor;

    private User $ada;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new QuizAttemptService();
        $this->offering   = CourseFactory::offering();
        $module           = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->ada = CourseFactory::user('student');
        $this->bob = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->ada);
        CourseFactory::enroll($this->offering, $this->bob);

        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, $module);
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 3]);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->quiz, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function withPassword(string $password = 'Blue-Heron-42'): void
    {
        $this->assertTrue((new AssessmentService())->setQuizPassword($this->instructor, $this->quiz, $password)['success']);
    }

    private function attempts(User $student): array
    {
        return db_connect()->table('assessment_submissions')->where(['assessment_id' => $this->quiz, 'student_id' => $student->id])->orderBy('id')->get()->getResultArray();
    }

    private function start(User $student, ?string $password = null): array
    {
        return $this->service->startAttempt($student, $this->quiz, $this->offering, $password);
    }

    /** @return list<string> the question texts, in the order the attempt holds them */
    private function order(array $attempt): array
    {
        return array_column(json_decode((string) $attempt['questions_snapshot'], true), 'question_text');
    }

    /*
    |--------------------------------------------------------------------------
    | Password
    |--------------------------------------------------------------------------
    */

    public function testWithoutThePasswordNoAttemptIsMadeAndThePromptIsShown(): void
    {
        $this->withPassword();
        AssessmentFactory::question($this->quiz, $this->instructor, 'Essay');

        $result = $this->start($this->ada);

        $this->assertTrue($result['passwordRequired']);
        $this->assertNull($result['passwordError'], 'asking is not a failure');
        $this->assertSame([], $this->attempts($this->ada), 'no attempt is started, so none is used up');
        $this->assertStringContainsString('data-quiz-password-form', $result['html']);
        $this->assertStringNotContainsString('Essay question', $result['html'], 'the questions are not sent before the password');
    }

    public function testAWrongPasswordStartsNothingAndSaysSo(): void
    {
        $this->withPassword();

        $result = $this->start($this->ada, 'wrong-guess');

        $this->assertTrue($result['passwordRequired']);
        $this->assertSame('That password is not right.', $result['passwordError']);
        $this->assertSame([], $this->attempts($this->ada));
        $this->assertStringContainsString('That password is not right.', $result['html']);
        $this->assertSame(1, db_connect()->table('quiz_password_failures')->where('student_user_id', $this->ada->id)->countAllResults());
    }

    public function testTheRightPasswordStartsTheAttemptSpacesAroundItDoNotMatter(): void
    {
        $this->withPassword();

        $result = $this->start($this->ada, '  Blue-Heron-42 ');

        $this->assertFalse($result['passwordRequired']);
        $this->assertCount(1, $this->attempts($this->ada));
        $this->assertSame('In Progress', $this->attempts($this->ada)[0]['status']);
        $this->assertStringNotContainsString('data-quiz-password-form', $result['html']);
    }

    public function testACaseThatDoesNotMatchIsWrong(): void
    {
        $this->withPassword();

        $this->assertTrue($this->start($this->ada, 'blue-heron-42')['passwordRequired']);
        $this->assertSame([], $this->attempts($this->ada));
    }

    public function testAnAttemptAlreadyRunningResumesWithoutAskingAgain(): void
    {
        $this->withPassword();
        $this->start($this->ada, 'Blue-Heron-42');

        $again = $this->start($this->ada);

        $this->assertFalse($again['passwordRequired']);
        $this->assertCount(1, $this->attempts($this->ada), 'the same attempt');
    }

    public function testAnotherStudentStillNeedsThePassword(): void
    {
        $this->withPassword();
        $this->start($this->ada, 'Blue-Heron-42');

        $this->assertTrue($this->start($this->bob)['passwordRequired']);
        $this->assertSame([], $this->attempts($this->bob));
    }

    public function testFiveWrongGuessesLockThatStudentOutEvenForTheRightPassword(): void
    {
        $this->withPassword();

        for ($i = 0; $i < 5; $i++) {
            $this->start($this->ada, 'guess-' . $i);
        }

        $locked = $this->start($this->ada, 'Blue-Heron-42');

        $this->assertTrue($locked['passwordRequired']);
        $this->assertStringContainsString('Too many wrong passwords', (string) $locked['passwordError']);
        $this->assertSame([], $this->attempts($this->ada), 'the right password does not get in while locked');

        $this->assertFalse($this->start($this->bob, 'Blue-Heron-42')['passwordRequired'], 'it is per student');
    }

    public function testOldFailuresDoNotCountAndARightPasswordClearsThem(): void
    {
        $this->withPassword();

        for ($i = 0; $i < 5; $i++) {
            db_connect()->table('quiz_password_failures')->insert(['assessment_id' => $this->quiz, 'student_user_id' => $this->ada->id, 'failed_at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))]);
        }

        $this->assertFalse($this->start($this->ada, 'Blue-Heron-42')['passwordRequired'], 'five failures half an hour ago are over');
        $this->assertSame(0, db_connect()->table('quiz_password_failures')->where('student_user_id', $this->ada->id)->countAllResults(), 'a right password clears what is left');
    }

    public function testAQuizWithoutAPasswordNeverAsks(): void
    {
        $result = $this->start($this->ada);

        $this->assertFalse($result['passwordRequired']);
        $this->assertCount(1, $this->attempts($this->ada));
    }

    public function testTheTeacherIsNotAskedForThePassword(): void
    {
        $this->withPassword();

        $this->assertFalse($this->start($this->instructor)['passwordRequired']);
    }

    /*
    |--------------------------------------------------------------------------
    | Randomized questions
    |--------------------------------------------------------------------------
    */

    private function twelveQuestions(): array
    {
        $texts = [];

        foreach (range(1, 12) as $i) {
            $id = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1.0, ['question_text' => 'Q' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
            $texts[] = 'Q' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            unset($id);
        }

        return $texts;
    }

    public function testWithRandomizeOffTheQuestionsKeepTheQuizOrder(): void
    {
        $texts = $this->twelveQuestions();

        $this->start($this->ada);

        $this->assertSame($texts, $this->order($this->attempts($this->ada)[0]));
    }

    public function testWithRandomizeOnTheSameQuestionsComeInAnotherOrderForEachAttempt(): void
    {
        $texts = $this->twelveQuestions();
        db_connect()->table('assessment_settings')->where('assessment_id', $this->quiz)->update(['randomize_questions' => 1]);

        $this->start($this->ada);
        $this->start($this->bob);

        $ada = $this->order($this->attempts($this->ada)[0]);
        $bob = $this->order($this->attempts($this->bob)[0]);

        $this->assertEqualsCanonicalizing($texts, $ada, 'every question, once');
        $this->assertEqualsCanonicalizing($texts, $bob);
        $this->assertNotSame($texts, $ada, 'shuffled (a 1 in 479 million chance of the same order)');
        $this->assertNotSame($ada, $bob, 'each student has their own order');
    }

    public function testAShuffledAttemptKeepsItsOrderWhenItIsResumed(): void
    {
        $this->twelveQuestions();
        db_connect()->table('assessment_settings')->where('assessment_id', $this->quiz)->update(['randomize_questions' => 1]);

        $this->start($this->ada);
        $first = $this->order($this->attempts($this->ada)[0]);

        $this->start($this->ada);
        $this->start($this->ada);

        $this->assertCount(1, $this->attempts($this->ada));
        $this->assertSame($first, $this->order($this->attempts($this->ada)[0]), 'reopening does not reshuffle');
    }

    public function testAPasswordAndShufflingWorkTogether(): void
    {
        $texts = $this->twelveQuestions();
        $this->withPassword();
        db_connect()->table('assessment_settings')->where('assessment_id', $this->quiz)->update(['randomize_questions' => 1]);

        $this->assertTrue($this->start($this->ada)['passwordRequired']);
        $this->start($this->ada, 'Blue-Heron-42');

        $this->assertEqualsCanonicalizing($texts, $this->order($this->attempts($this->ada)[0]));
    }
}
