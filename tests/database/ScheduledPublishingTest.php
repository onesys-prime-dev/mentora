<?php

declare(strict_types=1);

use App\Services\Courses\ModuleService;
use App\Services\Courses\ScheduledPublishing;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Adding a module, learning unit or learning item as published or as a draft, optionally scheduled to publish
 * itself later; the sweep that publishes what is due; and the schedule going away when it is published or drafted by hand.
 *
 * @internal
 */
final class ScheduledPublishingTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ModuleService $service;

    private int $offering;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new ModuleService();
        $this->offering   = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        // A quiz or assignment item makes its assessment from these.
        foreach (['Quiz', 'Assignment'] as $name) {
            db_connect()->table('assessment_types')->insert(['name' => $name, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function request(array $post): IncomingRequest
    {
        $request = new IncomingRequest(config('App'), new SiteURI(config('App')), null, new UserAgent());
        $request->setGlobal('post', $post);

        return $request;
    }

    private function row(string $table, int $id): array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function later(string $modify = '+2 days'): string
    {
        return date('Y-m-d\TH:i', strtotime($modify));
    }

    /*
    |--------------------------------------------------------------------------
    | What the form means
    |--------------------------------------------------------------------------
    */

    public function testNothingSentIsADraftWithNoSchedule(): void
    {
        $this->assertSame(['ok' => true, 'fields' => ['status' => 'draft', 'scheduled_publish_at' => null]], ScheduledPublishing::fields([]));
    }

    public function testPublishedIsPublishedAndAnyScheduleIsDropped(): void
    {
        $result = ScheduledPublishing::fields(['status' => 'published', 'schedule_publish' => '1', 'scheduled_publish_at' => $this->later()]);

        $this->assertSame(['status' => 'published', 'scheduled_publish_at' => null], $result['fields']);
    }

    public function testADraftWithTheScheduleOffKeepsNoSchedule(): void
    {
        $result = ScheduledPublishing::fields(['status' => 'draft', 'scheduled_publish_at' => $this->later()]);

        $this->assertSame(['status' => 'draft', 'scheduled_publish_at' => null], $result['fields']);
    }

    public function testADraftScheduledForTheFutureKeepsTheTime(): void
    {
        $result = ScheduledPublishing::fields(['status' => 'draft', 'schedule_publish' => '1', 'scheduled_publish_at' => '2030-03-04T09:30']);

        $this->assertTrue($result['ok']);
        $this->assertSame(['status' => 'draft', 'scheduled_publish_at' => '2030-03-04 09:30:00'], $result['fields']);
    }

    public function testAScheduleNeedsATime(): void
    {
        $result = ScheduledPublishing::fields(['status' => 'draft', 'schedule_publish' => '1', 'scheduled_publish_at' => '']);

        $this->assertFalse($result['ok']);
        $this->assertSame('Pick the date and time to publish.', $result['message']);
    }

    public function testAScheduleCannotBeInThePast(): void
    {
        $result = ScheduledPublishing::fields(['status' => 'draft', 'schedule_publish' => '1', 'scheduled_publish_at' => $this->later('-1 hour')]);

        $this->assertFalse($result['ok']);
        $this->assertSame('Pick a time in the future.', $result['message']);
    }

    public function testAnUnreadableTimeIsRefused(): void
    {
        $this->assertFalse(ScheduledPublishing::fields(['status' => 'draft', 'schedule_publish' => '1', 'scheduled_publish_at' => 'next tuesday-ish'])['ok']);
        $this->assertFalse(ScheduledPublishing::fields(['status' => 'draft', 'schedule_publish' => '1', 'scheduled_publish_at' => '2030-02-31T10:00'])['ok']);
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $this->assertFalse(ScheduledPublishing::fields(['status' => 'archived'])['ok']);
    }

    /*
    |--------------------------------------------------------------------------
    | Adding
    |--------------------------------------------------------------------------
    */

    public function testAModuleCanBeAddedPublished(): void
    {
        $this->assertTrue($this->service->storeModule($this->instructor, $this->offering, $this->request(['title' => 'Week 1', 'description' => '', 'status' => 'published']))['success']);

        $module = db_connect()->table('course_modules')->where('title', 'Week 1')->get()->getRowArray();
        $this->assertSame('published', $module['status']);
        $this->assertNull($module['scheduled_publish_at']);
    }

    public function testAModuleCanBeAddedAsAScheduledDraft(): void
    {
        $result = $this->service->storeModule($this->instructor, $this->offering, $this->request([
            'title' => 'Week 2', 'description' => '', 'status' => 'draft', 'schedule_publish' => '1', 'scheduled_publish_at' => '2030-03-04T09:30',
        ]));

        $this->assertTrue($result['success']);
        $module = db_connect()->table('course_modules')->where('title', 'Week 2')->get()->getRowArray();
        $this->assertSame('draft', $module['status']);
        $this->assertSame('2030-03-04 09:30:00', $module['scheduled_publish_at']);
    }

    public function testAModuleWithoutAStatusIsStillADraft(): void
    {
        $this->service->storeModule($this->instructor, $this->offering, $this->request(['title' => 'Week 3', 'description' => '']));

        $this->assertSame('draft', db_connect()->table('course_modules')->where('title', 'Week 3')->get()->getRowArray()['status']);
    }

    public function testABadScheduleStopsTheModuleBeingAdded(): void
    {
        $result = $this->service->storeModule($this->instructor, $this->offering, $this->request([
            'title' => 'Week 4', 'description' => '', 'status' => 'draft', 'schedule_publish' => '1', 'scheduled_publish_at' => '',
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame(0, db_connect()->table('course_modules')->where('title', 'Week 4')->countAllResults());
    }

    public function testALearningUnitCanBeAddedPublishedOrScheduled(): void
    {
        $module = CourseFactory::module($this->offering);

        $this->service->storeLearningUnit($this->instructor, $this->offering, $module, $this->request(['title' => 'Unit A', 'description' => '', 'status' => 'published']));
        $this->service->storeLearningUnit($this->instructor, $this->offering, $module, $this->request(['title' => 'Unit B', 'description' => '', 'status' => 'draft', 'schedule_publish' => '1', 'scheduled_publish_at' => '2030-03-04T09:30']));

        $a = db_connect()->table('learning_units')->where('title', 'Unit A')->get()->getRowArray();
        $b = db_connect()->table('learning_units')->where('title', 'Unit B')->get()->getRowArray();
        $this->assertSame('published', $a['status']);
        $this->assertSame('draft', $b['status']);
        $this->assertSame('2030-03-04 09:30:00', $b['scheduled_publish_at']);
    }

    public function testALearningItemCanBeAddedPublishedOrScheduled(): void
    {
        $module = CourseFactory::module($this->offering);
        $unit   = CourseFactory::unit($module);

        $a = $this->service->storeLearningItem($this->instructor, $this->offering, $module, $unit, $this->request(['title' => 'Read me', 'item_type' => 'reading', 'status' => 'published']));
        $b = $this->service->storeLearningItem($this->instructor, $this->offering, $module, $unit, $this->request(['title' => 'Later', 'item_type' => 'reading', 'status' => 'draft', 'schedule_publish' => '1', 'scheduled_publish_at' => '2030-03-04T09:30']));

        $this->assertSame('published', $this->row('learning_items', $a['learning_item_id'])['status']);
        $this->assertSame('2030-03-04 09:30:00', $this->row('learning_items', $b['learning_item_id'])['scheduled_publish_at']);
    }

    public function testAQuizCannotBeAddedPublishedBecauseItHasNoQuestionYet(): void
    {
        $module = CourseFactory::module($this->offering);
        $unit   = CourseFactory::unit($module);

        $result = $this->service->storeLearningItem($this->instructor, $this->offering, $module, $unit, $this->request(['title' => 'Quiz', 'item_type' => 'quiz', 'status' => 'published']));

        $this->assertFalse($result['success']);
        $this->assertSame(0, db_connect()->table('learning_items')->where('title', 'Quiz')->countAllResults(), 'nothing is left half made');
        $this->assertSame(0, db_connect()->table('assessments')->where('title', 'Quiz')->countAllResults());
    }

    public function testAnAssignmentAddedPublishedIsPublishedWithItsAssessment(): void
    {
        $module = CourseFactory::module($this->offering);
        $unit   = CourseFactory::unit($module);

        $result = $this->service->storeLearningItem($this->instructor, $this->offering, $module, $unit, $this->request(['title' => 'Essay', 'item_type' => 'assignment', 'status' => 'published']));

        $this->assertTrue($result['success']);
        $this->assertSame('Published', db_connect()->table('assessments')->where('learning_item_id', $result['learning_item_id'])->get()->getRowArray()['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | The sweep
    |--------------------------------------------------------------------------
    */

    private function scheduled(string $table, int $id, string $when): void
    {
        db_connect()->table($table)->where('id', $id)->update(['status' => 'draft', 'scheduled_publish_at' => $when]);
    }

    public function testWhatIsDueIsPublishedAndWhatIsNotDueWaits(): void
    {
        $module = CourseFactory::module($this->offering, 'draft');
        $unit   = CourseFactory::unit($module, 'draft');
        [$item] = CourseFactory::items($unit, [['type' => 'reading', 'status' => 'draft']]);
        $other  = CourseFactory::module($this->offering, 'draft', 2);

        $this->scheduled('course_modules', $module, date('Y-m-d H:i:s', strtotime('-1 minute')));
        $this->scheduled('learning_units', $unit, date('Y-m-d H:i:s', strtotime('-5 minutes')));
        $this->scheduled('learning_items', $item, date('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->scheduled('course_modules', $other, date('Y-m-d H:i:s', strtotime('+1 hour')));

        $done = ScheduledPublishing::publishDue();

        $this->assertSame(['modules' => 1, 'units' => 1, 'items' => 1], $done);

        foreach ([['course_modules', $module], ['learning_units', $unit], ['learning_items', $item]] as [$table, $id]) {
            $row = $this->row($table, $id);
            $this->assertSame('published', $row['status'], $table);
            $this->assertNull($row['scheduled_publish_at'], $table);
        }

        $this->assertSame('draft', $this->row('course_modules', $other)['status']);
        $this->assertNotNull($this->row('course_modules', $other)['scheduled_publish_at']);
    }

    public function testTheEveryMinuteJobRunsTheSweep(): void
    {
        $module = CourseFactory::module($this->offering, 'draft');
        $this->scheduled('course_modules', $module, date('Y-m-d H:i:s', strtotime('-1 minute')));

        ob_start();
        command('exports:run');
        ob_end_clean();

        $this->assertSame('published', $this->row('course_modules', $module)['status']);
    }

    public function testASecondSweepDoesNothingMore(): void
    {
        $module = CourseFactory::module($this->offering, 'draft');
        $this->scheduled('course_modules', $module, date('Y-m-d H:i:s', strtotime('-1 minute')));

        ScheduledPublishing::publishDue();

        $this->assertSame(['modules' => 0, 'units' => 0, 'items' => 0], ScheduledPublishing::publishDue());
    }

    public function testADraftWithNoScheduleIsNeverPublishedBySweeping(): void
    {
        $module = CourseFactory::module($this->offering, 'draft');

        ScheduledPublishing::publishDue();

        $this->assertSame('draft', $this->row('course_modules', $module)['status']);
    }

    public function testAnAssignmentItemPublishesItsAssessmentToo(): void
    {
        $module = CourseFactory::module($this->offering);
        $unit   = CourseFactory::unit($module);
        $result = $this->service->storeLearningItem($this->instructor, $this->offering, $module, $unit, $this->request(['title' => 'Essay', 'item_type' => 'assignment', 'status' => 'draft']));
        $item   = $result['learning_item_id'];
        $this->scheduled('learning_items', $item, date('Y-m-d H:i:s', strtotime('-1 minute')));

        ScheduledPublishing::publishDue();

        $this->assertSame('published', $this->row('learning_items', $item)['status']);
        $this->assertSame('Published', db_connect()->table('assessments')->where('learning_item_id', $item)->get()->getRowArray()['status']);
    }

    public function testAQuizItemWithNoQuestionIsLeftAsADraftUntilItHasOne(): void
    {
        $module = CourseFactory::module($this->offering);
        $unit   = CourseFactory::unit($module);
        $result = $this->service->storeLearningItem($this->instructor, $this->offering, $module, $unit, $this->request(['title' => 'Quiz', 'item_type' => 'quiz', 'status' => 'draft']));
        $item   = $result['learning_item_id'];
        $this->scheduled('learning_items', $item, date('Y-m-d H:i:s', strtotime('-1 minute')));

        $this->assertSame(0, ScheduledPublishing::publishDue()['items']);
        $this->assertSame('draft', $this->row('learning_items', $item)['status']);
        $this->assertNotNull($this->row('learning_items', $item)['scheduled_publish_at'], 'still scheduled: it publishes once it has a question');

        $assessment = (int) db_connect()->table('assessments')->where('learning_item_id', $item)->get()->getRowArray()['id'];
        AssessmentFactory::multipleChoice($assessment, $this->instructor, [true, false]);

        $this->assertSame(1, ScheduledPublishing::publishDue()['items']);
        $this->assertSame('Published', db_connect()->table('assessments')->where('id', $assessment)->get()->getRowArray()['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | By hand
    |--------------------------------------------------------------------------
    */

    public function testPublishingByHandClearsTheSchedule(): void
    {
        $module = CourseFactory::module($this->offering, 'draft');
        $this->scheduled('course_modules', $module, '2030-01-01 09:00:00');

        $this->service->updateModuleStatus($this->instructor, $this->offering, $module, 'published');

        $this->assertNull($this->row('course_modules', $module)['scheduled_publish_at']);
    }

    public function testDraftingAUnitByHandClearsItsSchedule(): void
    {
        $unit = CourseFactory::unit(CourseFactory::module($this->offering), 'draft');
        $this->scheduled('learning_units', $unit, '2030-01-01 09:00:00');

        $this->service->updateLearningUnitStatus($this->instructor, $this->offering, $unit, ['status' => 'published']);

        $this->assertNull($this->row('learning_units', $unit)['scheduled_publish_at']);
    }

    public function testPublishingAnItemByHandClearsItsSchedule(): void
    {
        $unit   = CourseFactory::unit(CourseFactory::module($this->offering), 'draft');
        [$item] = CourseFactory::items($unit, [['type' => 'reading', 'status' => 'draft']]);
        $this->scheduled('learning_items', $item, '2030-01-01 09:00:00');

        $this->service->updateLearningItem($this->instructor, $this->offering, $item, ['status' => 'published']);

        $this->assertNull($this->row('learning_items', $item)['scheduled_publish_at']);
    }

    public function testEditingTheTitleOfAScheduledItemKeepsItsSchedule(): void
    {
        $unit   = CourseFactory::unit(CourseFactory::module($this->offering), 'draft');
        [$item] = CourseFactory::items($unit, [['type' => 'reading', 'status' => 'draft']]);
        $this->scheduled('learning_items', $item, '2030-01-01 09:00:00');

        $this->service->updateLearningItem($this->instructor, $this->offering, $item, ['title' => 'New title']);

        $this->assertSame('2030-01-01 09:00:00', $this->row('learning_items', $item)['scheduled_publish_at']);
    }
}
