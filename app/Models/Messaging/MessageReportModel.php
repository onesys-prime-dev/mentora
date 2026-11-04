<?php

namespace App\Models\Messaging;

use CodeIgniter\Model;

class MessageReportModel extends Model
{
    protected $table         = 'message_reports';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['institution_id', 'message_id', 'reporter_id', 'reason', 'note_enc', 'snapshot_enc', 'status', 'reviewed_by', 'reviewed_at', 'created_at'];
}
