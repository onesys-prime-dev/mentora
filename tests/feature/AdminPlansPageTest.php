<?php

declare(strict_types=1);

use App\Models\Subscriptions\PlanModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Config\Services;
use Config\FeatureFlags as FlagConfig;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The plans pages as a system administrator uses them, and as everyone else
 * is refused: list, add, edit, retire and reinstate. Editing, retiring and
 * reinstating need the password again.
 *
 * @internal
 */
final class AdminPlansPageTest extends RouteTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        // The feature keys these tests give plans must be flags that exist.
        config(FlagConfig::class)->flags = [
            'sis.sync'    => ['description' => 'SIS', 'default' => false],
            'ai.advanced' => ['description' => 'AI', 'default' => false],
            'a.one'       => ['description' => 'One', 'default' => false],
            'b.two'       => ['description' => 'Two', 'default' => false],
        ];

        $this->admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Factories::reset('config');
        $_GET = [];
        service('superglobals')->setGetArray([]);

        parent::tearDown();
    }

    private function plan(string $name = 'Standard', string $status = 'active', ?int $seats = 100): int
    {
        db_connect()->table('plans')->insert([
            'name'       => $name,
            'features'   => json_encode(['sis.sync']),
            'seat_limit' => $seats,
            'status'     => $status,
            'retired_at' => $status === 'retired' ? date('Y-m-d H:i:s') : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return db_connect()->table('plans')->where('id', $id)->get()->getRowArray();
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    /** @param array<string, string> $data */
    private function submit(string $uri, array $data = []): TestResponse
    {
        return $this->step('POST', $uri, $data);
    }

    private function body(string $uri = 'admin/plans'): string
    {
        return $this->step('GET', $uri)->response()->getBody();
    }

    private function location(TestResponse $response): string
    {
        return $response->response()->getHeaderLine('Location');
    }

    private function auditCount(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /** @return array<string, string> */
    private function form(array $override = []): array
    {
        return array_merge(['name' => 'Premium', 'description' => '', 'features' => '', 'seat_limit' => '', 'storage_limit_mb' => '', 'ai_generation_limit' => ''], $override);
    }

    /*
    |--------------------------------------------------------------------------
    | Who may use it
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanSeeOrChangePlans(string $role): void
    {
        $id = $this->plan('Standard');

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        foreach (['admin/plans', 'admin/plans/new', "admin/plans/{$id}/edit"] as $uri) {
            $this->assertSame(302, $this->step('GET', $uri)->response()->getStatusCode(), "{$role} must not open {$uri}");
        }

        $this->confirmedPassword();
        $this->submit('admin/plans', $this->form(['name' => 'Sneaky']));
        $this->submit("admin/plans/{$id}", $this->form(['name' => 'Renamed By Sneak']));
        $this->submit("admin/plans/{$id}/retire");

        $this->assertSame(0, db_connect()->table('plans')->where('name', 'Sneaky')->countAllResults());
        $this->assertSame('Standard', $this->row($id)['name']);
        $this->assertSame('active', $this->row($id)['status']);
    }

    public function testAdminHomeLinksToPlans(): void
    {
        $this->assertStringContainsString('admin/plans', $this->body('admin/dashboard'));
    }

    /*
    |--------------------------------------------------------------------------
    | The list
    |--------------------------------------------------------------------------
    */

    public function testAnEmptyListSaysSo(): void
    {
        $this->assertStringContainsString('No plans yet', $this->body());
    }

    public function testThePlansAreListedWithTheirLimitsAndFeatures(): void
    {
        $id = $this->plan('Standard', 'active', 250);
        db_connect()->table('plans')->where('id', $id)->update(['storage_limit_mb' => 20480]);

        $body = $this->body();

        $this->assertStringContainsString('Standard', $body);
        $this->assertStringContainsString('250', $body);
        $this->assertStringContainsString('20,480 MB', $body);
        $this->assertStringContainsString('sis.sync', $body);
        $this->assertStringContainsString('Unlimited', $body, 'the AI cap was left empty');
    }

    public function testButtonsMatchEachPlansState(): void
    {
        $active  = $this->plan('Active Plan');
        $retired = $this->plan('Old Plan', 'retired');

        $body = $this->body();

        $this->assertStringContainsString("admin/plans/{$active}/edit", $body);
        $this->assertStringContainsString("admin/plans/{$active}/retire", $body);
        $this->assertStringNotContainsString("admin/plans/{$active}/reinstate", $body);
        $this->assertStringContainsString("admin/plans/{$retired}/reinstate", $body);
        $this->assertStringNotContainsString("admin/plans/{$retired}/edit", $body);
        $this->assertStringNotContainsString("admin/plans/{$retired}/retire", $body);
        $this->assertStringContainsString('Retired</span>', $body);
    }

    public function testNamesAndDescriptionsAreEscaped(): void
    {
        $id = $this->plan('<script>alert(1)</script> Plan');
        db_connect()->table('plans')->where('id', $id)->update(['description' => '<img src=x onerror=alert(2)>']);

        $body = $this->body();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Add
    |--------------------------------------------------------------------------
    */

    public function testTheAddFormHasEveryField(): void
    {
        $body = $this->body('admin/plans/new');

        foreach (['name', 'description', 'features', 'seat_limit', 'storage_limit_mb', 'ai_generation_limit'] as $field) {
            $this->assertStringContainsString("name=\"{$field}\"", $body);
        }
    }

    public function testAddingAPlanDoesNotNeedThePasswordAndShowsInTheList(): void
    {
        $response = $this->submit('admin/plans', $this->form(['name' => 'Premium', 'features' => 'sis.sync', 'seat_limit' => '2000']));

        $this->assertStringEndsWith('admin/plans', $this->location($response));

        $row = db_connect()->table('plans')->where('name', 'Premium')->get()->getRowArray();

        $this->assertSame('active', $row['status']);
        $this->assertSame(2000, (int) $row['seat_limit']);
        $this->assertStringContainsString('Plan added', $this->body());
        $this->assertSame(1, $this->auditCount('plan.created'));
    }

    public function testAddingWithBadDetailsGoesBackWithTheMessageAndTheInput(): void
    {
        $response = $this->submit('admin/plans', $this->form(['name' => 'Premium', 'seat_limit' => '-5', 'features' => 'sis.sync']));

        $this->assertStringEndsWith('admin/plans/new', $this->location($response));

        $body = $this->body('admin/plans/new');

        $this->assertStringContainsString('Student seats must be a whole number', $body);
        $this->assertStringContainsString('value="Premium"', $body, 'what was typed is not lost');
        $this->assertStringContainsString('sis.sync', $body);
        $this->assertSame(0, db_connect()->table('plans')->countAllResults());
    }

    public function testAddingWithAFeatureThatIsNotAFlagShowsTheChoices(): void
    {
        $this->submit('admin/plans', $this->form(['name' => 'Typo Plan', 'features' => 'sis.snyc']));

        $this->assertSame(0, db_connect()->table('plans')->countAllResults());

        $body = $this->body('admin/plans/new');

        $this->assertStringContainsString('is not a feature flag', $body);
        $this->assertStringContainsString('sis.snyc', $body, 'what was typed is kept');
    }

    public function testTheFormListsTheFeaturesThatCanBeGranted(): void
    {
        $this->assertStringContainsString('Available:', $this->body('admin/plans/new'));
        $this->assertStringContainsString('sis.sync, ai.advanced, a.one, b.two', $this->body('admin/plans/new'));
    }

    public function testWithNoFlagsTheFormSaysThereIsNothingToChooseFrom(): void
    {
        config(FlagConfig::class)->flags = [];

        $this->assertStringContainsString('no feature flags to choose from', $this->body('admin/plans/new'));
    }

    public function testAddingADuplicateNameIsRefused(): void
    {
        $this->plan('Premium');

        $this->submit('admin/plans', $this->form(['name' => 'premium']));

        $this->assertSame(1, db_connect()->table('plans')->countAllResults());
        $this->assertStringContainsString('already a plan called', $this->body('admin/plans/new'));
    }

    /*
    |--------------------------------------------------------------------------
    | Edit
    |--------------------------------------------------------------------------
    */

    public function testOpeningTheEditFormNeedsThePasswordAndComesBackToIt(): void
    {
        $id = $this->plan('Standard');

        $response = $this->step('GET', "admin/plans/{$id}/edit");

        $this->assertStringEndsWith('admin/reauth', $this->location($response));

        $this->confirmedPassword();
        $body = $this->body("admin/plans/{$id}/edit");

        $this->assertStringContainsString('value="Standard"', $body);
        $this->assertStringContainsString('sis.sync', $body);
        $this->assertStringContainsString('every institution on this plan', $body);
    }

    public function testSavingAnEditNeedsThePasswordToo(): void
    {
        $id = $this->plan('Standard', 'active', 100);

        $response = $this->submit("admin/plans/{$id}", $this->form(['name' => 'Standard', 'seat_limit' => '999']));

        $this->assertStringEndsWith('admin/reauth', $this->location($response));
        $this->assertSame(100, (int) $this->row($id)['seat_limit']);
        $this->assertSame(0, $this->auditCount('plan.updated'));
    }

    public function testOnceConfirmedAnEditIsSavedAndAudited(): void
    {
        $id = $this->plan('Standard', 'active', 100);
        $this->confirmedPassword();

        $response = $this->submit("admin/plans/{$id}", $this->form(['name' => 'Standard Plus', 'features' => 'ai.advanced', 'seat_limit' => '999']));

        $this->assertStringEndsWith('admin/plans', $this->location($response));

        $row = $this->row($id);

        $this->assertSame('Standard Plus', $row['name']);
        $this->assertSame(999, (int) $row['seat_limit']);
        $this->assertSame(['ai.advanced'], json_decode($row['features'], true));
        $this->assertStringContainsString('Plan saved', $this->body());
        $this->assertSame(1, $this->auditCount('plan.updated'));
    }

    public function testAnInvalidEditReturnsToTheFormWithTheMessage(): void
    {
        $id = $this->plan('Standard');
        $this->confirmedPassword();

        $response = $this->submit("admin/plans/{$id}", $this->form(['name' => 'Ab']));

        $this->assertStringEndsWith("admin/plans/{$id}/edit", $this->location($response));
        $this->assertSame('Standard', $this->row($id)['name']);
        $this->assertStringContainsString('plan name must be', $this->body("admin/plans/{$id}/edit"));
    }

    public function testEditingAMissingPlanIsNotFound(): void
    {
        $this->confirmedPassword();

        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->step('GET', 'admin/plans/999999/edit');
    }

    public function testARetiredPlanCannotBeEditedEvenByForgingTheRequest(): void
    {
        $id = $this->plan('Old Plan', 'retired');
        $this->confirmedPassword();

        $this->submit("admin/plans/{$id}", $this->form(['name' => 'Sneaky Rename']));

        $this->assertSame('Old Plan', $this->row($id)['name']);
        $this->assertStringContainsString('Reinstate it first', $this->body("admin/plans/{$id}/edit"));
    }

    /*
    |--------------------------------------------------------------------------
    | Retire and reinstate
    |--------------------------------------------------------------------------
    */

    public function testRetiringNeedsThePassword(): void
    {
        $id = $this->plan('Standard');

        $response = $this->submit("admin/plans/{$id}/retire");

        $this->assertStringEndsWith('admin/reauth', $this->location($response));
        $this->assertSame('active', $this->row($id)['status']);
    }

    public function testOnceConfirmedRetiringAndReinstatingWork(): void
    {
        $id = $this->plan('Standard');
        $this->confirmedPassword();

        $this->submit("admin/plans/{$id}/retire");
        $this->assertSame('retired', $this->row($id)['status']);
        $this->assertStringContainsString('Plan retired', $this->body());
        $this->assertSame(0, (new PlanModel())->selectable()->where('id', $id)->countAllResults());

        $this->submit("admin/plans/{$id}/reinstate");
        $this->assertSame('active', $this->row($id)['status']);
        $this->assertStringContainsString('Plan reinstated', $this->body());

        $this->assertSame(1, $this->auditCount('plan.retired'));
        $this->assertSame(1, $this->auditCount('plan.reinstated'));
    }

    public function testRetiringAnAlreadyRetiredPlanShowsWhy(): void
    {
        $id = $this->plan('Old Plan', 'retired');
        $this->confirmedPassword();

        $this->submit("admin/plans/{$id}/retire");

        $this->assertStringContainsString('already retired', $this->body());
        $this->assertSame(0, $this->auditCount('plan.retired'));
    }

    public function testReinstatingAnActivePlanShowsWhy(): void
    {
        $id = $this->plan('Standard');
        $this->confirmedPassword();

        $this->submit("admin/plans/{$id}/reinstate");

        $this->assertStringContainsString('not retired', $this->body());
    }

    /*
    |--------------------------------------------------------------------------
    | The whole journey
    |--------------------------------------------------------------------------
    */

    public function testAddEditRetireAndReinstateAPlan(): void
    {
        $this->submit('admin/plans', $this->form(['name' => 'Journey Plan', 'seat_limit' => '50']));
        $id = (int) db_connect()->table('plans')->where('name', 'Journey Plan')->get()->getRowArray()['id'];

        $this->confirmedPassword();
        $this->submit("admin/plans/{$id}", $this->form(['name' => 'Journey Plan', 'seat_limit' => '75']));
        $this->submit("admin/plans/{$id}/retire");
        $this->submit("admin/plans/{$id}/reinstate");

        $this->assertSame(75, (int) $this->row($id)['seat_limit']);
        $this->assertSame('active', $this->row($id)['status']);

        $actions = array_column(db_connect()->table('audit_logs')->like('action', 'plan.', 'after')->orderBy('id')->get()->getResultArray(), 'action');

        $this->assertSame(['plan.created', 'plan.updated', 'plan.retired', 'plan.reinstated'], $actions);
    }
}
