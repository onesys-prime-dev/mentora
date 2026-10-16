<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\QuestionPickerService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * "Add from another quiz" in the quiz builder: the quizzes a teacher may take
 * questions from, the questions in one, and copying chosen ones in. The target
 * quiz is checked against the offering by the route's assessmentscope filter;
 * the source is checked by the service against what this person teaches.
 */
class QuestionPickerController extends BaseController
{
    protected QuestionPickerService $picker;

    public function __construct()
    {
        $this->picker = new QuestionPickerService();
    }

    public function sources(int $assessmentId)
    {
        return $this->response->setJSON([
            'success' => true,
            'sources' => $this->picker->sources(auth()->user(), $assessmentId),
        ]);
    }

    public function questions(int $assessmentId, int $sourceId)
    {
        $questions = $this->picker->questionsOf(auth()->user(), $sourceId, $assessmentId);

        if ($questions === null) {
            throw PageNotFoundException::forPageNotFound('Quiz not found.');
        }

        return $this->response->setJSON(['success' => true, 'questions' => $questions]);
    }

    public function copy(int $assessmentId)
    {
        $body = $this->request->getJSON(true) ?? [];

        if (!is_array($body)) {
            $body = [];
        }

        $result = $this->picker->copy(
            auth()->user(),
            $assessmentId,
            (int) ($body['source_assessment_id'] ?? 0),
            array_map('intval', (array) ($body['question_ids'] ?? []))
        );

        return $this->response->setStatusCode($result['success'] ? 200 : 422)->setJSON($result);
    }
}
