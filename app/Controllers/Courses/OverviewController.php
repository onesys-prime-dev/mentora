<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\OverviewService;
use CodeIgniter\Exceptions\PageNotFoundException;

class OverviewController extends BaseController
{
    protected OverviewService $overviewService;

    public function __construct()
    {
        $this->overviewService = new OverviewService();
    }

    public function show(int $offeringId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->overviewService->show(
                $user,
                $offeringId
            )
        );
    }
}