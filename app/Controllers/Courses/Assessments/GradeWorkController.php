<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\GradeWorkService;

class GradeWorkController extends BaseController
{
    protected GradeWorkService $gradeWorkService;

    public function __construct()
    {
        $this->gradeWorkService = new GradeWorkService();
    }

    public function show(int $assessmentId, ?int $studentId = null)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->gradeWorkService->gradeWork(
                $user,
                $assessmentId,
                $studentId
            )
        );
    }

    public function startGrading(int $submissionId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->gradeWorkService->startGrading(
                $user,
                $submissionId
            )
        );
    }

    public function submitGrade(int $submissionId)
    {
        $user = auth()->user();

        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->gradeWorkService->submitGrade(
                $user,
                $submissionId,
                $data
            )
        );
    }
}
