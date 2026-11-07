<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

class ProgramModel extends Model
{
    protected $table = 'programs';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'department_id',
        'name',
        'code',
        'status',

    ];

    protected $validationRules = [

        'department_id' => [
            'rules' => 'required|is_natural_no_zero',
        ],

        'name' => [
            'rules' => 'required|min_length[3]|max_length[255]',
        ],

        'code' => [
            'rules' => 'required|max_length[50]',
        ],

        'status' => [
            'rules' => 'required|in_list[active,inactive,archived]',
        ],

    ];

    protected $validationMessages = [

        'department_id' => [
            'required' => 'Department is required.',
        ],

        'name' => [
            'required' => 'Program name is required.',
        ],

        'code' => [
            'required' => 'Program code is required.',
        ],

    ];

    /** A department's programs, by name. */
    public function forDepartment(int $departmentId): array
    {
        return $this->where('department_id', $departmentId)
            ->orderBy('name')
            ->findAll();
    }

    /**
     * A program with the department, college and institution above it
     * (null when there is no such program).
     */
    public function withHierarchy(int $programId): ?array
    {
        return $this->builder()
            ->select([
                'programs.*',
                'departments.id AS department_id',
                'departments.name AS department_name',
                'departments.code AS department_code',
                'colleges.id AS college_id',
                'colleges.name AS college_name',
                'colleges.code AS college_code',
                'institutions.id AS institution_id',
                'institutions.name AS institution_name',
            ])
            ->join('departments', 'departments.id = programs.department_id')
            ->join('colleges', 'colleges.id = departments.college_id')
            ->join('institutions', 'institutions.id = colleges.institution_id')
            ->where('programs.id', $programId)
            ->get()
            ->getRowArray();
    }
}