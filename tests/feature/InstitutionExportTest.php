<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionExport;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The institutional administrator's data export page: only for the
 * administrator of that institution, only once it is read-only or past,
 * and every export and download is logged.
 *
 * @internal
 */
final class InstitutionExportTest extends RouteTestCase
{
    private string $folder;

    private int $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->folder        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-export-' . bin2hex(random_bytes(4));
        \Tests\Support\TestEnv::set('export.path', $this->folder);

        $this->institution = PeopleFactory::institution('LEAVER', 'read_only', 'Leaver College');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@leaver.example', $this->institution);

        db_connect()->table('audit_logs')->emptyTable();
        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);
        \Tests\Support\TestEnv::restore();

        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function actions(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideIneligibleStatuses(): iterable
    {
        yield 'active' => ['active'];
        yield 'pending' => ['pending'];
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanReachIt(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@x.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/export')->response()->getStatusCode());
        $this->step('POST', 'institution/export');

        $this->assertSame(0, db_connect()->table('institution_exports')->countAllResults());
    }

    public function testALoggedOutVisitorIsSentToTheLoginPage(): void
    {
        auth()->logout();
        $this->withSession([]);

        $response = $this->call('GET', 'institution/export')->response();

        $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));
    }

    public function testTheAdministratorLandsOnTheirHomeAfterSigningIn(): void
    {
        $response = $this->step('GET', '/')->response();

        $this->assertStringContainsString('institution/dashboard', $response->getHeaderLine('Location'));
    }

    public function testTheAdministratorOfAReadOnlyInstitutionCanCreateAndDownloadAnExport(): void
    {
        $page = $this->step('GET', 'institution/export');
        $page->assertStatus(200);
        $page->assertSee('Create an export');

        $this->step('POST', 'institution/export');

        // Prepared in the background (12.2): first it is being prepared, and no second one can be asked for.
        $preparing = $this->step('GET', 'institution/export');
        $preparing->assertSee('Being prepared');
        $preparing->assertDontSee('Create an export');

        (new \App\Services\Institutions\InstitutionExport())->runQueued();

        $row = db_connect()->table('institution_exports')->where('institution_id', $this->institution)->get()->getRowArray();
        $this->assertSame('ready', $row['status']);
        $this->assertSame(1, $this->actions('institution.exported'));

        $this->step('GET', 'institution/export')->assertSee('Download');

        $download = $this->step('GET', 'institution/export/' . $row['id']);
        $download->assertStatus(200);

        $file = $download->response();
        $this->assertInstanceOf(DownloadResponse::class, $file);
        $file->buildHeaders();
        $this->assertStringContainsString($row['file_name'], $file->getHeaderLine('Content-Disposition'));
        $this->assertGreaterThan(0, $file->getContentLength());
        $this->assertSame(1, $this->actions('institution.export_downloaded'));
    }

    public function testAPastInstitutionCanAlsoExport(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);

        $this->step('POST', 'institution/export');

        $this->assertSame(1, db_connect()->table('institution_exports')->countAllResults());
    }

    #[DataProvider('provideIneligibleStatuses')]
    public function testAnInstitutionThatIsStillRunningCannotExport(string $status): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => $status]);

        $this->step('GET', 'institution/export')->assertSee('available once the subscription has ended');
        $this->step('POST', 'institution/export');

        $this->assertSame(0, db_connect()->table('institution_exports')->countAllResults());
        $this->assertSame(0, $this->actions('institution.exported'));
    }

    public function testAnAdministratorCannotDownloadAnotherInstitutionsExport(): void
    {
        $this->step('POST', 'institution/export');
        $mine = (int) db_connect()->table('institution_exports')->get()->getRowArray()['id'];

        $otherInstitution = PeopleFactory::institution('OTHER', 'read_only');
        $this->signIn(PeopleFactory::person('institutional_admin', 'Olga', 'Other', 'olga@other.example', $otherInstitution));

        $response = $this->step('GET', "institution/export/{$mine}")->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->actions('institution.export_downloaded'));
        $this->assertSame([], (new InstitutionExport())->history($otherInstitution));
    }

    public function testAnExpiredExportCannotBeDownloaded(): void
    {
        $this->step('POST', 'institution/export');
        $id = (int) db_connect()->table('institution_exports')->get()->getRowArray()['id'];
        db_connect()->table('institution_exports')->where('id', $id)->update(['expires_at' => '2020-01-01 00:00:00']);

        $this->step('GET', "institution/export/{$id}");

        $this->assertSame(0, $this->actions('institution.export_downloaded'));
    }

    public function testAnAdministratorWithoutAnInstitutionIsSentAway(): void
    {
        $this->signIn(PeopleFactory::person('institutional_admin', 'No', 'Home', 'nohome@x.example'));

        $this->assertSame(302, $this->step('GET', 'institution/export')->response()->getStatusCode());
        $this->assertSame(302, $this->step('POST', 'institution/export')->response()->getStatusCode());
        $this->assertSame(0, db_connect()->table('institution_exports')->countAllResults());
    }

    public function testAFailureToMakeTheFileIsShownAndAnotherCanBeAskedFor(): void
    {
        $this->step('POST', 'institution/export');

        // A file where the folder should be, so the job cannot make the folder.
        file_put_contents($this->folder, 'x');

        try {
            (new \App\Services\Institutions\InstitutionExport())->runQueued();
        } finally {
            unlink($this->folder);
        }

        $page = $this->step('GET', 'institution/export');
        $this->assertStringContainsString('data-export="failed"', (string) $page->response()->getBody());
        $page->assertSee('The export folder could not be created.'); // why it failed, under its badge
        $page->assertSee('Create an export');
    }

    public function testEveryAdministratorSeesThatOneIsBeingPrepared(): void
    {
        $other = \Tests\Support\PeopleFactory::person('institutional_admin', 'Ben', 'Admin', 'ben@export.test', $this->institution);
        (new \App\Services\Institutions\InstitutionExport())->request($this->institution, $other);

        $page = $this->step('GET', 'institution/export');

        $page->assertSee('An export is being prepared.');
        $page->assertSee('ben@export.test');
        $page->assertDontSee('Create an export');
    }
}
