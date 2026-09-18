<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\AssessmentService;
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
            $this->assessmentService->getAssessmentIndex(
                $user,
                $offeringId
            )
        );
    }

    public function viewAssessment(int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->viewAssessment(
                $user,
                $assessmentId
            )
        );
    }

    public function editAssessment(int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->editAssessment(
                $user,
                $assessmentId
            )
        );
    }

    public function quizBuilder(int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->quizBuilder(
                $user,
                $assessmentId
            )
        );
    }

    public function updateInstructions(int $assessmentId)
    {
        $user = auth()->user();

        $payload = $this->request->getJSON(true);

        return $this->response->setJSON(
            $this->assessmentService->updateAssessmentInstructions(
                $user,
                $assessmentId,
                $payload['instructions'] ?? []
            )
        );
    }

    public function updateSubmissionSettings(int $assessmentId)
    {
        $user = auth()->user();

        $payload = $this->request->getJSON(true);

        return $this->response->setJSON(
            $this->assessmentService->updateSubmissionSettings(
                $user,
                $assessmentId,
                $payload
            )
        );
    }

    public function create(int $offeringId, int $moduleId, int $learningUnitId)
    {
        $user = auth()->user();

        $data = $this->request->getPost();

        return $this->response->setJSON(
            $this->assessmentService->createAssessment(
                $user,
                $moduleId,
                $learningUnitId,
                $data
            )
        );
    }

    public function update(int $assessmentId)
    {
        $user = auth()->user();

        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->assessmentService->updateAssessment(
                $user,
                $assessmentId,
                $data
            )
        );
    }

    public function publish(int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->publishedAssessment(
                $user,
                $assessmentId
            )
        );        
    }

    public function unpublish(int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->unpublishedAssessment(
                $user,
                $assessmentId
            )
        );
    }

    public function destroy(int $assessmentId)
    {
        return $this->response->setJSON(
            $this->assessmentService->deleteAssessment(
                $assessmentId
            )
        );
    }

    public function archive(int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->archiveAssessment(
                $user,
                $assessmentId
            )
        );
    }

    public function restore(int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->restoreAssessment(
                $user,
                $assessmentId
            )
        );
    }   

    public function duplicate(int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->duplicateAssessment(
                $user,
                $assessmentId
            )
        );
    }

    public function statistics(int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->getAssessmentStatistics(
                $user,
                $assessmentId
            )
        );
    }
}