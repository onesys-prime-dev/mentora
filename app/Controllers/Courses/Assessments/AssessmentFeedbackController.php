<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\AssessmentFeedbackService;

/**
 * Posting to a submission's feedback thread, outside of grading — reachable
 * by both roles; AssessmentFeedbackService decides what each is allowed to do.
 */
class AssessmentFeedbackController extends BaseController
{
    protected AssessmentFeedbackService $feedbackService;

    public function __construct()
    {
        $this->feedbackService = new AssessmentFeedbackService();
    }

    public function store(int $submissionId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->feedbackService->postMessage(auth()->user(), $submissionId, (string) ($data['message'] ?? ''))
        );
    }
}
