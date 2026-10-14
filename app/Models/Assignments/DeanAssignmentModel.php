<?php

declare(strict_types=1);

namespace App\Models\Assignments;

use CodeIgniter\Model;

class DeanAssignmentModel extends Model
{
    protected $table = 'dean_assignments';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $allowedFields = [
        'department_id', 'dean_user_id', 'status', 'active_marker',
        'assigned_at', 'ended_at', 'assigned_by',
    ];

    public function currentDeanOfDepartment(int $departmentId): ?array
    {
        return $this->where([
            'department_id' => $departmentId,
            'status' => 'active',
            'active_marker' => 1,
        ])->first();
    }

    public function departmentOfDean(int $userId): ?array
    {
        return $this->select('dean_assignments.*, departments.institution_id')
            ->join('departments', 'departments.id = dean_assignments.department_id')
            ->where([
                'dean_assignments.dean_user_id' => $userId,
                'dean_assignments.status' => 'active',
                'dean_assignments.active_marker' => 1,
            ])->first();
    }

    /** @return list<int> */
    public function departmentIdsOfDean(int $userId): array
    {
        return array_map('intval', array_column(
            $this->select('department_id')
                ->where(['dean_user_id' => $userId, 'status' => 'active', 'active_marker' => 1])
                ->findAll(),
            'department_id'
        ));
    }
}