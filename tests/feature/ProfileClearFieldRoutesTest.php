<?php

declare(strict_types=1);

use App\Services\Auth\RolePermissions;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DeanFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Clearing a field and a home department through the screens and routes (Task 6.3).
 *
 * @internal
 */
final class ProfileClearFieldRoutesTest extends RouteTestCase
{
    use MessagingScenario;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        $this->buildScenario();
        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        db_connect()->table('user_profiles')->where('user_id', $this->student->id)->update(['pronouns' => 'x/y', 'bio' => 'Spam']);
        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        $this->releaseScenario();
        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        $_SESSION = [];
        Services::resetSingle('auth');
        $this->loggedInAs($user);
    }

    private function bio(): mixed
    {
        return db_connect()->table('user_profiles')->where('user_id', $this->student->id)->get()->getRow('bio');
    }

    private function body(User $viewer, string $uri): string
    {
        $this->signIn($viewer);

        return (string) $this->call('GET', $uri)->response()->getBody();
    }

    /** @return array<string, string> */
    private function json(): array
    {
        return ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
    }

    public function testTheAdminSeesTheMenuOnlyForFieldsWithAValueAndALinkInTheModal(): void
    {
        $page = $this->body($this->admin, "/people/{$this->student->id}");
        $this->assertStringContainsString('data-admin-clear', $page);
        $this->assertStringContainsString('person-clear-bio', $page);
        $this->assertStringContainsString('person-clear-pronouns', $page);
        $this->assertStringNotContainsString('person-clear-work_phone', $page);
        $this->assertStringContainsString("institution/people/{$this->student->id}/clear", $page);

        $this->assertStringContainsString('data-admin-clear-link', $this->body($this->admin, "/people/{$this->student->id}/panel"));
        $this->assertStringNotContainsString('person-clear-', $this->body($this->instructor, "/people/{$this->student->id}"));
    }

    public function testTheAdminClearsAFieldThroughTheRoute(): void
    {
        $this->signIn($this->admin);

        $this->withHeaders($this->json())->step('POST', "institution/people/{$this->student->id}/clear", ['field' => 'bio'])->assertStatus(200);

        $this->assertNull($this->bio());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'profile.field_cleared_by_admin')->countAllResults());
    }

    public function testAnAdminWithOnlyPeopleViewIsRefusedAndSeesNoMenu(): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => 'institutional_admin', 'permission' => 'people.manage', 'granted' => 0]);
        RolePermissions::flush();

        $this->assertStringNotContainsString('person-clear-', $this->body($this->admin, "/people/{$this->student->id}"));

        $this->signIn($this->admin);
        $status = $this->step('POST', "institution/people/{$this->student->id}/clear", ['field' => 'bio'])->response()->getStatusCode();

        $this->assertNotSame(200, $status);
        $this->assertSame('Spam', $this->bio());
    }

    public function testAReadOnlyInstitutionRefuses(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->signIn($this->admin);

        $this->step('POST', "institution/people/{$this->student->id}/clear", ['field' => 'bio'])->assertRedirect();

        $this->assertSame('Spam', $this->bio());
    }

    public function testARequestWithoutTheCsrfTokenIsRefused(): void
    {
        $this->signIn($this->admin);

        try {
            $status = $this->withoutCsrfToken()->step('POST', "institution/people/{$this->student->id}/clear", ['field' => 'bio'])->response()->getStatusCode();
            $this->assertNotSame(200, $status);
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('Spam', $this->bio());
    }

    public function testAnAdminOfAnotherInstitutionGetsA404(): void
    {
        $other = PeopleFactory::person('institutional_admin', 'Olga', 'Elsewhere', null, PeopleFactory::institution('PCX'));
        $this->signIn($other);

        try {
            $status = $this->step('POST', "institution/people/{$this->student->id}/clear", ['field' => 'bio'])->response()->getStatusCode();
            $this->assertSame(404, $status);
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('Spam', $this->bio());
    }

    public function testTheHomeDepartmentReplacesTeachesInOnTheProfile(): void
    {
        ['college' => $c] = DeanFactory::college('HM', $this->institution);
        $dept = DeanFactory::department($c, 'HM');
        $this->signIn($this->admin);
        $this->withHeaders($this->json())->step('POST', "institution/people/{$this->instructor->id}", [
            'first_name' => 'Ivy', 'last_name' => 'Tan', 'email' => (string) $this->instructor->email, 'home_department_id' => (string) $dept,
        ])->assertStatus(200);

        $this->assertStringContainsString('Department HM', $this->body($this->student, "/people/{$this->instructor->id}"));
        $this->assertStringNotContainsString('Teaches in', $this->body($this->student, "/people/{$this->instructor->id}"));
    }
}
