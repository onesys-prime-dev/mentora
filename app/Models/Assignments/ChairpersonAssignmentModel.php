<?php

namespace App\Models\Assignments;

use CodeIgniter\Model;

class ChairpersonAssignmentModel extends Model
{
    protected $table = 'chairperson_assignments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useTimestamps = true;

    protected $allowedFields = [
        'program_id',
        'chairperson_user_id',
        'status',
        'assigned_at',
    ];
}