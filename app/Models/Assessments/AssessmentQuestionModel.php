<?php

namespace App\Models\Assessments;

use App\Services\Courses\Assessments\ShortAnswerGrader;
use CodeIgniter\Model;

class AssessmentQuestionModel extends Model
{
    protected $table            = 'assessment_questions';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'assessment_id',
        'question_text',
        'question_type',
        'allow_multiple_answers',
        'answers_count',
        'accepted_answers',
        'case_sensitive',
        'points',
        'is_required',
        'order_index',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status'
    ];

    public function listForAssessment(int $assessmentId): array
    {
        $questions = $this
            ->where('assessment_id', $assessmentId)
            ->where('status', 'Active')
            ->orderBy('order_index', 'ASC')
            ->findAll();

        $choiceModel = new AssessmentQuestionChoiceModel();

        foreach ($questions as &$question) {

            // Short Answer keys, decoded to a list (see ShortAnswerGrader).
            $question['accepted_answers'] = ShortAnswerGrader::decode($question['accepted_answers'] ?? null);

            $question['choices'] = $question['question_type'] === 'Multiple Choice'
                ? $choiceModel
                    ->where('question_id', $question['id'])
                    ->orderBy('order_index', 'ASC')
                    ->findAll()
                : [];

        }

        return $questions;
    }

    public function getWithChoices(int $questionId): ?array
    {
        $question = $this->find($questionId);

        if (!$question) {
            return null;
        }

        $question['accepted_answers'] = ShortAnswerGrader::decode($question['accepted_answers'] ?? null);

        $choiceModel = new AssessmentQuestionChoiceModel();

        $question['choices'] = $choiceModel
            ->where('question_id', $questionId)
            ->orderBy('order_index', 'ASC')
            ->findAll();

        return $question;
    }

    /** What the quiz's active questions are worth together. */
    public function activeTotal(int $assessmentId): float
    {
        $total = $this
            ->selectSum('points')
            ->where('assessment_id', $assessmentId)
            ->where('status', 'Active')
            ->first();

        return (float) ($total['points'] ?? 0);
    }

    public function recalculateAssessmentTotal(int $assessmentId): void
    {
        (new AssessmentModel())->update($assessmentId, [
            'total_points' => $this->activeTotal($assessmentId),
        ]);
    }
}