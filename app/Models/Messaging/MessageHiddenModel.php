<?php

namespace App\Models\Messaging;

use CodeIgniter\Model;

class MessageHiddenModel extends Model
{
    protected $table         = 'message_hidden';
    protected $primaryKey    = 'message_id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['message_id', 'user_id'];
}
