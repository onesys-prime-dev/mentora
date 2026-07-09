<?php

namespace App\Models\Assignments;

use CodeIgniter\Model;

class InstructorAssignmentModel extends Model
{
    protected $table = 'instructor_assignments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'assigned_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'offering_id',
        'instructor_user_id',
        'assigned_at',
    ];
}