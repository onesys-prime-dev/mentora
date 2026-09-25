<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AssessmentModel extends Model
{
    protected $table            = 'assessments';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'course_id',
        'module_id',
        'learning_item_id',
        'assessment_type_id',
        'title',
        'description',
        'instructions',
        'available_from',
        'due_date',
        'available_until',
        'duration',
        'total_points',
        'weight_percentage',
        'attempt_limit',
        'passing_score',
        'passing_percentage',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status'
    ];

    public function findByLearningItem(int $learningItemId): ?array
    {
        return $this->where('learning_item_id', $learningItemId)->first();
    }
}