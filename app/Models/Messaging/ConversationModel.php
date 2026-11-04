<?php

namespace App\Models\Messaging;

use CodeIgniter\Model;

class ConversationModel extends Model
{
    protected $table         = 'conversations';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = ['institution_id', 'type', 'offering_id', 'subject_student_id', 'title', 'created_by', 'is_locked', 'direct_key', 'last_message_id', 'last_message_at'];
}
