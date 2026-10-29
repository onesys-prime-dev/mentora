<?php

declare(strict_types=1);

use App\Services\Monitoring\AdminHome;
use App\Services\Monitoring\SystemHealth;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The strip of platform checks at the top of the administrator's home page: a
 * cell per check, the question-builder service left to the status page, and
 * each check run once however many parts of the page read it.
 *
 * @internal
 */
final class AdminHomeHealthStripTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->loggedInAs(model(UserModel::class)->findById(CourseFactory::user('admin')->id));
        $this->withSession();

        db_connect()->table('backups')->insert(['status' => 'succeeded', 'trigger' => 'scheduled', 'created_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s')]);
        setting()->set('Monitoring.subscriptionSyncAt', time());
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('adminHome');

        parent::tearDown();
    }

    /** @param array<string, callable(): array{status: string, detail: string}> $overrides */
    private function homeWith(array $overrides = [], int &$runs = 0): string
    {
        $up = static function () use (&$runs): array {
            $runs++;

            return ['status' => SystemHealth::UP, 'detail' => 'fine'];
        };

        $probes = ['database' => $up, 'storage' => $up, 'disk' => $up, 'ai' => $up, 'jobs' => $up, 'backups' => $up];

        Services::injectMock('adminHome', new AdminHome(new SystemHealth($overrides + $probes)));

        return $this->step('GET', 'admin/dashboard')->response()->getBody();
    }

    public function testTheStripHasACellPerCheckExceptTheQuestionBuilder(): void
    {
        $body = $this->homeWith();

        foreach (['database', 'storage', 'disk', 'jobs', 'backups'] as $key) {
            $this->assertStringContainsString('data-health-cell="' . $key . '"', $body, $key);
        }

        $this->assertStringNotContainsString('data-health-cell="ai"', $body);
        $this->assertStringContainsString('Normal', $body);
    }

    public function testAFailingCheckIsWordedAndListedAsSomethingToFix(): void
    {
        $body = $this->homeWith(['jobs' => static fn (): array => ['status' => SystemHealth::DEGRADED, 'detail' => 'The daily job is late.']]);

        $this->assertMatchesRegularExpression('/data-health-cell="jobs".*?Needs a look/s', $body);
        $this->assertStringContainsString('data-attention="warning"', $body);
        $this->assertStringContainsString('1 to fix', $body);
        $this->assertStringContainsString('href="' . site_url('admin/monitoring') . '"', $body);
    }

    public function testEachCheckRunsOncePerPage(): void
    {
        $runs = 0;

        $this->homeWith([], $runs);

        // Six checks, each probed once although both the strip and the list of things to fix read them.
        $this->assertSame(6, $runs);
    }
}
