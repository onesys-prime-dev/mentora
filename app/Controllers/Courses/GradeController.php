<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\GradeService;
use CodeIgniter\Exceptions\PageNotFoundException;

class GradeController extends BaseController
{
    protected GradeService $gradeService;

    public function __construct()
    {
        $this->gradeService = new GradeService();
    }

    public function show(int $offeringId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->gradeService->show(
                $user,
                $offeringId
            )
        );
    }
}