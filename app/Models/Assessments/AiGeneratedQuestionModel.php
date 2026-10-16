<?php

namespace App\Models\Assessments;

use App\Services\Courses\Assessments\ShortAnswerGrader;
use CodeIgniter\Model;

class AiGeneratedQuestionModel extends Model
{
    protected $table            = 'ai_generated_questions';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'batch_id',
        'question_text',
        'question_type',
        'points',
        'is_required',
        'allow_multiple_answers',
        'answers_count',
        'accepted_answers',
        'case_sensitive',
        'choices',
        'similarity_score',
        'math_check',
        'explanation',
        'evidence_quote',
        'source_title',
        'source_page',
        'verification',
        'flags',
        'status',
        'created_at',
        'updated_at',
    ];

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        $row['choices'] = $row['choices'] ? (json_decode($row['choices'], true) ?? []) : [];
        $row['accepted_answers'] = ShortAnswerGrader::decode($row['accepted_answers'] ?? null);
        $row['verification'] = ! empty($row['verification']) ? (json_decode($row['verification'], true) ?: null) : null;
        $row['flags'] = ! empty($row['flags']) ? (json_decode($row['flags'], true) ?: []) : [];

        return $row;
    }

    public function listForBatch(int $batchId): array
    {
        $rows = $this
            ->where('batch_id', $batchId)
            ->where('status', 'pending')
            ->orderBy('id', 'ASC')
            ->findAll();

        return array_map(fn (array $row): array => $this->decode($row), $rows);
    }

    public function getDecoded(int $id): ?array
    {
        $row = $this->find($id);

        if (!$row) {
            return null;
        }

        return $this->decode($row);
    }
}
