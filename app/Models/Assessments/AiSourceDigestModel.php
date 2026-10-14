<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AiSourceDigestModel extends Model
{
    protected $table            = 'ai_source_digests';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = ['institution_id', 'source_hash', 'digest', 'token_estimate', 'created_at', 'last_used_at', 'expires_at'];
}
