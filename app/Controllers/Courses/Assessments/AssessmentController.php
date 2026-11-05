<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Models\Assessments\AssessmentModel;
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

    public function viewAssessment(int $offeringId, int $assessmentId)
    {
        if (! (new AssessmentModel())->belongsToOffering($assessmentId, $offeringId)) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }

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

    /** Sets the quiz's password (JSON {password}). The answer says whether it was saved, never what it is. */
    public function setPassword(int $assessmentId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->assessmentService->setQuizPassword(
                auth()->user(),
                $assessmentId,
                is_string($data['password'] ?? null) ? $data['password'] : ''
            )
        );
    }

    /** The students of the assessment's section and who it is given to (specific students). */
    public function assignees(int $assessmentId)
    {
        return $this->response->setJSON([
            'success'  => true,
            'students' => $this->assessmentService->assignmentRoster($assessmentId),
        ]);
    }

    /** Saves who the assessment is given to (JSON {student_ids: []}). */
    public function setAssignees(int $assessmentId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->assessmentService->setAssignees(
                auth()->user(),
                $assessmentId,
                is_array($data['student_ids'] ?? null) ? $data['student_ids'] : []
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