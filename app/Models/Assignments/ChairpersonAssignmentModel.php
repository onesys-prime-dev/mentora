<?php

namespace App\Models\Assignments;

use CodeIgniter\Model;

class ChairpersonAssignmentModel extends Model
{
    protected $table = 'chairperson_assignments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'assigned_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'sub_department_id',
        'chairperson_user_id',
        'status',
        'assigned_at',
    ];

    /** Every sub-department this user actively chairs — the basis for all of their program-wide access. */
    public function subDepartmentIdsFor(int $userId): array
    {
        return array_map(
            'intval',
            array_column(
                $this->select('sub_department_id')
                    ->where('chairperson_user_id', $userId)
                    ->where('status', 'active')
                    ->findAll(),
                'sub_department_id'
            )
        );
    }

    /** Whether this user actively chairs the given sub-department. */
    public function isChairOf(int $userId, int $subDepartmentId): bool
    {
        return $this->where('chairperson_user_id', $userId)
            ->where('sub_department_id', $subDepartmentId)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }
}