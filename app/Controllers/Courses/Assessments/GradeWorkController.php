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
        return $this->answer($this->gradeWorkService->reopenGrading(auth()->user(), $submissionId));
    }

    public function startGrading(int $submissionId)
    {
        $user = auth()->user();

        return $this->answer(
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

        return $this->answer(
            $this->gradeWorkService->submitGrade(
                $user,
                $submissionId,
                $data
            )
        );
    }

    public function gradeQuestion(int $submissionId, int $questionId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->answer(
            $this->gradeWorkService->gradeQuestion(
                auth()->user(),
                $submissionId,
                $questionId,
                $data
            )
        );
    }

    /** A group's submission is refused with 422 (graded from the group view); every other answer is as before. */
    private function answer(array $result)
    {
        return $this->response->setStatusCode(($result['code'] ?? null) === 'group_submission' ? 422 : 200)->setJSON($result);
    }
}
