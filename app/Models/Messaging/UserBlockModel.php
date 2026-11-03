<?php

namespace App\Models\Messaging;

use CodeIgniter\Model;

class UserBlockModel extends Model
{
    protected $table         = 'user_blocks';
    protected $primaryKey    = 'blocker_id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['institution_id', 'blocker_id', 'blocked_id', 'created_at'];
}
