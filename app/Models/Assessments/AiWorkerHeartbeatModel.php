<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AiWorkerHeartbeatModel extends Model
{
    protected $table            = 'ai_worker_heartbeats';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = ['worker_id', 'version', 'backend', 'last_seen_at'];
}
