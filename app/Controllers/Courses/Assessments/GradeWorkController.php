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

    /**
     * Grade Work for one section: only students enrolled in $offeringId are
     * listed, matching the Assessments tab's To Grade numbers.
     */
    public function show(int $offeringId, int $assessmentId, ?int $studentId = null)
    {
        $user = auth()->user();

        // ?attempt= picks one of the student's attempts (tabs in Grade Work).
        $attemptId = $this->request->getGet('attempt');

        return $this->response->setJSON(
            $this->gradeWorkService->gradeWork(
                $user,
                $offeringId,
                $assessmentId,
                $studentId,
                ctype_digit((string) $attemptId) ? (int) $attemptId : null
            )
        );
    }

    public function reopenGrading(int $submissionId)
    {
        return $this->response->setJSON(
            $this->gradeWorkService->reopenGrading(auth()->user(), $submissionId)
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
