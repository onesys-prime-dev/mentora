<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\QuizAttemptService;

class QuizAttemptController extends BaseController
{
    protected QuizAttemptService $quizAttemptService;

    public function __construct()
    {
        $this->quizAttemptService = new QuizAttemptService();
    }

    public function start(int $offeringId, int $assessmentId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->quizAttemptService->startAttempt(
                $user,
                $assessmentId,
                $offeringId
            )
        );
    }

    public function saveAnswer(int $submissionId, int $questionId)
    {
        $user = auth()->user();

        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->quizAttemptService->saveAnswer(
                $user,
                $submissionId,
                $questionId,
                $data
            )
        );
    }

    public function submit(int $submissionId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->quizAttemptService->submitAttempt(
                $user,
                $submissionId
            )
        );
    }
}
