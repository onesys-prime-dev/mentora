<?php

declare(strict_types=1);

use App\Database\Seeds\PlansSeeder;
use App\Models\Subscriptions\PlanModel;
use App\Services\Audit\AuditLogger;
use App\Services\Subscriptions\PlanService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Subscription plans: the table, what can be done to a plan and the rules
 * for each action, the audit entries, the undo when an entry cannot be
 * written, and the sample plan seeder.
 *
 * @internal
 */
final class PlanServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private PlanService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->service = new PlanService();
        $this->admin   = CourseFactory::user('admin');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    /** @return array<string, string> a form post for a valid plan */
    private function form(array $override = []): array
    {
        return array_merge(['name' => 'Standard', 'description' => '', 'features' => '', 'seat_limit' => '', 'storage_limit_mb' => '', 'ai_generation_limit' => ''], $override);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return db_connect()->table('plans')->where('id', $id)->get()->getRowArray();
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
    }

    private function breakAuditing(): void
    {
        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                throw new RuntimeException('the audit log is unavailable');
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The table
    |--------------------------------------------------------------------------
    */

    public function testTheTableHasTheExpectedColumns(): void
    {
        $this->assertTrue(db_connect()->tableExists('plans'));

        foreach (['id', 'name', 'description', 'features', 'seat_limit', 'storage_limit_mb', 'ai_generation_limit', 'status', 'retired_at', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, db_connect()->getFieldNames('plans'));
        }
    }

    public function testNamesAreUniqueIgnoringCase(): void
    {
        db_connect()->table('plans')->insert(['name' => 'Gold']);

        try {
            $stored = db_connect()->table('plans')->insert(['name' => 'GOLD']);
        } catch (CodeIgniter\Database\Exceptions\DatabaseException) {
            $stored = false;
        }

        $this->assertFalse($stored, 'the database refuses a second plan with the same name in another case');
        $this->assertSame(1, db_connect()->table('plans')->countAllResults());
    }

    public function testNewRowsAreActiveByDefault(): void
    {
        db_connect()->table('plans')->insert(['name' => 'Raw']);

        $this->assertSame('active', $this->row((int) db_connect()->insertID())['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function testCreatingStoresTheNormalisedPlan(): void
    {
        $id = $this->service->create($this->form([
            'name' => '  Premium ', 'description' => 'All the things', 'features' => "SIS.Sync\nai.advanced",
            'seat_limit' => '2000', 'storage_limit_mb' => '51200', 'ai_generation_limit' => '1000',
        ]), $this->admin);

        $plan = $this->service->find($id);

        $this->assertSame('Premium', $plan['name']);
        $this->assertSame('All the things', $plan['description']);
        $this->assertSame(['ai.advanced', 'sis.sync'], $plan['features']);
        $this->assertSame(2000, (int) $plan['seat_limit']);
        $this->assertSame(51200, (int) $plan['storage_limit_mb']);
        $this->assertSame(1000, (int) $plan['ai_generation_limit']);
        $this->assertSame('active', $plan['status']);
        $this->assertNull($plan['retired_at']);
    }

    public function testEmptyLimitsAreStoredAsNullMeaningUnlimited(): void
    {
        $id = $this->service->create($this->form(), $this->admin);

        $row = $this->row($id);

        $this->assertNull($row['seat_limit']);
        $this->assertNull($row['storage_limit_mb']);
        $this->assertNull($row['ai_generation_limit']);
        $this->assertSame([], $this->service->find($id)['features']);
    }

    public function testADuplicateNameIsRefusedWhateverItsCase(): void
    {
        $this->service->create($this->form(['name' => 'Gold']), $this->admin);

        try {
            $this->service->create($this->form(['name' => 'gold']), $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already a plan called', $e->getMessage());
        }

        $this->assertSame(1, db_connect()->table('plans')->countAllResults());
    }

    public function testBadInputCreatesNothing(): void
    {
        foreach ([['name' => 'Ab'], ['seat_limit' => '0'], ['features' => 'Not Valid!'], ['storage_limit_mb' => 'lots']] as $bad) {
            try {
                $this->service->create($this->form($bad), $this->admin);
                $this->fail('expected a refusal for ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, db_connect()->table('plans')->countAllResults());
        $this->assertSame([], $this->audit('plan.created'));
    }

    public function testCreatingIsAudited(): void
    {
        $id = $this->service->create($this->form(['name' => 'Premium', 'features' => 'sis.sync', 'seat_limit' => '100']), $this->admin);

        $entry = $this->audit('plan.created')[0];

        $this->assertSame((string) $this->admin->id, (string) $entry['actor_user_id']);
        $this->assertSame('plan', $entry['target_type']);
        $this->assertSame((string) $id, $entry['target_id']);
        $this->assertSame(
            ['name' => 'Premium', 'description' => null, 'features' => ['sis.sync'], 'seat_limit' => 100, 'storage_limit_mb' => null, 'ai_generation_limit' => null],
            json_decode($entry['new_values'], true),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit
    |--------------------------------------------------------------------------
    */

    public function testEditingChangesTheFields(): void
    {
        $id = $this->service->create($this->form(['name' => 'Basic', 'seat_limit' => '100']), $this->admin);

        $this->service->update($id, $this->form(['name' => 'Basic Plus', 'features' => 'sis.sync', 'seat_limit' => '250', 'ai_generation_limit' => '50']), $this->admin);

        $plan = $this->service->find($id);

        $this->assertSame('Basic Plus', $plan['name']);
        $this->assertSame(['sis.sync'], $plan['features']);
        $this->assertSame(250, (int) $plan['seat_limit']);
        $this->assertSame(50, (int) $plan['ai_generation_limit']);
    }

    public function testEditingCanClearALimitToUnlimited(): void
    {
        $id = $this->service->create($this->form(['seat_limit' => '100']), $this->admin);

        $this->service->update($id, $this->form(['seat_limit' => '']), $this->admin);

        $this->assertNull($this->row($id)['seat_limit']);
    }

    public function testEditingIsAuditedWithTheWholeBeforeAndAfter(): void
    {
        $id = $this->service->create($this->form(['name' => 'Basic', 'seat_limit' => '100']), $this->admin);

        $this->service->update($id, $this->form(['name' => 'Basic', 'seat_limit' => '250']), $this->admin);

        $entry = $this->audit('plan.updated')[0];

        $this->assertSame(100, json_decode($entry['old_values'], true)['seat_limit']);
        $this->assertSame(250, json_decode($entry['new_values'], true)['seat_limit']);
        $this->assertSame('Basic', json_decode($entry['new_values'], true)['name']);
    }

    public function testSavingWithoutChangesDoesNothing(): void
    {
        $id = $this->service->create($this->form(['name' => 'Basic', 'features' => 'b.two, a.one']), $this->admin);

        $this->service->update($id, $this->form(['name' => 'Basic', 'features' => "a.one\nb.two"]), $this->admin);

        $this->assertSame([], $this->audit('plan.updated'));
    }

    public function testAPlanCanKeepItsOwnNameButNotTakeAnothers(): void
    {
        $gold   = $this->service->create($this->form(['name' => 'Gold']), $this->admin);
        $silver = $this->service->create($this->form(['name' => 'Silver']), $this->admin);

        $this->service->update($gold, $this->form(['name' => 'Gold', 'seat_limit' => '10']), $this->admin);
        $this->assertSame(10, (int) $this->row($gold)['seat_limit']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already a plan called');

        $this->service->update($silver, $this->form(['name' => 'GOLD']), $this->admin);
    }

    public function testBadEditsChangeNothing(): void
    {
        $id = $this->service->create($this->form(['name' => 'Basic', 'seat_limit' => '100']), $this->admin);

        try {
            $this->service->update($id, $this->form(['name' => 'Basic', 'seat_limit' => '-1']), $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(100, (int) $this->row($id)['seat_limit']);
    }

    public function testAMissingPlanCannotBeEdited(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->service->update(999999, $this->form(), $this->admin);
    }

    public function testARetiredPlanCannotBeEdited(): void
    {
        $id = $this->service->create($this->form(['name' => 'Old']), $this->admin);
        $this->service->retire($id, $this->admin);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reinstate it first');

        $this->service->update($id, $this->form(['name' => 'Old', 'seat_limit' => '5']), $this->admin);
    }

    /*
    |--------------------------------------------------------------------------
    | Retire and reinstate
    |--------------------------------------------------------------------------
    */

    public function testRetiringMarksThePlanRetiredWithATime(): void
    {
        $id = $this->service->create($this->form(['name' => 'Old']), $this->admin);

        $this->service->retire($id, $this->admin);

        $row = $this->row($id);

        $this->assertSame('retired', $row['status']);
        $this->assertEqualsWithDelta(time(), strtotime($row['retired_at']), 5);
    }

    public function testARetiredPlanIsKeptNotDeleted(): void
    {
        $id = $this->service->create($this->form(['name' => 'Old', 'seat_limit' => '100']), $this->admin);

        $this->service->retire($id, $this->admin);

        $this->assertSame(100, (int) $this->row($id)['seat_limit'], 'everything about it is still there');
        $this->assertNotNull($this->service->find($id));
    }

    public function testARetiredPlanIsNotOfferedForNewSubscriptions(): void
    {
        $keep = $this->service->create($this->form(['name' => 'Keep']), $this->admin);
        $old  = $this->service->create($this->form(['name' => 'Old']), $this->admin);
        $this->service->retire($old, $this->admin);

        $selectable = array_map('intval', array_column($this->service->selectable(), 'id'));

        $this->assertSame([$keep], $selectable);
        $this->assertSame(1, (new PlanModel())->selectable()->countAllResults());
    }

    public function testTheFullListShowsActivePlansFirstThenRetiredByName(): void
    {
        $b   = $this->service->create($this->form(['name' => 'Beta']), $this->admin);
        $a   = $this->service->create($this->form(['name' => 'Alpha']), $this->admin);
        $old = $this->service->create($this->form(['name' => 'Aardvark']), $this->admin);
        $this->service->retire($old, $this->admin);

        $this->assertSame([$a, $b, $old], array_map('intval', array_column($this->service->all(), 'id')));
        $this->assertSame([$old], array_map('intval', array_column($this->service->all('retired'), 'id')));
        $this->assertSame([$a, $b], array_map('intval', array_column($this->service->all('active'), 'id')));
    }

    public function testReinstatingOffersItAgain(): void
    {
        $id = $this->service->create($this->form(['name' => 'Old']), $this->admin);
        $this->service->retire($id, $this->admin);

        $this->service->reinstate($id, $this->admin);

        $row = $this->row($id);

        $this->assertSame('active', $row['status']);
        $this->assertNull($row['retired_at']);
        $this->assertCount(1, $this->service->selectable());
    }

    public function testRetiringAndReinstatingAreAudited(): void
    {
        $id = $this->service->create($this->form(['name' => 'Old']), $this->admin);

        $this->service->retire($id, $this->admin);
        $this->service->reinstate($id, $this->admin);

        $retired    = $this->audit('plan.retired')[0];
        $reinstated = $this->audit('plan.reinstated')[0];

        $this->assertSame((string) $id, $retired['target_id']);
        $this->assertSame(['name' => 'Old', 'status' => 'active'], json_decode($retired['old_values'], true));
        $this->assertSame(['name' => 'Old', 'status' => 'retired'], json_decode($retired['new_values'], true));
        $this->assertSame(['name' => 'Old', 'status' => 'retired'], json_decode($reinstated['old_values'], true));
    }

    public function testRetiringTwiceOrReinstatingAnActivePlanIsRefused(): void
    {
        $id = $this->service->create($this->form(['name' => 'Old']), $this->admin);

        try {
            $this->service->reinstate($id, $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not retired', $e->getMessage());
        }

        $this->service->retire($id, $this->admin);

        try {
            $this->service->retire($id, $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already retired', $e->getMessage());
        }

        $this->assertCount(1, $this->audit('plan.retired'));
    }

    public function testRetiringAMissingPlanIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->retire(999999, $this->admin);
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing changes without a trace
    |--------------------------------------------------------------------------
    */

    public function testACreationThatCannotBeAuditedLeavesNothingBehind(): void
    {
        $this->breakAuditing();

        try {
            $this->service->create($this->form(['name' => 'Ghost']), $this->admin);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('the audit log is unavailable', $e->getMessage());
        }

        $this->assertSame(0, db_connect()->table('plans')->countAllResults());
    }

    public function testAnEditThatCannotBeAuditedIsTakenBack(): void
    {
        $id = $this->service->create($this->form(['name' => 'Basic', 'features' => 'a.one', 'seat_limit' => '100', 'storage_limit_mb' => '500']), $this->admin);
        $this->breakAuditing();

        try {
            $this->service->update($id, $this->form(['name' => 'Changed', 'features' => 'b.two', 'seat_limit' => '', 'storage_limit_mb' => '900']), $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $plan = $this->service->find($id);

        $this->assertSame('Basic', $plan['name']);
        $this->assertSame(['a.one'], $plan['features']);
        $this->assertSame(100, (int) $plan['seat_limit']);
        $this->assertSame(500, (int) $plan['storage_limit_mb']);
    }

    public function testARetirementThatCannotBeAuditedIsTakenBack(): void
    {
        $id = $this->service->create($this->form(['name' => 'Old']), $this->admin);
        $this->breakAuditing();

        try {
            $this->service->retire($id, $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $row = $this->row($id);

        $this->assertSame('active', $row['status']);
        $this->assertNull($row['retired_at']);
    }

    public function testAReinstatementThatCannotBeAuditedIsTakenBack(): void
    {
        $id = $this->service->create($this->form(['name' => 'Old']), $this->admin);
        $this->service->retire($id, $this->admin);
        $retiredAt = $this->row($id)['retired_at'];
        $this->breakAuditing();

        try {
            $this->service->reinstate($id, $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $row = $this->row($id);

        $this->assertSame('retired', $row['status']);
        $this->assertSame($retiredAt, $row['retired_at'], 'back to exactly how it was');
    }

    /*
    |--------------------------------------------------------------------------
    | The sample plan
    |--------------------------------------------------------------------------
    */

    public function testTheSeederCreatesOneSamplePlanAndIsSafeToRunTwice(): void
    {
        ob_start();
        $this->seed(PlansSeeder::class);
        $this->seed(PlansSeeder::class);
        ob_end_clean();

        $plans = $this->service->all();

        $this->assertCount(1, $plans);
        $this->assertSame('Standard', $plans[0]['name']);
        $this->assertSame('active', $plans[0]['status']);
        $this->assertSame(500, (int) $plans[0]['seat_limit']);
    }
}
