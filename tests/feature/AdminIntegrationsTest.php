<?php

declare(strict_types=1);

use App\Services\Flags\FeatureFlagAdmin;
use App\Services\Sis\CredentialVault;
use App\Services\Sis\SisConnectors;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * An institution's Integrations page: who may see and change it, that saved
 * credentials never come back in any response, and the test and history.
 *
 * @internal
 */
final class AdminIntegrationsTest extends RouteTestCase
{
    private const SECRET = 'correct-horse-battery-staple-signing';

    private User $admin;

    private int $institution;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        Services::injectMock('credentialVault', new CredentialVault('feature-test-encryption-key-0123456789'));

        $this->folder     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-sis-' . bin2hex(random_bytes(4));
        \Tests\Support\TestEnv::set('sis.path', $this->folder);

        $this->admin       = model(UserModel::class)->findById(CourseFactory::user('admin')->id);
        $this->institution = PeopleFactory::institution('SISFC', 'active', 'Sis College');

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*') ?: [] as $dir) {
            @rmdir($dir);
        }

        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $dir) {
            @rmdir($dir);
        }

        @rmdir($this->folder);
        \Tests\Support\TestEnv::restore();

        Services::resetSingle('credentialVault');
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

    private function turnOnTheFlag(): void
    {
        (new FeatureFlagAdmin())->setForInstitution($this->institution, 'sis.sync', 'on', 'Pilot', null, $this->admin);
    }

    private function actions(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanSeeOrChangeIt(string $role): void
    {
        $user = PeopleFactory::person($role, 'Some', 'One', "{$role}@x.example", $this->institution);

        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();

        $this->assertSame(302, $this->step('GET', $this->url())->response()->getStatusCode());
        $this->step('POST', $this->url(), ['enabled' => '1', 'signing_key' => self::SECRET]);

        $this->assertSame(0, db_connect()->table('sis_connections')->countAllResults());
    }

    public function testThePageShowsTheConnectionTheFoldersAndAnEmptyHistory(): void
    {
        $body = $this->step('GET', $this->url())->response()->getBody();

        $this->assertStringContainsString('Sis College', $body);
        $this->assertStringContainsString('data-sis-flag="off"', $body);
        $this->assertStringContainsString('data-credentials="none"', $body);
        $this->assertStringContainsString('data-runs-empty', $body);
        $this->assertStringContainsString(str_replace('\\', '\\', SisConnectors::folders($this->institution)['inbound']), html_entity_decode($body));
    }

    public function testAnUnknownInstitutionIsNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->call('GET', 'admin/institutions/999999/integrations');
    }

    public function testSavingNeedsThePasswordAgain(): void
    {
        $response = $this->step('POST', $this->url(), ['signing_key' => self::SECRET]);

        $this->assertStringContainsString('admin/reauth', $response->response()->getHeaderLine('Location'));
        $this->assertSame(0, db_connect()->table('sis_connections')->countAllResults());
    }

    public function testSavedCredentialsNeverAppearInAnyResponseOrTheDatabase(): void
    {
        $this->turnOnTheFlag();
        $this->confirmedPassword();

        $saved = $this->step('POST', $this->url(), ['enabled' => '1', 'signing_key' => self::SECRET]);
        $this->assertSame(site_url($this->url()), $saved->response()->getHeaderLine('Location'));
        $this->assertStringNotContainsString(self::SECRET, $saved->response()->getBody());

        $page = $this->step('GET', $this->url())->response()->getBody();
        $this->assertStringNotContainsString(self::SECRET, $page);
        $this->assertStringContainsString('data-credentials="set"', $page);
        $this->assertStringContainsString('Connection saved.', $page);
        $this->assertStringContainsString('checked', $page, 'the enabled box is ticked');

        $row = db_connect()->table('sis_connections')->where('institution_id', $this->institution)->get()->getRowArray();
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertStringNotContainsString(self::SECRET, (string) $row['credentials']);

        $this->assertStringNotContainsString(self::SECRET, json_encode(db_connect()->table('audit_logs')->get()->getResultArray()));
        $this->assertSame(1, $this->actions('sis.connection_saved'));
    }

    public function testItCannotBeEnabledWhileTheFeatureIsOffAndTheReasonIsShown(): void
    {
        $this->confirmedPassword();

        $this->step('POST', $this->url(), ['enabled' => '1']);
        $page = $this->step('GET', $this->url())->response()->getBody();

        $this->assertStringContainsString('sis.sync', $page);
        $this->assertSame(0, db_connect()->table('sis_connections')->countAllResults());
    }

    public function testAShortKeyIsRefusedWithAMessage(): void
    {
        $this->confirmedPassword();

        $this->step('POST', $this->url(), ['signing_key' => 'short']);
        $page = $this->step('GET', $this->url())->response()->getBody();

        $this->assertStringContainsString('16 to 255 characters', $page);
    }

    public function testRemovingTheSavedCredentials(): void
    {
        $this->confirmedPassword();
        $this->step('POST', $this->url(), ['signing_key' => self::SECRET]);
        $this->step('POST', $this->url(), ['clear_credentials' => '1']);

        $row = db_connect()->table('sis_connections')->where('institution_id', $this->institution)->get()->getRowArray();
        $this->assertNull($row['credentials']);
    }

    public function testTestingTheConnectionShowsTheOutcomeAndTheRun(): void
    {
        $inbound = SisConnectors::folders($this->institution)['inbound'];
        mkdir($inbound, 0770, true);
        file_put_contents($inbound . DIRECTORY_SEPARATOR . 'students.csv', "external_id,email,first_name,last_name\nS1,a@example.com,Ann,Lee\n");

        $this->step('POST', $this->url('/test'));
        $page = $this->step('GET', $this->url())->response()->getBody();

        $this->assertStringContainsString('Connection works.', $page);
        $this->assertStringContainsString('data-run="succeeded"', $page);
        $this->assertSame(1, $this->actions('sis.connection_tested'));
    }

    public function testAFailedTestIsShownAsAnError(): void
    {
        $this->step('POST', $this->url('/test'));
        $page = $this->step('GET', $this->url())->response()->getBody();

        $this->assertStringContainsString('Connection test failed.', $page);
        $this->assertStringContainsString('data-run="failed"', $page);
    }

    public function testAMissingEncryptionKeyIsExplainedNotCrashed(): void
    {
        $config      = config(\Config\Encryption::class);
        $original    = $config->key;
        $config->key = '';
        Services::injectMock('credentialVault', new CredentialVault());
        $this->confirmedPassword();

        try {
            $this->step('POST', $this->url(), ['signing_key' => self::SECRET]);
            $page = $this->step('GET', $this->url())->response()->getBody();
        } finally {
            $config->key = $original;
        }

        $this->assertStringContainsString('key:generate', $page);
        $this->assertSame(0, db_connect()->table('sis_connections')->countAllResults());
    }
}
