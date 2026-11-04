<?php

namespace App\Models\Messaging;

use CodeIgniter\Model;

class ConversationParticipantModel extends Model
{
    protected $table         = 'conversation_participants';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['conversation_id', 'user_id', 'joined_at', 'left_at', 'first_visible_message_id', 'last_read_message_id', 'is_starred', 'is_muted'];
}
