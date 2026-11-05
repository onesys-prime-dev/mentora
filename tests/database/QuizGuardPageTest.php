<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * What the quiz page tells its script to watch, and tells the student plainly about it: nothing when the security
 * options are off, and when they are on a notice that says what is recorded (that they left, not where) and what
 * the lock really does (asks for fullscreen, blocks copying; it discourages, it cannot prevent).
 *
 * @internal
 */
final class QuizGuardPageTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuizAttemptService $service;

    private int $offering;

    private int $quiz;

    private User $ada;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new QuizAttemptService();
        $this->offering   = CourseFactory::offering();
        $module           = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->ada = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->ada);

        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, $module);
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 3]);
        AssessmentFactory::question($this->quiz, $this->instructor, 'Essay');
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->quiz, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @param array<string, int> $settings */
    private function html(User $user, array $settings): string
    {
        db_connect()->table('assessment_settings')->where('assessment_id', $this->quiz)->update($settings);

        return $this->service->startAttempt($user, $this->quiz, $this->offering)['html'];
    }

    public function testWithBothOffTheFormCarriesNothingToWatchAndThereIsNoNotice(): void
    {
        $html = $this->html($this->ada, ['detect_tab_switch' => 0, 'browser_lockdown' => 0]);

        $this->assertStringContainsString('id="quiz-answer-form"', $html);
        $this->assertStringNotContainsString('data-detect-tab-switch', $html);
        $this->assertStringNotContainsString('data-lock-browser', $html);
        $this->assertStringNotContainsString('data-quiz-guard-notice', $html);
    }

    public function testRecordingLeavingIsOnTheFormAndSaysWhatIsRecordedAndWhatIsNot(): void
    {
        $html = $this->html($this->ada, ['detect_tab_switch' => 1, 'browser_lockdown' => 0]);

        $this->assertMatchesRegularExpression('/<form[^>]*id="quiz-answer-form"[^>]*data-detect-tab-switch="1"/s', $html);
        $this->assertStringNotContainsString('data-lock-browser', $html);
        $this->assertStringContainsString('data-quiz-guard-notice', $html);
        $this->assertStringContainsString('records when you leave this page', $html);
        $this->assertStringContainsString('not where you went', $html, 'it does not claim to see the site');
        $this->assertStringNotContainsString('data-guard-fullscreen', $html, 'no fullscreen button without the lock');
    }

    public function testTheLockIsOnTheFormAndSaysWhatItDoesAndThatItCannotPrevent(): void
    {
        $html = $this->html($this->ada, ['detect_tab_switch' => 0, 'browser_lockdown' => 1]);

        $this->assertMatchesRegularExpression('/<form[^>]*id="quiz-answer-form"[^>]*data-lock-browser="1"/s', $html);
        $this->assertStringContainsString('fullscreen', $html);
        $this->assertStringContainsString('copying, pasting and printing', $html);
        $this->assertStringContainsString('data-guard-fullscreen', $html);
        $this->assertStringNotContainsString('data-detect-tab-switch', $html);
    }

    public function testTheTeacherPreviewingGetsNoGuard(): void
    {
        $html = $this->html($this->instructor, ['detect_tab_switch' => 1, 'browser_lockdown' => 1]);

        $this->assertStringNotContainsString('data-detect-tab-switch', $html);
        $this->assertStringNotContainsString('data-lock-browser', $html);
        $this->assertStringNotContainsString('data-quiz-guard-notice', $html);
    }
}
