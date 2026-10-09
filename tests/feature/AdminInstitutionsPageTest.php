<?php

declare(strict_types=1);

use App\Models\Academic\InstitutionModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The institutions pages as a system administrator uses them, and as
 * everyone else is refused: list, add, edit, suspend, delete (with the name
 * typed), and restore (which needs the password again).
 *
 * @internal
 */
final class AdminInstitutionsPageTest extends RouteTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        service('superglobals')->setGetArray([]);

        parent::tearDown();
    }

    private function seeded(string $code, string $status = 'active', ?string $name = null): int
    {
        db_connect()->table('institutions')->insert(['name' => $name ?? "Institution {$code}", 'code' => $code, 'status' => $status, 'created_at' => date('Y-m-d H:i:s')]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        return db_connect()->table('institutions')->where('id', $id)->get()->getRowArray();
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

    private function body(string $uri = 'admin/institutions'): string
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
    public function testNobodyElseCanSeeOrChangeInstitutions(string $role): void
    {
        $id = $this->seeded('TARGET', 'active', 'Target University');

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        foreach (['admin/institutions', 'admin/institutions/new', "admin/institutions/{$id}/edit"] as $uri) {
            $this->assertSame(302, $this->step('GET', $uri)->response()->getStatusCode(), "{$role} must not open {$uri}");
        }

        $this->submit('admin/institutions', ['name' => 'Sneaky University', 'code' => 'SNEAK']);
        $this->submit("admin/institutions/{$id}", ['name' => 'Renamed By Sneak']);
        $this->submit("admin/institutions/{$id}/suspend");
        $this->submit("admin/institutions/{$id}/delete", ['confirm_name' => 'Target University']);

        $this->assertSame(0, db_connect()->table('institutions')->where('code', 'SNEAK')->countAllResults());
        $this->assertSame('Target University', $this->row($id)['name']);
        $this->assertSame('active', $this->row($id)['status']);
        $this->assertNull($this->row($id)['deleted_at']);
    }

    public function testAdminHomeLinksToInstitutions(): void
    {
        $this->assertStringContainsString('admin/institutions', $this->body('admin/dashboard'));
    }

    /*
    |--------------------------------------------------------------------------
    | The list
    |--------------------------------------------------------------------------
    */

    public function testTheListShowsInstitutionsWithTheirStatus(): void
    {
        $this->seeded('ACME', 'active', 'Acme University');
        $this->seeded('OLD', 'past', 'Old College');

        $body = $this->body();

        $this->assertStringContainsString('Acme University', $body);
        $this->assertStringContainsString('ACME', $body);
        $this->assertStringContainsString('Old College', $body);
        $this->assertStringContainsString('2 institutions', $body);
    }

    public function testAnEmptyListSaysSo(): void
    {
        $this->assertStringContainsString('No institutions yet', $this->body());
    }

    public function testSearchStatusAndSortNarrowAndOrderTheList(): void
    {
        $this->seeded('ACME', 'active', 'Acme University');
        $this->seeded('ZETA', 'past', 'Zeta College');
        $this->seeded('BETA', 'past', 'Beta College');

        $body = $this->body('admin/institutions?status=past&sort=name&dir=desc');

        $this->assertStringNotContainsString('Acme University', $body);
        $this->assertLessThan(strpos($body, 'Beta College'), strpos($body, 'Zeta College'), 'descending by name');
        $this->assertStringContainsString('2 institutions match', $body);

        $this->assertStringContainsString('Acme University', $this->body('admin/institutions?q=acme'));
        $this->assertStringNotContainsString('Zeta College', $this->body('admin/institutions?q=acme'));
        $this->assertStringContainsString('No institutions match these filters', $this->body('admin/institutions?q=nothing-like-this'));
    }

    public function testPagingKeepsTheFilters(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->seeded("P{$i}", 'past', "Paged College {$i}");
        }

        $body = $this->body('admin/institutions?per_page=2&status=past');

        $this->assertStringContainsString('Page 1 of 2', $body);
        $this->assertMatchesRegularExpression('/href="[^"]*page=2[^"]*"/', $body);
    }

    public function testHostileQueryValuesAreHarmless(): void
    {
        $this->seeded('ACME');

        foreach (['sort[]=x', 'status[]=x', 'q[]=x', 'page=-3', 'per_page=999999', 'sort=deleted_at', "dir=%3B%20DROP"] as $query) {
            $this->assertTrue($this->step('GET', "admin/institutions?{$query}")->isOK(), "?{$query} must not break the page");
        }
    }

    public function testNamesAreEscapedOnThePage(): void
    {
        $this->seeded('XSS', 'active', '<script>alert(1)</script> College');

        $body = $this->body();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testButtonsMatchWhatEachInstitutionCanDo(): void
    {
        $active  = $this->seeded('ACT', 'active', 'Active College');
        $pending = $this->seeded('PEN', 'pending', 'Pending College');
        $past    = $this->seeded('PST', 'past', 'Past College');

        $body = $this->body();

        $this->assertStringContainsString("admin/institutions/{$active}/suspend", $body);
        $this->assertStringNotContainsString("admin/institutions/{$active}/delete", $body, 'an active institution cannot be deleted');
        $this->assertStringContainsString("admin/institutions/{$pending}/delete", $body);
        $this->assertStringNotContainsString("admin/institutions/{$pending}/suspend", $body);
        $this->assertStringContainsString("admin/institutions/{$past}/delete", $body);
        $this->assertStringNotContainsString("admin/institutions/{$past}/suspend", $body);
    }

    public function testTheDeletedFilterOffersRestoreInsteadOfTheOtherActions(): void
    {
        $id = $this->seeded('GONE', 'past', 'Gone College');
        (new InstitutionModel())->delete($id);

        $body = $this->body('admin/institutions?status=deleted');

        $this->assertStringContainsString("admin/institutions/{$id}/restore", $body);
        $this->assertStringNotContainsString("admin/institutions/{$id}/edit", $body);
        $this->assertStringNotContainsString("admin/institutions/{$id}/delete", $body);
        $this->assertStringContainsString('Deleted</span>', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Add
    |--------------------------------------------------------------------------
    */

    public function testTheAddFormAsksForNameAndCode(): void
    {
        $body = $this->body('admin/institutions/new');

        $this->assertStringContainsString('name="name"', $body);
        $this->assertStringContainsString('name="code"', $body);
    }

    public function testAddingCreatesAPendingInstitutionAndShowsItInTheList(): void
    {
        $response = $this->submit('admin/institutions', ['name' => 'Acme University', 'code' => 'acme']);

        $this->assertStringEndsWith('admin/institutions', $this->location($response));

        $row = db_connect()->table('institutions')->where('code', 'ACME')->get()->getRowArray();

        $this->assertSame('Acme University', $row['name']);
        $this->assertSame('pending', $row['status']);

        $body = $this->body();

        $this->assertStringContainsString('Added Acme University', $body);
        $this->assertStringContainsString('Acme University', $body);
        $this->assertSame(1, $this->auditCount('institution.created'));
    }

    public function testAddingWithBadDetailsGoesBackToTheFormWithTheMessageAndTheInput(): void
    {
        $response = $this->submit('admin/institutions', ['name' => 'Acme University', 'code' => 'bad code!']);

        $this->assertStringEndsWith('admin/institutions/new', $this->location($response));

        $body = $this->body('admin/institutions/new');

        $this->assertStringContainsString('The code must be', $body);
        $this->assertStringContainsString('value="Acme University"', $body, 'what was typed is not lost');
        $this->assertSame(0, db_connect()->table('institutions')->countAllResults());
    }

    public function testAddingADuplicateCodeIsRefused(): void
    {
        $this->seeded('ACME');

        $this->submit('admin/institutions', ['name' => 'Another University', 'code' => 'acme']);

        $this->assertSame(1, db_connect()->table('institutions')->where('code', 'ACME')->countAllResults());
        $this->assertStringContainsString('already exists', $this->body('admin/institutions/new'));
    }

    /*
    |--------------------------------------------------------------------------
    | Edit
    |--------------------------------------------------------------------------
    */

    public function testTheEditFormShowsTheCodeButDoesNotOfferToChangeIt(): void
    {
        $id = $this->seeded('ACME', 'active', 'Acme University');

        $body = $this->body("admin/institutions/{$id}/edit");

        $this->assertStringContainsString('value="Acme University"', $body);
        $this->assertStringContainsString('value="ACME"', $body);
        $this->assertStringContainsString('cannot be changed', $body);
        $this->assertStringNotContainsString('name="code"', $body, 'the code is not even submitted');
    }

    public function testEditingRenamesButNeverChangesTheCode(): void
    {
        $id = $this->seeded('ACME', 'active', 'Acme University');

        $this->submit("admin/institutions/{$id}", ['name' => 'Acme State University', 'code' => 'HACKED']);

        $row = $this->row($id);

        $this->assertSame('Acme State University', $row['name']);
        $this->assertSame('ACME', $row['code']);
        $this->assertSame(1, $this->auditCount('institution.updated'));
    }

    public function testEditingToAnInvalidNameShowsTheMessage(): void
    {
        $id = $this->seeded('ACME', 'active', 'Acme University');

        $response = $this->submit("admin/institutions/{$id}", ['name' => 'Ab']);

        $this->assertStringEndsWith("admin/institutions/{$id}/edit", $this->location($response));
        $this->assertSame('Acme University', $this->row($id)['name']);
        $this->assertStringContainsString('at least 3', $this->body("admin/institutions/{$id}/edit"));
    }

    public function testEditingAMissingInstitutionIsNotFound(): void
    {
        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->step('GET', 'admin/institutions/999999/edit');
    }

    public function testADeletedInstitutionCannotBeEdited(): void
    {
        $id = $this->seeded('GONE', 'past', 'Gone College');
        (new InstitutionModel())->delete($id);

        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->step('GET', "admin/institutions/{$id}/edit");
    }

    /*
    |--------------------------------------------------------------------------
    | Suspend
    |--------------------------------------------------------------------------
    */

    public function testSuspendingMakesAnActiveInstitutionPast(): void
    {
        $id = $this->seeded('ACME', 'active', 'Acme University');

        $this->submit("admin/institutions/{$id}/suspend");

        $this->assertSame('past', $this->row($id)['status']);
        $this->assertStringContainsString('Institution suspended', $this->body());
        $this->assertSame(1, $this->auditCount('institution.suspended'));
    }

    public function testSuspendingSomethingNotActiveShowsWhy(): void
    {
        $id = $this->seeded('PEN', 'pending');

        $this->submit("admin/institutions/{$id}/suspend");

        $this->assertSame('pending', $this->row($id)['status']);
        $this->assertStringContainsString('active or read-only', $this->body());
        $this->assertSame(0, $this->auditCount('institution.suspended'));
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function testDeletingWithTheRightNameHidesTheInstitution(): void
    {
        $id = $this->seeded('OLD', 'past', 'Old College');

        $this->submit("admin/institutions/{$id}/delete", ['confirm_name' => 'Old College']);

        $this->assertNotNull($this->row($id)['deleted_at']);
        $this->assertStringNotContainsString('Old College', $this->body());
        $this->assertSame(1, $this->auditCount('institution.deleted'));
    }

    public function testDeletingWithTheWrongNameChangesNothingAndSaysWhy(): void
    {
        $id = $this->seeded('OLD', 'past', 'Old College');

        $this->submit("admin/institutions/{$id}/delete", ['confirm_name' => 'old college']);

        $this->assertNull($this->row($id)['deleted_at']);
        $this->assertStringContainsString('exact name', $this->body());
        $this->assertSame(0, $this->auditCount('institution.deleted'));
    }

    public function testDeletingWithNoNameAtAllChangesNothing(): void
    {
        $id = $this->seeded('OLD', 'past', 'Old College');

        $this->submit("admin/institutions/{$id}/delete");

        $this->assertNull($this->row($id)['deleted_at']);
    }

    public function testAnActiveInstitutionCannotBeDeletedEvenWithTheRightName(): void
    {
        $id = $this->seeded('LIVE', 'active', 'Live College');

        $this->submit("admin/institutions/{$id}/delete", ['confirm_name' => 'Live College']);

        $this->assertNull($this->row($id)['deleted_at']);
        $this->assertStringContainsString('Suspend it', $this->body());
    }

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    */

    public function testRestoringNeedsThePasswordFirst(): void
    {
        $id = $this->seeded('GONE', 'past', 'Gone College');
        (new InstitutionModel())->delete($id);

        $response = $this->submit("admin/institutions/{$id}/restore");

        $this->assertStringEndsWith('admin/reauth', $this->location($response));
        $this->assertNotNull($this->row($id)['deleted_at'], 'still deleted');
        $this->assertSame(0, $this->auditCount('institution.restored'));
    }

    public function testOnceConfirmedARestoreBringsTheInstitutionBack(): void
    {
        $id = $this->seeded('GONE', 'past', 'Gone College');
        (new InstitutionModel())->delete($id);
        $this->confirmedPassword();

        $response = $this->submit("admin/institutions/{$id}/restore");

        $this->assertStringEndsWith('admin/institutions', $this->location($response));
        $this->assertNull($this->row($id)['deleted_at']);
        $this->assertSame('past', $this->row($id)['status']);
        $this->assertStringContainsString('Institution restored', $this->body());
        $this->assertStringContainsString('Gone College', $this->body());
        $this->assertSame(1, $this->auditCount('institution.restored'));
    }

    public function testRestoringAnInstitutionThatIsNotDeletedShowsWhy(): void
    {
        $id = $this->seeded('LIVE', 'active');
        $this->confirmedPassword();

        $this->submit("admin/institutions/{$id}/restore");

        $this->assertStringContainsString('not deleted', $this->body());
    }

    /*
    |--------------------------------------------------------------------------
    | The whole journey
    |--------------------------------------------------------------------------
    */

    public function testAddFindSuspendDeleteAndRestoreAnInstitution(): void
    {
        $this->submit('admin/institutions', ['name' => 'Journey University', 'code' => 'JOURNEY']);
        $id = (int) db_connect()->table('institutions')->where('code', 'JOURNEY')->get()->getRowArray()['id'];

        $this->assertStringContainsString('Journey University', $this->body('admin/institutions?q=journey'));

        // Pending: can be deleted, cannot be suspended. Pretend onboarding finished.
        db_connect()->table('institutions')->where('id', $id)->update(['status' => 'active']);

        $this->submit("admin/institutions/{$id}/suspend");
        $this->submit("admin/institutions/{$id}/delete", ['confirm_name' => 'Journey University']);

        $this->assertStringNotContainsString('Journey University', $this->body('admin/institutions?q=journey'));
        $this->assertStringContainsString('Journey University', $this->body('admin/institutions?status=deleted'));

        $this->confirmedPassword();
        $this->submit("admin/institutions/{$id}/restore");

        $this->assertStringContainsString('Journey University', $this->body('admin/institutions?q=journey'));

        $actions = array_column(db_connect()->table('audit_logs')->like('action', 'institution.', 'after')->orderBy('id')->get()->getResultArray(), 'action');

        $this->assertSame(['institution.created', 'institution.suspended', 'institution.deleted', 'institution.restored'], $actions);
    }
}
