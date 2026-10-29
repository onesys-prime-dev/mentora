<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Monitoring\SystemHealth;
use App\Services\Monitoring\UsageReport;
use CodeIgniter\CodeIgniter;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

/**
 * System status for administrators (SA9): read-only, refreshes itself. The
 * public /health endpoint is separate and unchanged.
 */
class MonitoringController extends BaseController
{
    public function index(): string
    {
        $now    = new DateTimeImmutable();
        $health = (new SystemHealth())->run();
        $usage  = new UsageReport();

        return view('pages/admin/monitoring', [
            'title'        => 'System status',
            'page'         => 'admin',
            'user'         => auth()->user(),
            'monHealth'    => $health,
            'monOverall'   => SystemHealth::overall($health),
            'monPlatform'  => $usage->platform($now),
            'monWatch'     => $usage->subscriptionWatch($now),
            'monUsage'     => $usage->institutions($now),
            'monLiveUrl'   => site_url('admin/monitoring/live'),
            'monRuntime'   => ['php' => PHP_VERSION, 'framework' => CodeIgniter::CI_VERSION, 'environment' => ENVIRONMENT, 'checkedAt' => $now->format('Y-m-d H:i:s')],
        ]);
    }

    /**
     * The numbers the status page's graphs draw, read fresh on each call. The
     * AI check is left out: it can reach over the network and the page asks
     * every few seconds. Nothing is stored, the page keeps the recent readings.
     */
    public function live(): ResponseInterface
    {
        $now    = new DateTimeImmutable();
        $start  = microtime(true);
        db_connect()->query('SELECT 1');
        $ms     = (int) round((microtime(true) - $start) * 1000);

        $health = (new SystemHealth(['ai' => static fn (): array => ['status' => SystemHealth::UP, 'detail' => '']]))->run();
        unset($health['ai']);

        $free  = @disk_free_space(WRITEPATH);
        $total = @disk_total_space(WRITEPATH);
        $platform = (new UsageReport())->platform($now);

        return $this->response->setJSON([
            'at'         => $now->getTimestamp(),
            'checks'     => $health,
            'databaseMs' => $ms,
            'diskUsed'   => $free === false || ! $total ? null : round((1 - $free / $total) * 100, 1),
            'sessions'   => $platform['sessions'],
            'failed24h'  => $platform['failedLogins24h'],
            'failed7d'   => $platform['failedLogins7d'],
            'signedInToday' => $platform['signedInToday'],
        ]);
    }
}
