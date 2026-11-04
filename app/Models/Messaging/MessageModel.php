<?php

namespace App\Models\Messaging;

use CodeIgniter\Model;

class MessageModel extends Model
{
    protected $table         = 'messages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['conversation_id', 'sender_id', 'body_enc', 'client_uuid', 'removed_at', 'removed_by', 'created_at'];
}
