<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AiGenerationBatchModel extends Model
{
    protected $table            = 'ai_generation_batches';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'assessment_id',
        'course_id',
        'source_type',
        'topic',
        'source_file_id',
        'learning_unit_id',
        'source_text',
        'source_items',
        'type_mix',
        'requested_count',
        'generated_count',
        'points_per_question',
        'status',
        'error_message',
        'created_by',
        'created_at',
        'replaces_question_id',
        'instruction',
    ];
}
