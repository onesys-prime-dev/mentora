<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Monitoring\SystemHealth;
use App\Services\Monitoring\UsageReport;
use CodeIgniter\CodeIgniter;
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
            'monRuntime'   => ['php' => PHP_VERSION, 'framework' => CodeIgniter::CI_VERSION, 'environment' => ENVIRONMENT, 'checkedAt' => $now->format('Y-m-d H:i:s')],
        ]);
    }
}
