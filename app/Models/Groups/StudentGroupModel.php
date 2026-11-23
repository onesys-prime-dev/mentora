<?php

declare(strict_types=1);

namespace App\Models\Groups;

use CodeIgniter\Model;

/**
 * A student group, in a set or (later) on an activity.
 */
class StudentGroupModel extends Model
{
    protected $table = 'student_groups';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $allowedFields = ['set_id', 'config_id', 'name', 'leader_user_id', 'leader_finalized_at', 'status', 'proposed_by', 'frozen_at'];
}
