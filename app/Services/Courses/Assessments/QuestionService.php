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

        $this->questionModel->insert(array_merge([

            'assessment_id' => $assessmentId,
            'question_text' => $data['question_text'] ?? '',
            'question_type' => $data['question_type'] ?? 'Essay',
            'points' => $data['points'] ?? 0,
            'is_required' => !empty($data['is_required']),
            'order_index' => $order + 1,
            'created_by' => $user->id,
            'created_at' => $now,
            'status' => 'Active',

        ], $this->resolveMultipleAnswers($data), $this->resolveShortAnswer($data)));

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

        $this->questionModel->update($questionId, array_merge([

            'question_text' => $data['question_text'] ?? $question['question_text'],
            'question_type' => $data['question_type'] ?? $question['question_type'],
            'points' => $data['points'] ?? $question['points'],
            'is_required' => array_key_exists('is_required', $data)
                ? !empty($data['is_required'])
                : $question['is_required'],
            'updated_by' => $user->id,
            'updated_at' => date('Y-m-d H:i:s'),

        ], $this->resolveMultipleAnswers($data, $question), $this->resolveShortAnswer($data, $question)));

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

        // Make room right after the original: everything below it moves down
        // one, so the copy doesn't tie with (and sort behind) the next question.
        $this->questionModel
            ->where('assessment_id', $assessmentId)
            ->where('order_index >', (int) $question['order_index'])
            ->set('order_index', 'order_index + 1', false)
            ->update();

        $this->questionModel->insert([

            'assessment_id' => $assessmentId,
            'question_text' => $question['question_text'],
            'question_type' => $question['question_type'],
            'allow_multiple_answers' => $question['allow_multiple_answers'],
            'answers_count' => $question['answers_count'],
            'accepted_answers' => ShortAnswerGrader::encode($question['accepted_answers']),
            'case_sensitive' => (int) $question['case_sensitive'],
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

            // Only this assessment's own questions can be renumbered here.
            $this->questionModel
                ->where('id', (int) $questionId)
                ->where('assessment_id', $assessmentId)
                ->set('order_index', $order)
                ->update();

            $order++;

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

    public function clearAllQuestions(int $assessmentId): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.',
            ];
        }

        // Choice rows cascade-delete via the FK on assessment_question_choices
        // (see 2026-07-17-000005_CreateAssessmentQuestionChoices), so a single
        // bulk delete here is enough — no per-row loop needed.
        $this->questionModel
            ->where('assessment_id', $assessmentId)
            ->delete();

        $this->questionModel->recalculateAssessmentTotal($assessmentId);

        return [
            'success' => true,
            'message' => 'All questions cleared.',
        ];
    }

    /**
     * Short Answer answer key: accepted_answers (a JSON list, the first
     * being the main answer) and case_sensitive (off by default). Other
     * question types never keep either. Fields absent from $data keep their
     * current value, so a partial update (e.g. just the case toggle) works.
     */
    private function resolveShortAnswer(array $data, ?array $existing = null): array
    {
        $type = $data['question_type'] ?? $existing['question_type'] ?? null;

        if ($type !== 'Short Answer') {
            return ['accepted_answers' => null, 'case_sensitive' => 0];
        }

        return [
            'accepted_answers' => array_key_exists('accepted_answers', $data)
                ? ShortAnswerGrader::encode($data['accepted_answers'])
                : ShortAnswerGrader::encode($existing['accepted_answers'] ?? null),
            'case_sensitive' => array_key_exists('case_sensitive', $data)
                ? (int) filter_var($data['case_sensitive'], FILTER_VALIDATE_BOOLEAN)
                : (int) ($existing['case_sensitive'] ?? 0),
        ];
    }

    /**
     * answers_count only means anything when allow_multiple_answers is
     * true — normalized together so a stray answers_count from a stale
     * form payload can never persist against a single-answer question.
     */
    private function resolveMultipleAnswers(array $data, array $existing = []): array
    {
        $allowMultiple = array_key_exists('allow_multiple_answers', $data)
            ? !empty($data['allow_multiple_answers'])
            : !empty($existing['allow_multiple_answers'] ?? false);

        if (!$allowMultiple) {
            return ['allow_multiple_answers' => false, 'answers_count' => null];
        }

        $answersCount = array_key_exists('answers_count', $data)
            ? (int) $data['answers_count']
            : (int) ($existing['answers_count'] ?? 2);

        // "Multiple" answers means at least 2 — a 1-answer "multiple
        // answers" question is a single-answer question in disguise.
        return ['allow_multiple_answers' => true, 'answers_count' => max(2, $answersCount)];
    }

    /**
     * Makes the question's choices match the submitted list while keeping the
     * rows (and so their ids) a student's answer points at. Deleting and
     * re-inserting would orphan every saved answer. A submitted choice reuses
     * the existing row with the same text first, then, for an edited text, the
     * unused existing row it most resembles; rows left over are removed.
     */
    private function syncChoices(int $questionId, array $choices): void
    {
        $existing = $this->choiceModel
            ->where('question_id', $questionId)
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        $wanted = [];

        foreach ($choices as $choice) {

            $text = trim($choice['text'] ?? '');

            if ($text !== '') {
                $wanted[] = ['text' => $text, 'is_correct' => !empty($choice['is_correct'])];
            }

        }

        // Same text first, so reordering or inserting keeps each answer on its choice.
        $assigned = [];
        $used = [];

        foreach ($wanted as $index => $choice) {

            foreach ($existing as $row) {

                if (!isset($used[$row['id']]) && trim((string) $row['choice_text']) === $choice['text']) {
                    $assigned[$index] = $row['id'];
                    $used[$row['id']] = true;
                    break;
                }

            }

        }

        // An edited text (a typo fix) takes over the unmatched row it most
        // resembles; a clearly different text is a new choice, so a student's
        // answer never moves onto an unrelated choice.
        $spare = array_values(array_filter($existing, static fn ($row) => !isset($used[$row['id']])));

        foreach ($wanted as $index => $choice) {

            if (isset($assigned[$index])) {
                continue;
            }

            $best = null;
            $bestScore = 0.0;

            foreach ($spare as $position => $row) {

                similar_text(mb_strtolower((string) $row['choice_text']), mb_strtolower($choice['text']), $score);

                if ($score > $bestScore) {
                    $best = $position;
                    $bestScore = $score;
                }

            }

            if ($best !== null && $bestScore >= 50.0) {
                $assigned[$index] = $spare[$best]['id'];
                array_splice($spare, $best, 1);
            }

        }

        $now = date('Y-m-d H:i:s');

        foreach ($wanted as $index => $choice) {

            $row = [
                'choice_text' => $choice['text'],
                'is_correct' => $choice['is_correct'],
                'order_index' => $index + 1,
            ];

            if (isset($assigned[$index])) {

                $this->choiceModel->update($assigned[$index], $row + ['updated_at' => $now]);

                continue;

            }

            $this->choiceModel->insert($row + ['question_id' => $questionId, 'created_at' => $now]);

        }

        foreach ($spare as $row) {
            $this->choiceModel->delete($row['id']);
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
