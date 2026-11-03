<?php

namespace App\Models\Messaging;

use CodeIgniter\Model;

class MessagePreferenceModel extends Model
{
    protected $table         = 'message_preferences';
    protected $primaryKey    = 'user_id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['user_id', 'quiet_start', 'quiet_end', 'email_alerts'];
}
