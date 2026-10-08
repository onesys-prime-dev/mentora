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

        'sub_department_id',
        'name',
        'code',
        'status',

    ];

    protected $validationRules = [

        'sub_department_id' => [
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

        'sub_department_id' => [
            'required' => 'Sub-department is required.',
        ],

        'name' => [
            'required' => 'Program name is required.',
        ],

        'code' => [
            'required' => 'Program code is required.',
        ],

    ];

    /** A sub-department's programs, by name. */
    public function forSubDepartment(int $subDepartmentId): array
    {
        return $this->where('sub_department_id', $subDepartmentId)
            ->orderBy('name')
            ->findAll();
    }

    /**
     * A program with the sub-department, department and institution above it
     * (null when there is no such program).
     */
    public function withHierarchy(int $programId): ?array
    {
        return $this->builder()
            ->select([
                'programs.*',
                'sub_departments.id AS sub_department_id',
                'sub_departments.name AS sub_department_name',
                'sub_departments.code AS sub_department_code',
                'departments.id AS department_id',
                'departments.name AS department_name',
                'departments.code AS department_code',
                'institutions.id AS institution_id',
                'institutions.name AS institution_name',
            ])
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->join('institutions', 'institutions.id = departments.institution_id')
            ->where('programs.id', $programId)
            ->get()
            ->getRowArray();
    }
}