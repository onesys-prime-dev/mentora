<?php

declare(strict_types=1);

namespace App\Models\Groups;

use CodeIgniter\Model;

/**
 * A student's place in a group. Only joined_at is kept, so no created/updated timestamps.
 */
class StudentGroupMemberModel extends Model
{
    protected $table = 'student_group_members';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['group_id', 'set_id', 'config_id', 'student_user_id', 'joined_at'];
}
