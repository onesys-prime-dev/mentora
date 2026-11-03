<?php

namespace App\Models\Messaging;

use App\Models\Messaging\Concerns\RefusesIdLookups;
use CodeIgniter\Model;

/**
 * The primary key is composite (blocker_id, blocked_id): every read and delete must constrain BOTH columns
 * with where(). Id-based find/update/delete throw.
 */
class UserBlockModel extends Model
{
    use RefusesIdLookups;

    protected $table            = 'user_blocks';
    protected $primaryKey       = 'blocker_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = ['institution_id', 'blocker_id', 'blocked_id', 'created_at'];
}
