<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Creating and editing quizzes and assignments: the defaults a new one gets
 * (and the learning item that comes with it), partial edits, how status,
 * duration and attempt settings carry over to the Modules item and the
 * gradebook, who sees what (students never see drafts or hidden items), the
 * availability window, submission counts, and the pages that render them.
 *
 * @internal
 */
final class AssessmentServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssessmentService $service;
    private int $offering;
    private int $module;
    private int $unit;
    private int $quizType;
    private int $assignmentType;
    private User $instructor;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AssessmentService();
        $this->offering = CourseFactory::offering();
        $this->module = CourseFactory::module($this->offering);
        $this->unit = CourseFactory::unit($this->module);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->student = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->student);

        $db = db_connect();
        foreach (['Quiz', 'Assignment'] as $name) {
            $db->table('assessment_types')->insert(['name' => $name, 'created_at' => date('Y-m-d H:i:s')]);
        }
        $this->quizType = (int) $db->table('assessment_types')->where('name', 'Quiz')->get()->getRowArray()['id'];
        $this->assignmentType = (int) $db->table('assessment_types')->where('name', 'Assignment')->get()->getRowArray()['id'];
    }

    private function row(string $table, int $id): ?array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function settings(int $assessmentId): ?array
    {
        return db_connect()->table('assessment_settings')->where('assessment_id', $assessmentId)->get()->getRowArray();
    }

    /** Creates through the service. @return array{assessment: int, item: int} */
    private function create(array $data = [], ?int $type = null): array
    {
        $result = $this->service->createAssessment($this->instructor, $this->module, $this->unit, $data + [
            'title' => 'Week 1 quiz',
            'assessment_type_id' => $type ?? $this->quizType,
        ]);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));

        return ['assessment' => $result['assessment_id'], 'item' => $result['learning_item_id']];
    }

    /** A quiz is published only with a question (QA AMB-ASM-02): gives it one worth nothing, so its total is unchanged. */
    private function publishable(array $ids): array
    {
        AssessmentFactory::question((int) $ids['assessment'], $this->instructor, 'Essay', 0.0);

        return $ids;
    }

    /*
    |--------------------------------------------------------------------------
    | Creating
    |--------------------------------------------------------------------------
    */

    public function testANewQuizStartsAsADraftWithItsDefaults(): void
    {
        $ids = $this->create();

        $assessment = $this->row('assessments', $ids['assessment']);
        $this->assertSame('Week 1 quiz', $assessment['title']);
        $this->assertSame('Draft', $assessment['status']);
        $this->assertSame($this->module, (int) $assessment['module_id']);
        $this->assertSame($ids['item'], (int) $assessment['learning_item_id']);
        $this->assertSame($this->quizType, (int) $assessment['assessment_type_id']);
        $this->assertSame(1, (int) $assessment['attempt_limit']);
        $this->assertEquals(0, $assessment['total_points']);
        $this->assertSame($this->instructor->id, (int) $assessment['created_by']);
        $this->assertEqualsWithDelta(time(), strtotime($assessment['available_from']), 60);
        $this->assertEqualsWithDelta(strtotime('+7 days'), strtotime($assessment['due_date']), 60);
    }

    public function testTheCourseIsTakenFromTheModulesOffering(): void
    {
        $ids = $this->create();

        $courseId = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'];
        $this->assertSame($courseId, (int) $this->row('assessments', $ids['assessment'])['course_id']);
    }

    public function testCreatedValuesOverrideTheDefaults(): void
    {
        $ids = $this->create([
            'description' => 'Chapter 1', 'available_from' => '2026-10-01 08:00:00', 'due_date' => '2026-10-08 23:59:00',
            'duration' => 30, 'total_points' => 50, 'attempt_limit' => 3, 'passing_score' => 25,
        ]);

        $assessment = $this->row('assessments', $ids['assessment']);
        $this->assertSame('Chapter 1', $assessment['description']);
        $this->assertSame('2026-10-01 08:00:00', $assessment['available_from']);
        $this->assertSame('2026-10-08 23:59:00', $assessment['due_date']);
        $this->assertSame(30, (int) $assessment['duration']);
        $this->assertEquals(50, $assessment['total_points']);
        $this->assertSame(3, (int) $assessment['attempt_limit']);
    }

    public function testANewAssessmentGetsDefaultSettings(): void
    {
        $settings = $this->settings($this->create()['assessment']);

        $this->assertSame(1, (int) $settings['allow_file_upload']);
        foreach (['allow_text_entry', 'allow_link_entry', 'allow_late_submission', 'show_results_immediately', 'show_correct_answers', 'require_password', 'randomize_questions', 'randomize_choices', 'browser_lockdown', 'allow_multiple_attempts'] as $off) {
            $this->assertSame(0, (int) $settings[$off], $off);
        }
        $this->assertSame(1, (int) $settings['allow_review_after_submission']);
        $this->assertNull($settings['password']);
    }

    public function testItComesWithALearningItemOfTheRightTypeAtTheEndOfTheUnit(): void
    {
        CourseFactory::items($this->unit, [[], []]);

        $quiz = $this->create(['title' => 'Quiz'], $this->quizType);
        $assignment = $this->create(['title' => 'Essay'], $this->assignmentType);

        $item = $this->row('learning_items', $quiz['item']);
        $this->assertSame('quiz', $item['item_type']);
        $this->assertSame('Quiz', $item['title']);
        $this->assertSame('draft', $item['status']);
        $this->assertSame($this->unit, (int) $item['learning_unit_id']);
        $this->assertSame(3, (int) $item['order_index']);
        $this->assertSame('assignment', $this->row('learning_items', $assignment['item'])['item_type']);
        $this->assertSame(4, (int) $this->row('learning_items', $assignment['item'])['order_index']);
    }

    public function testCreatingNeedsARealModuleAndAUnitInIt(): void
    {
        $otherUnit = CourseFactory::unit(CourseFactory::module($this->offering, 'published', 2));
        $data = ['title' => 'X', 'assessment_type_id' => $this->quizType];

        $this->assertSame('Course module not found.', $this->service->createAssessment($this->instructor, 999999, $this->unit, $data)['message']);
        $this->assertSame('Learning unit not found.', $this->service->createAssessment($this->instructor, $this->module, 999999, $data)['message']);
        $this->assertSame('Learning unit not found.', $this->service->createAssessment($this->instructor, $this->module, $otherUnit, $data)['message'], 'a unit of another module');
        $this->assertSame(0, db_connect()->table('assessments')->countAllResults());
        $this->assertSame(0, db_connect()->table('learning_items')->countAllResults());
    }

    public function testAnAssessmentCanBeAttachedToAnExistingLearningItem(): void
    {
        [$item] = CourseFactory::items($this->unit, [['type' => 'quiz']]);

        $result = $this->service->createAssessmentForLearningItem($this->instructor, $this->module, $item, $this->quizType, 'From the item');

        $this->assertTrue($result['success']);
        $assessment = $this->row('assessments', (int) $result['assessment_id']);
        $this->assertSame('From the item', $assessment['title']);
        $this->assertSame($item, (int) $assessment['learning_item_id']);
        $this->assertSame('Draft', $assessment['status']);
        $this->assertNotNull($this->settings((int) $result['assessment_id']));
        $this->assertSame(1, db_connect()->table('learning_items')->where('learning_unit_id', $this->unit)->countAllResults(), 'no second item');
        $this->assertSame('Course module not found.', $this->service->createAssessmentForLearningItem($this->instructor, 999999, $item, $this->quizType, 'X')['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    public function testEditingChangesOnlyWhatIsSent(): void
    {
        $id = $this->create(['total_points' => 40, 'duration' => 20, 'description' => 'Keep'], $this->assignmentType)['assessment'];

        $result = $this->service->updateAssessment($this->instructor, $id, ['title' => 'Renamed', 'weight_percentage' => 15]);

        $this->assertTrue($result['success']);
        $row = $this->row('assessments', $id);
        $this->assertSame('Renamed', $row['title']);
        $this->assertEquals(15, $row['weight_percentage']);
        $this->assertSame('Keep', $row['description']);
        $this->assertEquals(40, $row['total_points']);
        $this->assertSame(20, (int) $row['duration']);
        $this->assertSame($this->instructor->id, (int) $row['updated_by']);
        $this->assertNotNull($row['updated_at']);
        $this->assertSame('Renamed', $result['assessment']['title']);
    }

    public function testEditingAMissingAssessmentIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->service->updateAssessment($this->instructor, 999999, ['title' => 'X']));
    }

    public function testThePassingScoreFollowsThePassingPercentageOfTheTotal(): void
    {
        $id = $this->create(['total_points' => 40], $this->assignmentType)['assessment'];

        $this->service->updateAssessment($this->instructor, $id, ['passing_percentage' => 75]);
        $this->assertEquals(30, $this->row('assessments', $id)['passing_score']);
        $this->assertEquals(75, $this->row('assessments', $id)['passing_percentage']);

        $this->service->updateAssessment($this->instructor, $id, ['total_points' => 50]);
        $this->assertEquals(37.5, $this->row('assessments', $id)['passing_score'], 'recomputed when the total changes');
    }

    public function testWithoutAPassingPercentageThereIsNoPassingScore(): void
    {
        $id = $this->create(['total_points' => 40, 'passing_score' => 20])['assessment'];

        $this->service->updateAssessment($this->instructor, $id, ['title' => 'Renamed']);

        $this->assertNull($this->row('assessments', $id)['passing_score']);
    }

    public function testTheAttemptLimitOnlyAppliesWhenMultipleAttemptsAreOn(): void
    {
        $id = $this->create()['assessment'];

        $this->service->updateAssessment($this->instructor, $id, ['attempt_limit' => 5]);
        $this->assertSame(1, (int) $this->row('assessments', $id)['attempt_limit'], 'one attempt while multiple attempts are off');

        $this->service->updateAssessment($this->instructor, $id, ['allow_multiple_attempts' => true, 'attempt_limit' => 5]);
        $this->assertSame(5, (int) $this->row('assessments', $id)['attempt_limit']);
        $this->assertSame(1, (int) $this->settings($id)['allow_multiple_attempts']);

        $this->service->updateAssessment($this->instructor, $id, ['title' => 'Still on']);
        $this->assertSame(5, (int) $this->row('assessments', $id)['attempt_limit'], 'left on when not mentioned');

        $this->service->updateAssessment($this->instructor, $id, ['allow_multiple_attempts' => false]);
        $this->assertSame(1, (int) $this->row('assessments', $id)['attempt_limit']);
        $this->assertSame(0, (int) $this->settings($id)['allow_multiple_attempts']);
    }

    public function testSettingsAreEditedWithoutTouchingTheOthers(): void
    {
        $id = $this->create()['assessment'];

        $this->service->updateAssessment($this->instructor, $id, ['allow_late_submission' => 1]);

        $settings = $this->settings($id);
        $this->assertSame(1, (int) $settings['allow_late_submission']);
        $this->assertSame(1, (int) $settings['allow_file_upload'], 'untouched');
        $this->assertSame(0, (int) $settings['allow_multiple_attempts'], 'untouched');
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function settingsNothingEnforces(): iterable
    {
        yield 'a quiz password' => ['password', 'secret'];
        yield 'requiring the password' => ['require_password', true];
        yield 'browser lockdown' => ['browser_lockdown', true];
        yield 'randomised questions' => ['randomize_questions', true];
        yield 'randomised choices' => ['randomize_choices', true];
        yield 'showing results immediately' => ['show_results_immediately', true];
        yield 'showing correct answers' => ['show_correct_answers', true];
        yield 'review after submission' => ['allow_review_after_submission', false];
    }

    /**
     * No screen offers these and nothing in the app acts on them, so storing
     * them would only suggest protection (a quiz password, lockdown) that
     * does not exist. They are not accepted.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('settingsNothingEnforces')]
    public function testSettingsThatNothingEnforcesAreNotStored(string $key, mixed $value): void
    {
        $created = $this->create([$key => $value])['assessment'];
        $before = $this->settings($created);

        $this->service->updateAssessment($this->instructor, $created, [$key => $value]);

        $after = $this->settings($created);
        $this->assertSame($before[$key], $after[$key], $key);
        $this->assertSame(['password' => null, 'require_password' => 0, 'browser_lockdown' => 0, 'randomize_questions' => 0, 'randomize_choices' => 0, 'show_results_immediately' => 0, 'show_correct_answers' => 0, 'allow_review_after_submission' => 1][$key], is_numeric($after[$key]) ? (int) $after[$key] : $after[$key]);
    }

    public function testChangingTheStatusFollowsToTheLearningItem(): void
    {
        $ids = $this->publishable($this->create());

        foreach ([['Published', 'published'], ['Draft', 'draft'], ['Published', 'published'], ['Archived', 'draft']] as [$status, $item]) {
            $this->service->updateAssessment($this->instructor, $ids['assessment'], ['status' => $status]);

            $this->assertSame($status, $this->row('assessments', $ids['assessment'])['status']);
            $this->assertSame($item, $this->row('learning_items', $ids['item'])['status'], $status);
        }
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function badStatuses(): iterable
    {
        yield 'unknown word' => ['banana'];
        yield 'wrong case' => ['published'];
        yield 'empty' => [''];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('badStatuses')]
    public function testAnUnknownStatusIsRefusedAndNothingIsSaved(mixed $status): void
    {
        $ids = $this->create();

        $result = $this->service->updateAssessment($this->instructor, $ids['assessment'], ['status' => $status, 'title' => 'Changed']);

        $this->assertSame(['success' => false, 'message' => 'Choose a valid status.'], $result);
        $row = $this->row('assessments', $ids['assessment']);
        $this->assertSame('Draft', $row['status']);
        $this->assertSame('Week 1 quiz', $row['title']);
        $this->assertSame('draft', $this->row('learning_items', $ids['item'])['status']);
    }

    public function testEveryRealStatusIsAccepted(): void
    {
        $id = $this->publishable($this->create())['assessment'];

        foreach (['Published', 'Closed', 'Archived', 'Draft'] as $status) {
            $this->assertTrue($this->service->updateAssessment($this->instructor, $id, ['status' => $status])['success'], $status);
            $this->assertSame($status, $this->row('assessments', $id)['status']);
        }
    }

    public function testAStatusLeftNullKeepsTheCurrentOne(): void
    {
        $id = $this->publishable($this->create())['assessment'];
        $this->service->updateAssessment($this->instructor, $id, ['status' => 'Published']);

        $this->assertTrue($this->service->updateAssessment($this->instructor, $id, ['status' => null, 'title' => 'Renamed'])['success']);

        $this->assertSame('Published', $this->row('assessments', $id)['status']);
    }

    public function testTheStatusShortcutsKeepTheLearningItemInStep(): void
    {
        $ids = $this->publishable($this->create());

        foreach ([['publishedAssessment', 'published'], ['unpublishedAssessment', 'draft'], ['publishedAssessment', 'published'], ['archiveAssessment', 'draft'], ['restoreAssessment', 'draft']] as [$method, $item]) {
            $this->service->{$method}($this->instructor, $ids['assessment']);

            $this->assertSame($item, $this->row('learning_items', $ids['item'])['status'], $method);
        }
    }

    public function testClosingKeepsTheLearningItemVisible(): void
    {
        $ids = $this->publishable($this->create());
        $this->service->updateAssessment($this->instructor, $ids['assessment'], ['status' => 'Published']);

        $this->service->updateAssessment($this->instructor, $ids['assessment'], ['status' => 'Closed']);

        $this->assertSame('published', $this->row('learning_items', $ids['item'])['status']);
    }

    public function testPublishingAddsTheAssessmentToTheGradebook(): void
    {
        $scheme = GradeFactory::scheme($this->offering);
        $ids = $this->create(['total_points' => 25], $this->assignmentType);

        $this->service->updateAssessment($this->instructor, $ids['assessment'], ['status' => 'Published']);

        $item = db_connect()->table('grade_items')->where('assessment_id', $ids['assessment'])->get()->getRowArray();
        $this->assertNotNull($item);
        $this->assertSame($scheme, (int) $item['grading_scheme_id']);
        $this->assertEquals(25, $item['max_points']);
    }

    public function testEditingWithoutAStatusLeavesTheLearningItemAlone(): void
    {
        $ids = $this->publishable($this->create());
        $this->service->updateAssessment($this->instructor, $ids['assessment'], ['status' => 'Published']);

        $this->service->updateAssessment($this->instructor, $ids['assessment'], ['title' => 'Renamed']);

        $this->assertSame('published', $this->row('learning_items', $ids['item'])['status']);
    }

    public function testTheDurationIsMirroredOnTheLearningItemForTheModuleBadge(): void
    {
        $ids = $this->create();

        $this->service->updateAssessment($this->instructor, $ids['assessment'], ['duration' => 45]);
        $this->assertSame(45, (int) $this->row('learning_items', $ids['item'])['estimated_minutes']);

        $this->service->updateAssessment($this->instructor, $ids['assessment'], ['duration' => null]);
        $this->assertNull($this->row('learning_items', $ids['item'])['estimated_minutes']);
    }

    public function testClearingAnOptionalFieldRemovesIt(): void
    {
        // The editor sends null for a number or date box that was emptied.
        $id = $this->create(['total_points' => 40, 'duration' => 20, 'description' => 'Chapter 1'], $this->assignmentType)['assessment'];
        $this->service->updateAssessment($this->instructor, $id, ['available_until' => '2026-12-31 23:59:00', 'passing_percentage' => 60]);
        $this->assertSame('2026-12-31 23:59:00', $this->row('assessments', $id)['available_until']);

        $this->service->updateAssessment($this->instructor, $id, ['duration' => null, 'available_until' => null, 'passing_percentage' => null, 'description' => null]);

        $row = $this->row('assessments', $id);
        $this->assertNull($row['duration'], 'the time limit is gone');
        $this->assertNull($row['available_until']);
        $this->assertNull($row['passing_percentage']);
        $this->assertNull($row['passing_score']);
        $this->assertNull($row['description']);
        $this->assertEquals(40, $row['total_points'], 'other fields are untouched');
    }

    public function testFieldsThatMustHaveAValueKeepItWhenEmptied(): void
    {
        $id = $this->create(['total_points' => 40, 'due_date' => '2026-11-01 12:00:00', 'available_from' => '2026-10-01 08:00:00'], $this->assignmentType)['assessment'];

        $this->service->updateAssessment($this->instructor, $id, ['due_date' => null, 'available_from' => null, 'total_points' => null, 'title' => null, 'weight_percentage' => null]);

        $row = $this->row('assessments', $id);
        $this->assertSame('2026-11-01 12:00:00', $row['due_date']);
        $this->assertSame('2026-10-01 08:00:00', $row['available_from']);
        $this->assertEquals(40, $row['total_points']);
        $this->assertSame('Week 1 quiz', $row['title']);
    }

    public function testInstructionsAreStoredAsReadableJson(): void
    {
        $id = $this->create()['assessment'];

        $result = $this->service->updateAssessmentInstructions($this->instructor, $id, ['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'Lees alles — café']]]);

        $this->assertSame(['success' => true, 'message' => 'Instructions saved.'], $result);
        $row = $this->row('assessments', $id);
        $this->assertStringContainsString('café', $row['instructions'], 'accents are not escaped');
        $this->assertSame('Lees alles — café', json_decode($row['instructions'], true)['blocks'][0]['text']);
        $this->assertSame($this->instructor->id, (int) $row['updated_by']);
        $this->assertSame('Assessment not found.', $this->service->updateAssessmentInstructions($this->instructor, 999999, [])['message']);
    }

    public function testSubmissionSettingsAreSwitchedOnAndOffAndCreatedWhenMissing(): void
    {
        $id = $this->create()['assessment'];

        $this->service->updateSubmissionSettings($this->instructor, $id, ['allow_text_entry' => 1, 'allow_link_entry' => 1]);
        $settings = $this->settings($id);
        $this->assertSame(0, (int) $settings['allow_file_upload'], 'not sent means off');
        $this->assertSame(1, (int) $settings['allow_text_entry']);
        $this->assertSame(1, (int) $settings['allow_link_entry']);

        db_connect()->table('assessment_settings')->where('assessment_id', $id)->delete();
        $this->assertTrue($this->service->updateSubmissionSettings($this->instructor, $id, ['allow_file_upload' => 1])['success']);
        $this->assertSame(1, db_connect()->table('assessment_settings')->where('assessment_id', $id)->countAllResults());
        $this->assertSame('Assessment not found.', $this->service->updateSubmissionSettings($this->instructor, 999999, [])['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Deleting and the status shortcuts
    |--------------------------------------------------------------------------
    */

    public function testDeletingRemovesTheAssessmentAndItsLearningItem(): void
    {
        $ids = $this->create();

        $this->assertSame(['success' => true, 'message' => 'Assessment deleted successfully.'], $this->service->deleteAssessment($ids['assessment']));

        $this->assertNull($this->row('assessments', $ids['assessment']));
        $this->assertNull($this->row('learning_items', $ids['item']));
        $this->assertSame('Assessment not found.', $this->service->deleteAssessment($ids['assessment'])['message']);
    }

    public function testPublishUnpublishArchiveAndRestoreSetTheStatus(): void
    {
        $id = $this->publishable($this->create())['assessment'];

        foreach ([
            ['publishedAssessment', 'Published'],
            ['unpublishedAssessment', 'Draft'],
            ['archiveAssessment', 'Archived'],
            ['restoreAssessment', 'Draft'],
        ] as [$method, $status]) {
            $result = $this->service->{$method}($this->instructor, $id);

            $this->assertTrue($result['success'], $method);
            $this->assertSame($status, $result['assessment']['status'], $method);
            $this->assertSame($this->instructor->id, (int) $result['assessment']['updated_by']);
            $this->assertSame('Assessment not found.', $this->service->{$method}($this->instructor, 999999)['message'], $method);
        }
    }

    public function testPublishingThroughTheShortcutAddsTheGradebookItem(): void
    {
        GradeFactory::scheme($this->offering);
        $id = $this->publishable($this->create(['total_points' => 10]))['assessment'];

        $this->service->publishedAssessment($this->instructor, $id);

        $this->assertSame(1, db_connect()->table('grade_items')->where('assessment_id', $id)->countAllResults());
    }

    public function testADuplicateIsADraftCopyWithItsSettings(): void
    {
        $id = $this->create(['total_points' => 20], $this->assignmentType)['assessment'];
        $this->service->updateAssessment($this->instructor, $id, ['status' => 'Published', 'allow_late_submission' => 1]);

        $result = $this->service->duplicateAssessment($this->instructor, $id);

        $this->assertTrue($result['success']);
        $copy = $result['assessment'];
        $this->assertNotSame($id, (int) $copy['id']);
        $this->assertSame('Week 1 quiz (Copy)', $copy['title']);
        $this->assertSame('Draft', $copy['status']);
        $this->assertEquals(20, $copy['total_points']);
        $this->assertSame(1, (int) $this->settings((int) $copy['id'])['allow_late_submission']);
        $this->assertSame('Published', $this->row('assessments', $id)['status'], 'the original is untouched');
        $this->assertSame('Assessment not found.', $this->service->duplicateAssessment($this->instructor, 999999)['message']);
    }

    public function testADuplicateGetsItsOwnLearningItemAtTheEndOfTheUnit(): void
    {
        $ids = $this->publishable($this->create(['title' => 'Quiz']));
        $this->service->updateAssessment($this->instructor, $ids['assessment'], ['status' => 'Published']);
        [$other] = CourseFactory::items($this->unit, [[]]);
        db_connect()->table('learning_items')->where('id', $other)->update(['order_index' => 2]);

        $copy = $this->service->duplicateAssessment($this->instructor, $ids['assessment'])['assessment'];

        $this->assertNotSame($ids['item'], (int) $copy['learning_item_id'], 'not shared with the original');
        $item = $this->row('learning_items', (int) $copy['learning_item_id']);
        $this->assertSame('Quiz (Copy)', $item['title']);
        $this->assertSame('quiz', $item['item_type']);
        $this->assertSame('draft', $item['status']);
        $this->assertSame($this->unit, (int) $item['learning_unit_id']);
        $this->assertSame(3, (int) $item['order_index'], 'after the original and the other item');
        $this->assertSame('published', $this->row('learning_items', $ids['item'])['status'], 'the original item is untouched');
    }

    public function testDeletingADuplicateDoesNotTakeTheOriginalsItemWithIt(): void
    {
        $ids = $this->create();
        $copy = $this->service->duplicateAssessment($this->instructor, $ids['assessment'])['assessment'];

        $this->service->deleteAssessment((int) $copy['id']);

        $this->assertNotNull($this->row('assessments', $ids['assessment']));
        $this->assertNotNull($this->row('learning_items', $ids['item']));
    }

    public function testADuplicateCopiesTheQuestionsTheirChoicesAndTheRubric(): void
    {
        $ids = $this->create(['total_points' => 7]);
        $quiz = $ids['assessment'];
        $mc = AssessmentFactory::multipleChoice($quiz, $this->instructor, [false, true], 4);
        $short = AssessmentFactory::shortAnswer($quiz, $this->instructor, ['Paris', 'paris france'], 3, true);
        $archived = AssessmentFactory::question($quiz, $this->instructor, 'Essay', 9);
        db_connect()->table('assessment_questions')->where('id', $archived)->update(['status' => 'Archived']);
        $rubric = AssessmentFactory::rubric($quiz, $this->instructor, [10, 5]);

        $copy = (int) $this->service->duplicateAssessment($this->instructor, $quiz)['assessment']['id'];

        $questions = (new App\Models\Assessments\AssessmentQuestionModel())->listForAssessment($copy);
        $this->assertCount(2, $questions, 'archived questions are not copied');
        $this->assertSame(['Multiple Choice', 'Short Answer'], array_column($questions, 'question_type'));
        $this->assertSame([1, 2], array_map('intval', array_column($questions, 'order_index')));
        $this->assertEquals([4, 3], array_column($questions, 'points'));
        $this->assertSame(['Choice 1', 'Choice 2'], array_column($questions[0]['choices'], 'choice_text'));
        $this->assertSame([0, 1], array_map('intval', array_column($questions[0]['choices'], 'is_correct')));
        $this->assertSame([1, 2], array_map('intval', array_column($questions[0]['choices'], 'order_index')));
        $this->assertSame(['Paris', 'paris france'], $questions[1]['accepted_answers']);
        $this->assertSame(1, (int) $questions[1]['case_sensitive']);

        $copiedRubric = db_connect()->table('assessment_rubrics')->where('assessment_id', $copy)->get()->getRowArray();
        $this->assertNotNull($copiedRubric);
        $this->assertNotSame($rubric['rubric'], (int) $copiedRubric['id']);
        $this->assertEquals(15, $copiedRubric['total_points']);
        $criteria = db_connect()->table('assessment_rubric_criteria')->where('rubric_id', $copiedRubric['id'])->orderBy('order_index')->get()->getResultArray();
        $this->assertEquals([10, 5], array_column($criteria, 'max_points'));

        // The copy is independent of the original.
        db_connect()->table('assessment_questions')->where('id', $questions[0]['id'])->update(['question_text' => 'Changed in the copy']);
        $this->assertNotSame('Changed in the copy', db_connect()->table('assessment_questions')->where('id', $mc['question'])->get()->getRowArray()['question_text']);
        $this->assertNotSame($short, (int) $questions[1]['id']);
    }

    public function testADuplicateKeepsNoStudentWork(): void
    {
        $id = $this->create()['assessment'];
        AssessmentFactory::attempt($id, $this->student, 'Graded', 5);

        $copy = (int) $this->service->duplicateAssessment($this->instructor, $id)['assessment']['id'];

        $this->assertSame(0, db_connect()->table('assessment_submissions')->where('assessment_id', $copy)->countAllResults());
    }

    public function testReadingOneAssessmentAndItsFigures(): void
    {
        $id = $this->create(['total_points' => 20, 'duration' => 15, 'weight_percentage' => 10])['assessment'];

        $one = $this->service->getAssessment($this->instructor, $id);
        $stats = $this->service->getAssessmentStatistics($this->instructor, $id);

        $this->assertSame('Week 1 quiz', $one['assessment']['title']);
        $this->assertSame(1, (int) $one['settings']['allow_file_upload']);
        $this->assertEquals(20, $stats['statistics']['total_points']);
        $this->assertSame(15, (int) $stats['statistics']['duration']);
        $this->assertSame('Draft', $stats['statistics']['status']);
        $this->assertFalse($this->service->getAssessment($this->instructor, 999999)['success']);
        $this->assertFalse($this->service->getAssessmentStatistics($this->instructor, 999999)['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | What students may see
    |--------------------------------------------------------------------------
    */

    /** An assessment tied to a learning item with the given statuses. */
    private function visibilityCase(string $assessment, string $module = 'published', string $unit = 'published', string $item = 'published'): array
    {
        $moduleId = CourseFactory::module($this->offering, $module, 9);
        $unitId = CourseFactory::unit($moduleId, $unit);
        [$itemId] = CourseFactory::items($unitId, [['type' => 'quiz', 'status' => $item]]);
        $id = CourseFactory::assessment($this->offering, $this->instructor, $moduleId, $itemId);
        db_connect()->table('assessments')->where('id', $id)->update(['status' => $assessment]);

        return $this->row('assessments', $id);
    }

    public function testStudentsSeePublishedAndClosedAssessmentsOnly(): void
    {
        $rows = [
            'Published' => $this->visibilityCase('Published'),
            'Closed' => $this->visibilityCase('Closed'),
            'Draft' => $this->visibilityCase('Draft'),
            'Archived' => $this->visibilityCase('Archived'),
        ];

        $visible = $this->service->studentVisibleIds(array_values($rows));

        $this->assertSame([(int) $rows['Published']['id'], (int) $rows['Closed']['id']], array_keys($visible));
        $this->assertTrue($this->service->isVisibleToStudent($rows['Published']));
        $this->assertFalse($this->service->isVisibleToStudent($rows['Draft']));
    }

    public function testAnAssessmentIsHiddenWhileItsItemUnitOrModuleIsNot(): void
    {
        foreach ([['draft', 'published', 'published'], ['published', 'draft', 'published'], ['published', 'published', 'draft'], ['published', 'inactive', 'published']] as [$item, $unit, $module]) {
            $row = $this->visibilityCase('Published', $module, $unit, $item);

            $this->assertFalse($this->service->isVisibleToStudent($row), "item {$item}, unit {$unit}, module {$module}");
        }
    }

    public function testAnAssessmentWithoutALearningItemIsVisibleWhenPublished(): void
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, $this->module);
        AssessmentFactory::open($id);

        $this->assertTrue($this->service->isVisibleToStudent($this->row('assessments', $id)));
    }

    /*
    |--------------------------------------------------------------------------
    | Is the quiz open?
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{0: array, 1: ?string}> */
    public static function windows(): iterable
    {
        yield 'open' => [[], null];
        yield 'closed' => [['status' => 'Closed'], 'This quiz is closed.'];
        yield 'a draft' => [['status' => 'Draft'], "This quiz isn't open yet."];
        yield 'archived' => [['status' => 'Archived'], "This quiz isn't open yet."];
        yield 'not open yet' => [['available_from' => '+2 days'], 'This quiz opens '];
        yield 'availability ended' => [['available_until' => '-1 hour'], 'This quiz closed on '];
        yield 'past due' => [['due_date' => '-1 hour'], "The due date has passed and late attempts aren't allowed."];
        yield 'still inside the window' => [['available_until' => '+1 hour', 'due_date' => '+1 day'], null];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('windows')]
    public function testQuizAvailability(array $change, ?string $reason): void
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, $this->module);
        AssessmentFactory::open($id, array_map(static fn ($v) => preg_match('/^[+-]\d/', (string) $v) ? date('Y-m-d H:i:s', strtotime($v)) : $v, $change));

        $actual = $this->service->quizAvailabilityReason($this->row('assessments', $id));

        if ($reason === null) {
            $this->assertNull($actual);
        } else {
            $this->assertStringStartsWith($reason, (string) $actual);
        }
    }

    public function testLateAttemptsAreAllowedWhenTheSettingIsOn(): void
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, $this->module);
        AssessmentFactory::open($id, ['due_date' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $id, 'allow_late_submission' => 1, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertNull($this->service->quizAvailabilityReason($this->row('assessments', $id)));
    }

    /*
    |--------------------------------------------------------------------------
    | The Modules item's view of it
    |--------------------------------------------------------------------------
    */

    public function testAnItemWithoutAnAssessmentHasNoContext(): void
    {
        [$item] = CourseFactory::items($this->unit, [['type' => 'reading']]);

        $this->assertNull($this->service->buildLearningItemContext($this->student, $item));
    }

    public function testAStudentsQuizItemShowsTheirProgress(): void
    {
        $ids = $this->create(['attempt_limit' => 2, 'allow_multiple_attempts' => true]);
        $quiz = $ids['assessment'];
        AssessmentFactory::open($quiz, ['attempt_limit' => 2]);
        AssessmentFactory::multipleChoice($quiz, $this->instructor, [true, false], 4);
        AssessmentFactory::attempt($quiz, $this->student, 'Graded', 3);

        $context = $this->service->buildLearningItemContext($this->student, $ids['item']);

        $this->assertSame(1, $context['questionCount']);
        $this->assertNull($context['counts']);
        $this->assertSame(1, $context['state']['attemptsUsed']);
        $this->assertSame(2, $context['state']['attemptLimit']);
        $this->assertTrue($context['state']['canTake']);
        $this->assertEquals(3, $context['state']['counted']['score']);
        $this->assertNull($context['rubric']);
    }

    public function testAStudentWhoUsedAllAttemptsIsToldSo(): void
    {
        $ids = $this->create();
        AssessmentFactory::open($ids['assessment']);
        AssessmentFactory::attempt($ids['assessment'], $this->student, 'Graded', 3);

        $state = $this->service->buildLearningItemContext($this->student, $ids['item'])['state'];

        $this->assertFalse($state['canTake']);
        $this->assertSame("You've already taken this quiz.", $state['reason']);
    }

    public function testARunningAttemptCanAlwaysBeResumed(): void
    {
        $ids = $this->create();
        AssessmentFactory::open($ids['assessment'], ['status' => 'Closed']);
        AssessmentFactory::attempt($ids['assessment'], $this->student, 'In Progress');

        $state = $this->service->buildLearningItemContext($this->student, $ids['item'])['state'];

        $this->assertTrue($state['inProgress']);
        $this->assertTrue($state['canTake']);
    }

    public function testAnInstructorSeesSubmissionCountsForTheirSection(): void
    {
        $ids = $this->create();
        AssessmentFactory::open($ids['assessment']);
        $graded = CourseFactory::user('student');
        $waiting = CourseFactory::user('student');
        $silent = CourseFactory::user('student');
        $outsider = CourseFactory::user('student');
        foreach ([$graded, $waiting, $silent] as $s) {
            CourseFactory::enroll($this->offering, $s);
        }
        CourseFactory::enroll(CourseFactory::offering('B'), $outsider);
        AssessmentFactory::attempt($ids['assessment'], $graded, 'Graded', 5);
        AssessmentFactory::attempt($ids['assessment'], $waiting, 'Submitted');
        AssessmentFactory::attempt($ids['assessment'], $outsider, 'Submitted');

        $context = $this->service->buildLearningItemContext($this->instructor, $ids['item']);

        $this->assertSame(['submitted' => 2, 'toGrade' => 1, 'graded' => 1], $context['counts']);
        $this->assertNull($context['state']);
    }

    public function testAnAssignmentItemCarriesItsRubric(): void
    {
        $ids = $this->create([], $this->assignmentType);
        AssessmentFactory::open($ids['assessment']);
        AssessmentFactory::rubric($ids['assessment'], $this->instructor, [10, 5]);

        $context = $this->service->buildLearningItemContext($this->student, $ids['item']);

        $this->assertCount(2, $context['rubric']['criteria']);
        $this->assertSame(0, $context['questionCount']);
        $this->assertArrayHasKey('allowed', $context['state'], 'the assignment submission state');
    }

    /*
    |--------------------------------------------------------------------------
    | The pages
    |--------------------------------------------------------------------------
    */

    public function testTheAssessmentPagesRender(): void
    {
        $ids = $this->publishable($this->create());
        $this->service->updateAssessment($this->instructor, $ids['assessment'], ['status' => 'Published']);

        $this->assertIsString($this->service->getAssessmentIndex($this->instructor, $this->offering)['html']);
        $this->assertIsString($this->service->getAssessmentIndex($this->student, $this->offering)['html']);
        $this->assertIsString($this->service->viewAssessment($this->instructor, $ids['assessment'])['html']);
        $this->assertIsString($this->service->viewAssessment($this->student, $ids['assessment'])['html']);
        $this->assertIsString($this->service->editAssessment($this->instructor, $ids['assessment'])['html']);
        $this->assertIsString($this->service->quizBuilder($this->instructor, $ids['assessment'])['html']);
    }

    public function testStudentsGetANotFoundForDraftsEvenThroughADirectLink(): void
    {
        $id = $this->create()['assessment'];

        $this->expectException(PageNotFoundException::class);

        $this->service->viewAssessment($this->student, $id);
    }

    public function testInstructorsCanOpenADraft(): void
    {
        $id = $this->create()['assessment'];

        $this->assertIsString($this->service->viewAssessment($this->instructor, $id)['html']);
    }

    /*
    |--------------------------------------------------------------------------
    | Total points that are worked out, not typed
    |--------------------------------------------------------------------------
    */

    private function editorHtml(int $id): string
    {
        return (string) $this->service->editAssessment($this->instructor, $id)['html'];
    }

    /** The Total Points input of the editor's settings panel, as written in the page. */
    private function totalPointsInput(string $html): string
    {
        $this->assertSame(1, preg_match('/<input[^>]*data-inspector-total-points[^>]*>/s', $html, $match), 'the settings panel has a Total Points input');

        return $match[0];
    }

    public function testAQuizsTotalIsWhatItsQuestionsAddUpToAndTheFieldIsLocked(): void
    {
        $id = $this->create()['assessment'];
        AssessmentFactory::shortAnswer($id, $this->instructor, ['a'], 3);
        AssessmentFactory::shortAnswer($id, $this->instructor, ['b'], 4.5);

        $input = $this->totalPointsInput($this->editorHtml($id));

        $this->assertStringContainsString('value="7.5"', $input);
        $this->assertMatchesRegularExpression('/\sdisabled[\s>]/', $input);
        $this->assertStringContainsString('data-inspector-total-points-source="quiz"', $input);
    }

    public function testAQuizsTotalIgnoresWhatTheFormSends(): void
    {
        $id = $this->create()['assessment'];
        AssessmentFactory::shortAnswer($id, $this->instructor, ['a'], 6);

        $this->service->updateAssessment($this->instructor, $id, ['total_points' => 99, 'passing_percentage' => 50]);

        $row = $this->row('assessments', $id);
        $this->assertEquals(6, $row['total_points'], 'the questions decide, not the request');
        $this->assertEquals(3, $row['passing_score'], 'the passing score follows the questions');
    }

    public function testAQuizsTotalIgnoresRemovedQuestions(): void
    {
        $id = $this->create()['assessment'];
        AssessmentFactory::shortAnswer($id, $this->instructor, ['a'], 5);
        $removed = AssessmentFactory::shortAnswer($id, $this->instructor, ['b'], 8);
        db_connect()->table('assessment_questions')->where('id', $removed)->update(['status' => 'Deleted']);

        $this->service->updateAssessment($this->instructor, $id, ['title' => 'Renamed']);

        $this->assertEquals(5, $this->row('assessments', $id)['total_points']);
        $this->assertStringContainsString('value="5"', $this->totalPointsInput($this->editorHtml($id)));
    }

    public function testAnAssignmentWithARubricGetsItsTotalFromTheRubricAndTheFieldIsLocked(): void
    {
        $id = $this->create([], $this->assignmentType)['assessment'];
        AssessmentFactory::rubric($id, $this->instructor, [10, 15]);

        $input = $this->totalPointsInput($this->editorHtml($id));

        $this->assertStringContainsString('value="25"', $input);
        $this->assertMatchesRegularExpression('/\sdisabled[\s>]/', $input);
        $this->assertStringContainsString('data-inspector-total-points-source="rubric"', $input);

        $this->service->updateAssessment($this->instructor, $id, ['total_points' => 99, 'passing_percentage' => 40]);

        $row = $this->row('assessments', $id);
        $this->assertEquals(25, $row['total_points'], 'the rubric decides, not the request');
        $this->assertEquals(10, $row['passing_score']);
    }

    public function testAnAssignmentWithoutARubricKeepsAnEditableTotal(): void
    {
        $id = $this->create(['total_points' => 40], $this->assignmentType)['assessment'];

        $input = $this->totalPointsInput($this->editorHtml($id));

        $this->assertStringContainsString('value="40"', $input);
        $this->assertDoesNotMatchRegularExpression('/\sdisabled[\s>]/', $input);
        $this->assertStringNotContainsString('data-inspector-total-points-source', $input);

        $this->service->updateAssessment($this->instructor, $id, ['total_points' => 55]);

        $this->assertEquals(55, $this->row('assessments', $id)['total_points']);
    }

    public function testMissingAssessmentsAreNotFoundOnEveryPage(): void
    {
        foreach ([
            fn () => $this->service->viewAssessment($this->instructor, 999999),
            fn () => $this->service->viewAssessment($this->student, 999999),
            fn () => $this->service->editAssessment($this->instructor, 999999),
            fn () => $this->service->quizBuilder($this->instructor, 999999),
        ] as $index => $call) {
            try {
                $call();
                $this->fail("page #{$index} rendered a missing assessment");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
