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
        'college_id', 'dean_user_id', 'status', 'active_marker',
        'assigned_at', 'ended_at', 'assigned_by',
    ];

    public function currentDeanOfCollege(int $collegeId): ?array
    {
        return $this->where([
            'college_id' => $collegeId,
            'status' => 'active',
            'active_marker' => 1,
        ])->first();
    }

    public function collegeOfDean(int $userId): ?array
    {
        return $this->select('dean_assignments.*, colleges.institution_id')
            ->join('colleges', 'colleges.id = dean_assignments.college_id')
            ->where([
                'dean_assignments.dean_user_id' => $userId,
                'dean_assignments.status' => 'active',
                'dean_assignments.active_marker' => 1,
            ])->first();
    }

    /** @return list<int> */
    public function collegeIdsOfDean(int $userId): array
    {
        return array_map('intval', array_column(
            $this->select('college_id')
                ->where(['dean_user_id' => $userId, 'status' => 'active', 'active_marker' => 1])
                ->findAll(),
            'college_id'
        ));
    }
}