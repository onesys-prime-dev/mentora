<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\ModuleService;
use CodeIgniter\Exceptions\PageNotFoundException;

class ModuleController extends BaseController
{
    protected ModuleService $moduleService;

    public function __construct()
    {
        $this->moduleService = new ModuleService();
    }

    public function show(int $offeringId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->moduleService->show(
                $user,
                $offeringId
            )
        );
    }

    public function viewLearningItem(
        int $offeringId,
        int $learningItemId
    ) {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->moduleService->viewLearningItem(
                $user,
                $offeringId,
                $learningItemId
            )
        );
    }
}