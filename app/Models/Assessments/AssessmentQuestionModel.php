<?php

namespace App\Models\Assessments;

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

        $choiceModel = new AssessmentQuestionChoiceModel();

        $question['choices'] = $choiceModel
            ->where('question_id', $questionId)
            ->orderBy('order_index', 'ASC')
            ->findAll();

        return $question;
    }

    public function recalculateAssessmentTotal(int $assessmentId): void
    {
        $total = $this
            ->selectSum('points')
            ->where('assessment_id', $assessmentId)
            ->where('status', 'Active')
            ->first();

        (new AssessmentModel())->update($assessmentId, [
            'total_points' => $total['points'] ?? 0,
        ]);
    }
}