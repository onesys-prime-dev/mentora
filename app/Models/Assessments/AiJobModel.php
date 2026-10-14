<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AiJobModel extends Model
{
    protected $table            = 'ai_jobs';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = true;

    protected $allowedFields = [
        'institution_id', 'course_id', 'assessment_id', 'batch_id', 'created_by',
        'kind', 'status', 'payload', 'result', 'checkpoint',
        'stage', 'progress_done', 'progress_of', 'progress_note', 'waiting_seconds',
        'error_code', 'error_message', 'requeues',
        'claimed_by', 'claimed_at', 'heartbeat_at', 'finished_at',
    ];
}
