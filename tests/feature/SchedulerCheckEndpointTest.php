<?php

declare(strict_types=1);

use App\Services\Monitoring\ScheduledJobs;
use CodeIgniter\Config\Services;
use Tests\Support\FakeEmail;
use Tests\Support\RouteTestCase;

/**
 * POST internal/scheduler-check, called hourly by the uptime workflow with a shared secret (audit U2).
 *
 * @internal
 */
final class SchedulerCheckEndpointTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        FakeEmail::install();
        \Tests\Support\TestEnv::set('scheduler.checkSecret', 's3cret-for-tests');

        foreach (array_keys(ScheduledJobs::JOBS) as $command) {
            ScheduledJobs::heartbeat($command, time() - 60);
        }

        $this->withoutCsrfToken();
    }

    protected function tearDown(): void
    {
        \Tests\Support\TestEnv::restore();
        Services::resetSingle('settings');

        parent::tearDown();
    }

    public function testWithoutTheSecretConfiguredItAnswers503(): void
    {
        \Tests\Support\TestEnv::unset('scheduler.checkSecret');

        $response = $this->withHeaders(['X-Scheduler-Secret' => 'anything'])->call('POST', 'internal/scheduler-check');

        $this->assertSame(503, $response->response()->getStatusCode());
        $this->assertStringContainsString('The scheduler check is not configured.', $response->response()->getBody());
    }

    public function testAWrongSecretIs401(): void
    {
        $response = $this->withHeaders(['X-Scheduler-Secret' => 'wrong'])->call('POST', 'internal/scheduler-check');

        $this->assertSame(401, $response->response()->getStatusCode());
    }

    public function testTheRightSecretRunsTheCheckWithoutACsrfToken(): void
    {
        ScheduledJobs::heartbeat('backup:run', time() - 3600);

        $response = $this->withHeaders(['X-Scheduler-Secret' => 's3cret-for-tests'])->call('POST', 'internal/scheduler-check');

        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertSame(['backup:run'], json_decode($response->response()->getBody(), true)['stale']);
    }
}
