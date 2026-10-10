<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The import from an administrator's side: preview and import buttons, the
 * password for a real import, the issues list, and the lock on people the SIS
 * supplied (refused on the server, shown read-only on the page).
 *
 * @internal
 */
final class AdminSisImportTest extends RouteTestCase
{
    private User $admin;

    private int $institution;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->folder      = SisFixture::useFolder();
        $this->admin       = model(UserModel::class)->findById(CourseFactory::user('admin')->id);
        $this->institution = SisFixture::institution('FEATSIS');

        SisFixture::connect($this->institution, $this->admin);
        SisFixture::dropAll($this->institution);

        $this->loggedInAs($this->admin);
        $this->withSession();
    }

    protected function tearDown(): void
    {
        SisFixture::cleanup($this->folder);

        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function url(string $suffix = ''): string
    {
        return "admin/institutions/{$this->institution}/integrations{$suffix}";
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    private function page(): string
    {
        return $this->step('GET', $this->url())->response()->getBody();
    }

    public function testAPreviewShowsTheCountsAndTheRowsThatWouldBeRefused(): void
    {
        $this->step('POST', $this->url('/preview'));
        $page = $this->page();

        $this->assertStringContainsString('Preview done:', $page);
        $this->assertStringContainsString('created 6', $page);
        $this->assertStringContainsString('data-issues', $page);
        $this->assertStringContainsString('email is not a valid address', $page);
        $this->assertStringContainsString('program_code is required', $page);
        // That a preview writes nothing is proved in SisImporterRollbackTest (it needs a real outer transaction).
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'sis.import_previewed')->countAllResults());
    }

    public function testARealImportNeedsThePasswordAndAPreviewFirst(): void
    {
        $noPassword = $this->step('POST', $this->url('/run'));
        $this->assertStringContainsString('admin/reauth', $noPassword->response()->getHeaderLine('Location'));

        $this->confirmedPassword();
        $this->step('POST', $this->url('/run'));
        $this->assertStringContainsString('Run a preview', $this->page());
        $this->assertSame(0, db_connect()->table('sis_links')->countAllResults());
    }

    public function testARealImportAfterAPreviewWritesAndShowsTheResult(): void
    {
        $this->step('POST', $this->url('/preview'));
        $this->confirmedPassword();

        $this->step('POST', $this->url('/run'));
        $page = $this->page();

        $this->assertStringContainsString('Import done:', $page);
        $this->assertStringContainsString('data-run="succeeded"', $page);
        $this->assertSame(4, db_connect()->table('sis_links')->where('institution_id', $this->institution)->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'sis.import_run')->countAllResults());
    }

    public function testAFailedImportIsShownAsAnError(): void
    {
        SisFixture::drop($this->institution, 'students', "external_id,first_name\nS1,Ann\n");

        $this->step('POST', $this->url('/preview'));
        $page = $this->page();

        $this->assertStringContainsString('The import failed.', $page);
        $this->assertStringContainsString('missing the column', $page);
    }

    public function testAnImportIsRefusedWithAReasonWhenTheFeatureIsOff(): void
    {
        db_connect()->table('feature_flag_overrides')->where('institution_id', $this->institution)->delete();

        $this->step('POST', $this->url('/preview'));

        $this->assertStringContainsString('feature is off', $this->page());
    }

    public function testTheRunButtonIsDisabledUntilTheConnectionIsEnabled(): void
    {
        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->update(['enabled' => 0]);

        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*data-sis-run/', $this->page());
    }

    public function testAnUnknownInstitutionIsNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->call('POST', 'admin/institutions/999999/integrations/preview');
    }

    public function testASupplierPersonCannotBeEditedHereAndThePageSaysWhy(): void
    {
        $this->step('POST', $this->url('/preview'));
        $this->confirmedPassword();
        $this->step('POST', $this->url('/run'));

        $ann = (int) db_connect()->table('auth_identities')->where('secret', 'ann@example.com')->get()->getRowArray()['user_id'];

        $page = $this->step('GET', "admin/users/{$ann}")->response()->getBody();
        $this->assertStringContainsString('data-sis-managed', $page);
        $this->assertMatchesRegularExpression('/name="first_name"[^>]*readonly/', $page);

        $this->confirmedPassword();
        $this->step('POST', "admin/users/{$ann}", ['first_name' => 'Hacked', 'last_name' => 'Lee', 'email' => 'ann@example.com']);

        $this->assertSame('Ann', db_connect()->table('user_profiles')->where('user_id', $ann)->get()->getRowArray()['first_name']);
        $this->assertStringContainsString('student information system', $this->step('GET', "admin/users/{$ann}")->response()->getBody());
    }

    public function testSomeoneLocalIsStillEditable(): void
    {
        $local = PeopleFactory::person('student', 'Local', 'Person', 'local@example.com', $this->institution);

        $page = $this->step('GET', 'admin/users/' . $local->id)->response()->getBody();

        $this->assertStringNotContainsString('data-sis-managed', $page);
        $this->assertDoesNotMatchRegularExpression('/name="first_name"[^>]*readonly/', $page);
    }
}
