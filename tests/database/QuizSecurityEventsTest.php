<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * What the quiz page reports while a student answers: that they left the page and for how long, that they left
 * fullscreen, that they tried to copy, paste, print or open a menu. A page cannot say WHERE they went, so nothing
 * like that is recorded. Only the owner of a running attempt can report, only what the quiz has switched on is
 * kept, and a flood is cut off.
 *
 * @internal
 */
final class QuizSecurityEventsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuizAttemptService $service;

    private int $offering;

    private int $quiz;

    private User $ada;

    private User $bob;

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
        $this->bob = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->ada);
        CourseFactory::enroll($this->offering, $this->bob);

        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, $module);
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 3]);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->quiz, 'detect_tab_switch' => 1, 'browser_lockdown' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function attempt(User $student): int
    {
        return AssessmentFactory::attempt($this->quiz, $student, 'In Progress');
    }

    private function events(int $submission): array
    {
        return db_connect()->table('assessment_security_events')->where('submission_id', $submission)->orderBy('id')->get()->getResultArray();
    }

    /** @param array<string, mixed> $settings */
    private function settings(array $settings): void
    {
        db_connect()->table('assessment_settings')->where('assessment_id', $this->quiz)->update($settings);
    }

    public function testLeavingThePageIsRecordedWithHowLongTheyWereAway(): void
    {
        $submission = $this->attempt($this->ada);

        $result = $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_page', 'duration_seconds' => 42]);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));

        $rows = $this->events($submission);
        $this->assertCount(1, $rows);
        $this->assertSame(['left_page', 42, null], [$rows[0]['kind'], (int) $rows[0]['duration_seconds'], $rows[0]['detail']]);
        $this->assertEqualsWithDelta(time(), strtotime($rows[0]['occurred_at']), 5);
    }

    public function testFullscreenAndBlockedActionsAreRecordedWithWhatWasBlocked(): void
    {
        $submission = $this->attempt($this->ada);

        $this->assertTrue($this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_fullscreen'])['success']);
        $this->assertTrue($this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'blocked_action', 'detail' => 'paste'])['success']);

        $rows = $this->events($submission);
        $this->assertSame(['left_fullscreen', 'blocked_action'], array_column($rows, 'kind'));
        $this->assertSame([null, 'paste'], array_column($rows, 'detail'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideNonsense(): iterable
    {
        yield 'an unknown kind' => [['kind' => 'visited_website', 'detail' => 'example.com']];
        yield 'no kind' => [[]];
        yield 'a blocked action nobody blocks' => [['kind' => 'blocked_action', 'detail' => 'screenshot']];
        yield 'a blocked action with no detail' => [['kind' => 'blocked_action']];
    }

    /** @param array<string, mixed> $event */
    #[PHPUnit\Framework\Attributes\DataProvider('provideNonsense')]
    public function testWhatTheQuizDoesNotKnowAboutIsRefused(array $event): void
    {
        $submission = $this->attempt($this->ada);

        $this->assertFalse($this->service->recordSecurityEvent($this->ada, $submission, $event)['success']);
        $this->assertSame([], $this->events($submission));
    }

    public function testTheDurationIsAWholeNumberOfSecondsKeptWithinADay(): void
    {
        $submission = $this->attempt($this->ada);

        $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_page', 'duration_seconds' => -5]);
        $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_page', 'duration_seconds' => 999999999]);
        $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_page', 'duration_seconds' => 'soon']);
        $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_page', 'duration_seconds' => 12.7]);

        $this->assertSame([0, 86400, 0, 12], array_map('intval', array_column($this->events($submission), 'duration_seconds')));
    }

    public function testOnlyWhatTheQuizSwitchedOnIsKept(): void
    {
        $submission = $this->attempt($this->ada);

        $this->settings(['detect_tab_switch' => 0]);
        $this->assertFalse($this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_page', 'duration_seconds' => 3])['success'], 'recording leaving is off');
        $this->assertTrue($this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_fullscreen'])['success'], 'the lock is still on');

        $this->settings(['detect_tab_switch' => 1, 'browser_lockdown' => 0]);
        $this->assertTrue($this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_page', 'duration_seconds' => 3])['success']);
        $this->assertFalse($this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_fullscreen'])['success'], 'the lock is off');
        $this->assertFalse($this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'blocked_action', 'detail' => 'copy'])['success']);

        $this->assertSame(['left_fullscreen', 'left_page'], array_column($this->events($submission), 'kind'));
    }

    public function testOnlyTheOwnerOfARunningAttemptCanReport(): void
    {
        $adas = $this->attempt($this->ada);

        $this->assertFalse($this->service->recordSecurityEvent($this->bob, $adas, ['kind' => 'left_fullscreen'])['success'], 'another student');
        $this->assertFalse($this->service->recordSecurityEvent($this->instructor, $adas, ['kind' => 'left_fullscreen'])['success'], 'the teacher');
        $this->assertFalse($this->service->recordSecurityEvent($this->ada, 999999, ['kind' => 'left_fullscreen'])['success'], 'an attempt that is not there');
        $this->assertSame([], $this->events($adas));
    }

    public function testAnAttemptThatIsHandedInTakesNoMoreEvents(): void
    {
        $done = AssessmentFactory::attempt($this->quiz, $this->ada, 'Submitted');

        $this->assertFalse($this->service->recordSecurityEvent($this->ada, $done, ['kind' => 'left_fullscreen'])['success']);
        $this->assertSame([], $this->events($done));
    }

    public function testAFloodIsCutOff(): void
    {
        $submission = $this->attempt($this->ada);

        for ($i = 0; $i < QuizAttemptService::MAX_SECURITY_EVENTS + 20; $i++) {
            $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_fullscreen']);
        }

        $this->assertCount(QuizAttemptService::MAX_SECURITY_EVENTS, $this->events($submission));
    }

    public function testTheSummaryCountsWhatHappenedForTheTeacher(): void
    {
        $submission = $this->attempt($this->ada);

        $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_page', 'duration_seconds' => 30]);
        $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_page', 'duration_seconds' => 95]);
        $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'left_fullscreen']);
        $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'blocked_action', 'detail' => 'paste']);
        $this->service->recordSecurityEvent($this->ada, $submission, ['kind' => 'blocked_action', 'detail' => 'copy']);

        $report = $this->service->securityReport($submission);

        $this->assertSame(['left_page' => 2, 'away_seconds' => 125, 'left_fullscreen' => 1, 'blocked_actions' => 2], $report['summary']);
        $this->assertCount(5, $report['events']);
        $this->assertSame('left_page', $report['events'][0]['kind']);

        $this->assertSame(['left_page' => 0, 'away_seconds' => 0, 'left_fullscreen' => 0, 'blocked_actions' => 0], $this->service->securityReport($this->attempt($this->bob))['summary']);
    }
}
