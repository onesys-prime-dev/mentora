<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\AssessmentService;
use CodeIgniter\Exceptions\PageNotFoundException;

class AssessmentController extends BaseController
{
    protected AssessmentService $assessmentService;

    public function __construct()
    {
        $this->assessmentService = new AssessmentService();
    }

    public function show(int $offeringId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->show(
                $user,
                $offeringId
            )
        );
    }
}