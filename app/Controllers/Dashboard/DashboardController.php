<?php

namespace App\Controllers\Dashboard;

use App\Controllers\BaseController;
use App\Services\Dashboard\DashboardService;

class DashboardController extends BaseController
{
    protected DashboardService $dashboardService;

    public function __construct()
    {
        $this->dashboardService = new DashboardService();
    }

    public function index(): string
    {
        $user = auth()->user();

        return view('pages/dashboard/index', [
            'title'          => 'Dashboard',
            'page'           => 'dashboard',
            'user'           => $user,
            'widgets'        => $this->dashboardService->getDashboardWidgets($user),
            'academic_term'  => $this->dashboardService->getAcademicTerm($user),

            // Pin the dashboard to the viewport; cards scroll internally.
            'fullHeight'     => true,
        ]);
    }
}