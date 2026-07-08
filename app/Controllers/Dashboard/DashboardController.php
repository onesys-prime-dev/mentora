<?php

namespace App\Controllers\Dashboard;

use App\Controllers\BaseController;
use App\Services\Dashboard\DashboardService;

class DashboardController extends BaseController
{
    protected DashboardService $dashboardService;

    public function __construct() {
        $this->dashboardService = new DashboardService();
    }

    public function index(): string
    {
        return view(
            'pages/dashboard/index',
            $this->dashboardService->getDashboardData(auth()->user())
        );
    }
}
