<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionBranding;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The institution settings pages (profile, branding) and what they do to the
 * shell: access, saving and refusing, the colours and logo reaching only that
 * institution's people, the vendor's area keeping the platform colours, and
 * a read-only institution. (The browser's file upload itself is in the e2e
 * case; PHP's own upload check cannot be satisfied from a feature test.)
 *
 * @internal
 */
final class InstitutionSettingsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('SETA');
        $this->other       = SisFixture::institution('SETB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@set.example', $this->institution);

        $this->signIn($this->admin);
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function row(?int $id = null): array
    {
        return db_connect()->table('institutions')->where('id', $id ?? $this->institution)->get()->getRowArray();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseIt(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@set.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/settings')->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', 'institution/settings/branding')->response()->getStatusCode());

        $this->step('POST', 'institution/settings', ['name' => 'Hijacked']);
        $this->step('POST', 'institution/settings/branding', ['primary' => '#8B1E3F', 'accent' => '#1B5E20']);

        $this->assertSame('SETA College', $this->row()['name']);
        $this->assertNull($this->row()['brand_primary']);
    }

    public function testTheProfilePageShowsTheCodeAsReadOnly(): void
    {
        $body = $this->step('GET', 'institution/settings')->response()->getBody();

        $this->assertStringContainsString('SETA College', $body);
        $this->assertStringContainsString('data-institution-code', $body);
        $this->assertStringContainsString('cannot be changed', $body);
        $this->assertStringNotContainsString('name="code"', $body);
    }

    public function testTheProfileIsSavedThroughTheForm(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', 'institution/settings', ['name' => 'Lakeside College', 'contact_email' => 'office@lakeside.test', 'contact_phone' => '+63 2 8123 4567', 'address' => '12 Shore Road']);

        $this->assertTrue($result->isOK());
        $this->assertSame('The profile was saved.', json_decode($result->getJSON(), true)['message']);
        $this->assertSame('Lakeside College', $this->row()['name']);
        $this->assertSame('SETA', $this->row()['code']);

        $this->withHeaders(['Accept' => 'text/html']);
        $this->assertStringContainsString('office@lakeside.test', $this->step('GET', 'institution/settings')->response()->getBody());
    }

    public function testABadProfileAnswers422WithTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/settings', ['name' => 'Lakeside', 'contact_email' => 'not-an-email']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('contact_email', json_decode($result->getJSON(), true)['errors']);
    }

    public function testTheBrandingPageHasThePreviewAndTheTokens(): void
    {
        $body = $this->step('GET', 'institution/settings/branding')->response()->getBody();

        $this->assertStringContainsString('data-brand-preview', $body);
        $this->assertStringContainsString('data-brand-input="primary"', $body);
        $this->assertStringContainsString('data-brand-input="accent"', $body);
        $this->assertStringContainsString('Use the Mentora colours', $body);
        $this->assertStringContainsString('No logo yet', $body);
    }

    public function testColoursAreSavedAndPutInForceForThatInstitutionsPeopleOnly(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', 'institution/settings/branding', ['primary' => '#8B1E3F', 'accent' => '#1B5E20']);
        $this->assertTrue($result->isOK());

        $this->withHeaders(['Accept' => 'text/html']);

        $mine = $this->step('GET', 'institution/dashboard')->response()->getBody();
        $this->assertStringContainsString('data-institution-branding', $mine);
        $this->assertStringContainsString('--color-brand:#8B1E3F', $mine);
        $this->assertStringContainsString('--color-accent:#1B5E20', $mine);

        // A student of the same institution sees them; one of another does not.
        $own = PeopleFactory::person('student', 'Ann', 'Own', 'own@set.example', $this->institution);
        $this->signIn($own);
        $this->assertStringContainsString('--color-brand:#8B1E3F', $this->step('GET', 'student/courses')->response()->getBody());

        $foreign = PeopleFactory::person('student', 'Zed', 'Other', 'zed@set.example', $this->other);
        $this->signIn($foreign);
        $this->assertStringNotContainsString('data-institution-branding', $this->step('GET', 'student/courses')->response()->getBody());
    }

    public function testAColourThatIsTooLightIsRefusedAndNothingChanges(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/settings/branding', ['primary' => '#7FB2FF', 'accent' => '#2E7D4F']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $errors = json_decode($result->getJSON(), true)['errors'];
        $this->assertArrayHasKey('primary', $errors);
        $this->assertStringContainsString('too light', $errors['primary']);
        $this->assertNull($this->row()['brand_primary']);
    }

    public function testResettingGoesBackToTheMentoraColours(): void
    {
        $this->step('POST', 'institution/settings/branding', ['primary' => '#8B1E3F', 'accent' => '#1B5E20']);
        $this->assertStringContainsString('Reset to the Mentora colours', $this->step('GET', 'institution/settings/branding')->response()->getBody());

        $this->step('POST', 'institution/settings/branding/reset');

        $this->assertNull($this->row()['brand_primary']);
        $this->assertStringNotContainsString('data-institution-branding', $this->step('GET', 'institution/dashboard')->response()->getBody());
    }

    public function testTheVendorsAreaKeepsThePlatformColoursWhateverAnInstitutionPicked(): void
    {
        $this->step('POST', 'institution/settings/branding', ['primary' => '#8B1E3F', 'accent' => '#1B5E20']);

        $sysadmin = PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@set.example');
        $this->signIn($sysadmin);

        $this->assertNull((new InstitutionBranding())->forUser($sysadmin));
    }

    public function testTheLogoIsShownInTheHeaderAndServedToMembersOnly(): void
    {
        $file = CourseFactory::file($this->admin, 'png-bytes');
        db_connect()->table('files')->where('id', $file['id'])->update(['status' => 'active']);
        db_connect()->table('institutions')->where('id', $this->institution)->update(['logo_file_id' => $file['id']]);

        $dashboard = $this->step('GET', 'institution/dashboard')->response()->getBody();
        $this->assertStringContainsString('data-institution-logo', $dashboard);
        $this->assertStringContainsString('files/' . $file['id'], html_entity_decode($dashboard));

        $member = PeopleFactory::person('student', 'Ann', 'Own', 'own2@set.example', $this->institution);
        $this->signIn($member);
        $this->assertSame(200, $this->step('GET', 'files/' . $file['id'])->response()->getStatusCode());

        $foreign = PeopleFactory::person('student', 'Zed', 'Other', 'zed2@set.example', $this->other);
        $this->signIn($foreign);

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->call('GET', 'files/' . $file['id']);
    }

    public function testRemovingTheLogoThroughThePage(): void
    {
        $file = CourseFactory::file($this->admin, 'png-bytes');
        db_connect()->table('institutions')->where('id', $this->institution)->update(['logo_file_id' => $file['id']]);

        $this->assertStringContainsString('data-current-logo', $this->step('GET', 'institution/settings/branding')->response()->getBody());

        $this->step('POST', 'institution/settings/logo/remove');

        $this->assertNull($this->row()['logo_file_id']);
        $this->assertSame(0, db_connect()->table('files')->where('id', $file['id'])->countAllResults());
        $this->assertStringContainsString('No logo yet', $this->step('GET', 'institution/settings/branding')->response()->getBody());
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $page = $this->step('GET', 'institution/settings/branding')->response()->getBody();
        $this->assertStringContainsString('data-lock="read_only"', $page);
        $this->assertStringNotContainsString('Save colours', $page);

        $this->step('POST', 'institution/settings/branding', ['primary' => '#8B1E3F', 'accent' => '#1B5E20']);
        $this->step('POST', 'institution/settings', ['name' => 'Hijacked']);

        $this->assertNull($this->row()['brand_primary']);
        $this->assertSame('SETA College', $this->row()['name']);
    }

    public function testTheSettingsTileLeadsHere(): void
    {
        $dashboard = html_entity_decode($this->step('GET', 'institution/dashboard')->response()->getBody());

        $this->assertSame(1, preg_match('/<a\s[^>]*href="[^"]*institution\/settings"[^>]*data-app-tile="settings"/s', $dashboard));
    }
}
