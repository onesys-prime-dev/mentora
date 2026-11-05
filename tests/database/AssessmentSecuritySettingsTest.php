<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The quiz settings that are really enforced: randomize the order of questions, a password, and the security
 * options (record when a student leaves the page, a fullscreen lock that discourages). Those are quiz only: an
 * assignment never stores them. "Specific students" is for every assessment.
 *
 * What a student meets (the password prompt, the shuffled order) is in QuizSecurityAttemptTest.
 *
 * @internal
 */
final class AssessmentSecuritySettingsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssessmentService $service;

    private int $offering;

    private int $module;

    private int $unit;

    private int $quizType;

    private int $assignmentType;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new AssessmentService();
        $this->offering   = CourseFactory::offering();
        $this->module     = CourseFactory::module($this->offering);
        $this->unit       = CourseFactory::unit($this->module);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        $db = db_connect();

        foreach (['Quiz', 'Assignment'] as $name) {
            $db->table('assessment_types')->insert(['name' => $name, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $this->quizType       = (int) $db->table('assessment_types')->where('name', 'Quiz')->get()->getRowArray()['id'];
        $this->assignmentType = (int) $db->table('assessment_types')->where('name', 'Assignment')->get()->getRowArray()['id'];
    }

    private function settings(int $assessmentId): array
    {
        return db_connect()->table('assessment_settings')->where('assessment_id', $assessmentId)->get()->getRowArray();
    }

    private function make(?int $type = null): int
    {
        $result = $this->service->createAssessment($this->instructor, $this->module, $this->unit, ['title' => 'Week 1', 'assessment_type_id' => $type ?? $this->quizType]);
        $this->assertTrue($result['success']);

        return $result['assessment_id'];
    }

    /** @param array<string, mixed> $data */
    private function update(int $id, array $data): array
    {
        return $this->service->updateAssessment($this->instructor, $id, $data);
    }

    public function testANewQuizStartsWithEveryOneOfThemOff(): void
    {
        $settings = $this->settings($this->make());

        foreach (['randomize_questions', 'require_password', 'browser_lockdown', 'detect_tab_switch', 'assign_to_specific_students'] as $key) {
            $this->assertSame(0, (int) $settings[$key], $key);
        }

        $this->assertNull($settings['password']);
    }

    public function testAQuizTakesTheRandomizeSecurityAndSpecificStudentsToggles(): void
    {
        $id = $this->make();

        $result = $this->update($id, ['randomize_questions' => true, 'browser_lockdown' => true, 'detect_tab_switch' => true, 'assign_to_specific_students' => true]);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));

        $settings = $this->settings($id);
        $this->assertSame([1, 1, 1, 1], [(int) $settings['randomize_questions'], (int) $settings['browser_lockdown'], (int) $settings['detect_tab_switch'], (int) $settings['assign_to_specific_students']]);

        $this->update($id, ['randomize_questions' => false]);
        $this->assertSame(0, (int) $this->settings($id)['randomize_questions']);
        $this->assertSame(1, (int) $this->settings($id)['detect_tab_switch'], 'what was not sent is left alone');
    }

    public function testAnAssignmentNeverStoresTheQuizOnlySettingsButTakesSpecificStudents(): void
    {
        $id = $this->make($this->assignmentType);

        $result = $this->update($id, ['randomize_questions' => true, 'require_password' => true, 'browser_lockdown' => true, 'detect_tab_switch' => true, 'assign_to_specific_students' => true]);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));

        $settings = $this->settings($id);

        foreach (['randomize_questions', 'require_password', 'browser_lockdown', 'detect_tab_switch'] as $quizOnly) {
            $this->assertSame(0, (int) $settings[$quizOnly], "{$quizOnly} is quiz only");
        }

        $this->assertSame(1, (int) $settings['assign_to_specific_students']);
    }

    public function testThePasswordIsSetThroughItsOwnMethodAndStoredAsAHashNeverAsText(): void
    {
        $id = $this->make();

        $result = $this->service->setQuizPassword($this->instructor, $id, 'Blue-Heron-42');

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));

        $settings = $this->settings($id);
        $this->assertSame(1, (int) $settings['require_password']);
        $this->assertNotSame('Blue-Heron-42', $settings['password']);
        $this->assertStringNotContainsString('Heron', (string) $settings['password']);
        $this->assertTrue(password_verify('Blue-Heron-42', (string) $settings['password']));
        $this->assertTrue($this->service->quizPasswordMatches($id, 'Blue-Heron-42'));
        $this->assertFalse($this->service->quizPasswordMatches($id, 'blue-heron-42'), 'case matters');
        $this->assertFalse($this->service->quizPasswordMatches($id, ''));
    }

    public function testThePasswordCannotBeSentWithTheOtherSettings(): void
    {
        $id = $this->make();

        $this->update($id, ['password' => 'sneaky', 'require_password' => true]);

        $this->assertNull($this->settings($id)['password']);
    }

    /** @return iterable<string, array{string}> */
    public static function provideBadPasswords(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['    '];
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('x', 65)];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideBadPasswords')]
    public function testAnUnusablePasswordIsRefusedAndNothingChanges(string $password): void
    {
        $id = $this->make();

        $result = $this->service->setQuizPassword($this->instructor, $id, $password);

        $this->assertFalse($result['success']);
        $this->assertNotSame('', (string) $result['message']);
        $this->assertSame(0, (int) $this->settings($id)['require_password']);
        $this->assertNull($this->settings($id)['password']);
    }

    public function testAnAssignmentHasNoPassword(): void
    {
        $id = $this->make($this->assignmentType);

        $this->assertFalse($this->service->setQuizPassword($this->instructor, $id, 'Blue-Heron-42')['success']);
        $this->assertNull($this->settings($id)['password']);
    }

    public function testRequiringAPasswordBeforeOneIsCreatedIsRefusedWithAWayForward(): void
    {
        $id = $this->make();

        $result = $this->update($id, ['require_password' => true]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Create a password', $result['message']);
        $this->assertSame(0, (int) $this->settings($id)['require_password']);
    }

    public function testAPasswordCanBeKeptOnOrTurnedOffWhichForgetsIt(): void
    {
        $id = $this->make();
        $this->service->setQuizPassword($this->instructor, $id, 'Blue-Heron-42');

        // Saving the form with the toggle still on keeps the password.
        $this->assertTrue($this->update($id, ['require_password' => true, 'randomize_questions' => true])['success']);
        $this->assertTrue($this->service->quizPasswordMatches($id, 'Blue-Heron-42'));

        // Turning it off removes it, so a stale password cannot come back by accident.
        $this->assertTrue($this->update($id, ['require_password' => false])['success']);
        $this->assertSame(0, (int) $this->settings($id)['require_password']);
        $this->assertNull($this->settings($id)['password']);
        $this->assertFalse($this->service->quizPasswordMatches($id, 'Blue-Heron-42'));
    }

    public function testTheSettingsRowTheAppPassesAroundNeverCarriesTheHash(): void
    {
        $id = $this->make();
        $this->service->setQuizPassword($this->instructor, $id, 'Blue-Heron-42');

        $model = new \App\Models\Assessments\AssessmentSettingsModel();
        $row   = $model->where('assessment_id', $id)->first();

        $this->assertArrayNotHasKey('password', $row, 'a hash that reaches a page or a JSON answer is a leak');
        $this->assertTrue($row['has_password']);
        $this->assertFalse((new \App\Models\Assessments\AssessmentSettingsModel())->where('assessment_id', $this->make())->first()['has_password']);

        foreach ($model->where('assessment_id', $id)->findAll() as $each) {
            $this->assertArrayNotHasKey('password', $each);
        }
    }

    public function testAQuizPasswordIsTakenFromTheOnePageSettingsAsAPresentFlagOnly(): void
    {
        $id = $this->make();
        $this->service->setQuizPassword($this->instructor, $id, 'Blue-Heron-42');

        $this->assertTrue($this->service->hasQuizPassword($id));
        $this->assertFalse($this->service->hasQuizPassword($this->make()));
    }

    public function testAQuizWithQuestionsStillPublishesWithTheNewSettingsOn(): void
    {
        $id = $this->make();
        AssessmentFactory::question($id, $this->instructor, 'Essay', 1.0);
        $this->service->setQuizPassword($this->instructor, $id, 'Blue-Heron-42');

        $result = $this->update($id, ['status' => 'Published', 'randomize_questions' => true, 'detect_tab_switch' => true, 'require_password' => true]);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));
        $this->assertSame('Published', $result['assessment']['status']);
    }
}
