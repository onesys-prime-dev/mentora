<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\QuestionService;

class QuestionController extends BaseController
{
    protected QuestionService $questionService;

    public function __construct()
    {
        $this->questionService = new QuestionService();
    }

    public function index(int $assessmentId)
    {
        return $this->response->setJSON(
            $this->questionService->listQuestions($assessmentId)
        );
    }

    public function show(int $questionId)
    {
        return $this->response->setJSON(
            $this->questionService->getQuestion($questionId)
        );
    }

    public function store(int $assessmentId)
    {
        $user = auth()->user();

        $payload = $this->request->getJSON(true);

        return $this->response->setJSON(
            $this->questionService->createQuestion(
                $user,
                $assessmentId,
                $payload
            )
        );
    }

    public function update(int $questionId)
    {
        $user = auth()->user();

        $payload = $this->request->getJSON(true);

        return $this->response->setJSON(
            $this->questionService->updateQuestion(
                $user,
                $questionId,
                $payload
            )
        );
    }

    public function destroy(int $questionId)
    {
        return $this->response->setJSON(
            $this->questionService->deleteQuestion($questionId)
        );
    }

    public function duplicate(int $questionId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->questionService->duplicateQuestion(
                $user,
                $questionId
            )
        );
    }

    public function reorder(int $assessmentId)
    {
        $payload = $this->request->getJSON(true);

        return $this->response->setJSON(
            $this->questionService->reorderQuestions(
                $assessmentId,
                $payload['question_ids'] ?? []
            )
        );
    }
}
