<?php

namespace App\Models\Messaging;

use App\Models\Messaging\Concerns\RefusesIdLookups;
use CodeIgniter\Model;

/**
 * The primary key is composite (message_id, user_id): every read and delete must constrain BOTH columns
 * with where(). Id-based find/update/delete throw.
 */
class MessageHiddenModel extends Model
{
    use RefusesIdLookups;

    protected $table            = 'message_hidden';
    protected $primaryKey       = 'message_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = ['message_id', 'user_id'];
}
