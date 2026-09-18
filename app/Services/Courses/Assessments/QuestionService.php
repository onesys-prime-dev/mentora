<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentQuestionModel;
use App\Models\Assessments\AssessmentQuestionChoiceModel;

class QuestionService
{
    protected AssessmentModel $assessmentModel;
    protected AssessmentQuestionModel $questionModel;
    protected AssessmentQuestionChoiceModel $choiceModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->questionModel = new AssessmentQuestionModel();
        $this->choiceModel = new AssessmentQuestionChoiceModel();
    }

    public function listQuestions(int $assessmentId): array
    {
        return [
            'success' => true,
            'questions' => $this->questionModel->listForAssessment($assessmentId),
        ];
    }

    public function getQuestion(int $questionId): array
    {
        $question = $this->questionModel->getWithChoices($questionId);

        if (!$question) {
            return [
                'success' => false,
                'message' => 'Question not found.',
            ];
        }

        return [
            'success' => true,
            'question' => $question,
        ];
    }

    public function createQuestion(User $user, int $assessmentId, array $data): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.',
            ];
        }

        $order = $this->questionModel
            ->where('assessment_id', $assessmentId)
            ->countAllResults();

        $now = date('Y-m-d H:i:s');

        $this->questionModel->insert([

            'assessment_id' => $assessmentId,
            'question_text' => $data['question_text'] ?? '',
            'question_type' => $data['question_type'] ?? 'Essay',
            'points' => $data['points'] ?? 0,
            'is_required' => !empty($data['is_required']),
            'order_index' => $order + 1,
            'created_by' => $user->id,
            'created_at' => $now,
            'status' => 'Active',

        ]);

        $questionId = $this->questionModel->getInsertID();

        $this->syncChoices($questionId, $data['choices'] ?? []);

        $this->questionModel->recalculateAssessmentTotal($assessmentId);

        return [
            'success' => true,
            'message' => 'Question added successfully.',
            'question' => $this->questionModel->getWithChoices($questionId),
        ];
    }

    public function updateQuestion(User $user, int $questionId, array $data): array
    {
        $question = $this->questionModel->find($questionId);

        if (!$question) {
            return [
                'success' => false,
                'message' => 'Question not found.',
            ];
        }

        $this->questionModel->update($questionId, [

            'question_text' => $data['question_text'] ?? $question['question_text'],
            'question_type' => $data['question_type'] ?? $question['question_type'],
            'points' => $data['points'] ?? $question['points'],
            'is_required' => array_key_exists('is_required', $data)
                ? !empty($data['is_required'])
                : $question['is_required'],
            'updated_by' => $user->id,
            'updated_at' => date('Y-m-d H:i:s'),

        ]);

        if (array_key_exists('choices', $data)) {
            $this->syncChoices($questionId, $data['choices']);
        }

        $this->questionModel->recalculateAssessmentTotal($question['assessment_id']);

        return [
            'success' => true,
            'message' => 'Question updated successfully.',
            'question' => $this->questionModel->getWithChoices($questionId),
        ];
    }

    public function duplicateQuestion(User $user, int $questionId): array
    {
        $question = $this->questionModel->getWithChoices($questionId);

        if (!$question) {
            return [
                'success' => false,
                'message' => 'Question not found.',
            ];
        }

        $assessmentId = $question['assessment_id'];

        $now = date('Y-m-d H:i:s');

        $this->questionModel->insert([

            'assessment_id' => $assessmentId,
            'question_text' => $question['question_text'],
            'question_type' => $question['question_type'],
            'points' => $question['points'],
            'is_required' => $question['is_required'],
            'order_index' => (int) $question['order_index'] + 1,
            'created_by' => $user->id,
            'created_at' => $now,
            'status' => 'Active',

        ]);

        $newQuestionId = $this->questionModel->getInsertID();

        foreach ($question['choices'] as $choice) {

            $this->choiceModel->insert([

                'question_id' => $newQuestionId,
                'choice_text' => $choice['choice_text'],
                'is_correct' => $choice['is_correct'],
                'order_index' => $choice['order_index'],
                'created_at' => $now,

            ]);

        }

        // Reindex so the duplicate lands immediately after the original.
        $this->reindexQuestions($assessmentId);

        $this->questionModel->recalculateAssessmentTotal($assessmentId);

        return [
            'success' => true,
            'message' => 'Question duplicated successfully.',
            'question' => $this->questionModel->getWithChoices($newQuestionId),
        ];
    }

    public function reorderQuestions(int $assessmentId, array $orderedIds): array
    {
        $order = 1;

        foreach ($orderedIds as $questionId) {

            $this->questionModel->update((int) $questionId, [
                'order_index' => $order++,
            ]);

        }

        return [
            'success' => true,
            'message' => 'Questions reordered successfully.',
        ];
    }

    public function deleteQuestion(int $questionId): array
    {
        $question = $this->questionModel->find($questionId);

        if (!$question) {
            return [
                'success' => false,
                'message' => 'Question not found.',
            ];
        }

        $assessmentId = $question['assessment_id'];

        $this->questionModel->delete($questionId);

        $this->reindexQuestions($assessmentId);

        $this->questionModel->recalculateAssessmentTotal($assessmentId);

        return [
            'success' => true,
            'message' => 'Question deleted successfully.',
        ];
    }

    private function syncChoices(int $questionId, array $choices): void
    {
        $this->choiceModel
            ->where('question_id', $questionId)
            ->delete();

        $order = 1;

        $now = date('Y-m-d H:i:s');

        foreach ($choices as $choice) {

            $text = trim($choice['text'] ?? '');

            if ($text === '') {
                continue;
            }

            $this->choiceModel->insert([

                'question_id' => $questionId,
                'choice_text' => $text,
                'is_correct' => !empty($choice['is_correct']),
                'order_index' => $order++,
                'created_at' => $now,

            ]);

        }
    }

    private function reindexQuestions(int $assessmentId): void
    {
        $questions = $this->questionModel
            ->where('assessment_id', $assessmentId)
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        $order = 1;

        foreach ($questions as $question) {

            $this->questionModel->update($question['id'], [
                'order_index' => $order++,
            ]);

        }
    }
}
