<?php

namespace App\Controllers\Courses;

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

    public function index(int $learningItemId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->assessmentService->getAssessmentsByLearningItem(
                $user,
                $learningItemId
            )
        );
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

    public function create(int $learningItemId)
    {
        $user = auth()->user();

        $data = $this->request->getPost();

        return $this->response->setJSON(
            $this->assessmentService->createAssessment(
                $user,
                $learningItemId,
                $data
            )
        );
    }

    public function update(int $assessmentId)
    {
        $user = auth()->user();

        $data = $this->request->getRawInput();

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