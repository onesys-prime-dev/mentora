<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\FakeBackupRunner;
use Tests\Support\RouteTestCase;

/**
 * The backups page: administrators only, history and alert, asking for a
 * backup (needs the password, is audited, only one at a time), the server
 * command that takes it, and the system status page's backups check.
 *
 * @internal
 */
final class AdminBackupPageTest extends RouteTestCase
{
    private User $admin;

    private FakeBackupRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->runner = FakeBackupRunner::install();
        $this->admin  = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        $this->runner->remove();
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    private function body(string $uri = 'admin/backups'): string
    {
        return $this->step('GET', $uri)->response()->getBody();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanSeeOrRequestBackups(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        $this->assertSame(302, $this->step('GET', 'admin/backups')->response()->getStatusCode());
        $this->step('POST', 'admin/backups');

        $this->assertSame(0, db_connect()->table('backups')->countAllResults());
    }

    public function testAnEmptyHistoryShowsTheNoBackupAlert(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('No backups recorded yet', $body);
        $this->assertStringContainsString('data-backup-alert="none"', $body);
    }

    public function testAskingNeedsThePasswordAgain(): void
    {
        $response = $this->step('POST', 'admin/backups');

        $this->assertStringContainsString('admin/reauth', $response->response()->getHeaderLine('Location'));
        $this->assertSame(0, db_connect()->table('backups')->countAllResults());
    }

    public function testAskingRecordsARequestAndTheButtonIsOffWhileItWaits(): void
    {
        $this->confirmedPassword();

        $this->step('POST', 'admin/backups');

        $row = db_connect()->table('backups')->get()->getRowArray();

        $this->assertSame('requested', $row['status']);
        $this->assertSame((int) $this->admin->id, (int) $row['requested_by_user_id']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'backup.requested')->countAllResults());
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>Back up now<\/button>/', $this->body());
        $this->assertSame(0, $this->runner->calls);
    }

    public function testASecondRequestIsRefusedWithAMessage(): void
    {
        $this->confirmedPassword();
        $this->step('POST', 'admin/backups');
        $this->step('POST', 'admin/backups');

        $this->assertSame(1, db_connect()->table('backups')->countAllResults());
        $this->assertStringContainsString('already waiting', $this->body());
    }

    public function testTheServerCommandTakesItAndThePageShowsTheResult(): void
    {
        $this->confirmedPassword();
        $this->step('POST', 'admin/backups');

        command('backup:run');

        $body = $this->body();

        $this->assertMatchesRegularExpression('/data-backup="succeeded"/', $body);
        $this->assertStringContainsString('.sql.gz', $body);
        $this->assertStringNotContainsString('data-backup-alert', $body);
        $this->assertSame(1, $this->runner->calls);
    }

    public function testAFailedBackupShowsTheReasonAndTheAlert(): void
    {
        $this->runner->fail = true;
        $this->confirmedPassword();
        $this->step('POST', 'admin/backups');

        command('backup:run');

        $body = $this->body();

        $this->assertStringContainsString('data-backup-alert="failed"', $body);
        $this->assertStringContainsString('access denied', $body);
    }

    public function testTheSystemStatusPageHasABackupsCheck(): void
    {
        $this->assertMatchesRegularExpression('/data-check="backups" data-status="degraded"/', $this->body('admin/monitoring'));

        command('backup:run');

        $this->assertMatchesRegularExpression('/data-check="backups" data-status="up"/', $this->body('admin/monitoring'));
    }
}
