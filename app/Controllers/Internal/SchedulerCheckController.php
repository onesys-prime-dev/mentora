<?php

declare(strict_types=1);

namespace App\Controllers\Internal;

use App\Controllers\BaseController;
use App\Services\Monitoring\SchedulerCheck;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The hourly scheduler check, called by the uptime workflow (audit U2). Guarded by SchedulerCheckFilter.
 */
class SchedulerCheckController extends BaseController
{
    public function run(): ResponseInterface
    {
        return $this->response->setJSON((new SchedulerCheck())->run(time()));
    }
}
