<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

class InstitutionModel extends Model
{
    protected $table = 'institutions';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'name',
        'code',
        'status',

    ];

    protected $validationRules = [

        'name' => [
            'rules' => 'required|min_length[3]|max_length[255]',
        ],

        'code' => [
            'rules' => 'required|max_length[50]|is_unique[institutions.code,id,{id}]',
        ],

        'status' => [
            'rules' => 'required|in_list[active,inactive,archived]',
        ],

    ];

    protected $validationMessages = [

        'name' => [
            'required' => 'Institution name is required.',
        ],

        'code' => [
            'required'  => 'Institution code is required.',
            'is_unique' => 'Institution code already exists.',
        ],

    ];

    /** The programs an institution offers, whichever department they sit in. */
    public function programs(int $institutionId): array
    {
        return $this->db->table('programs')
            ->select('programs.*')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('departments.institution_id', $institutionId)
            ->orderBy('programs.name')
            ->get()
            ->getResultArray();
    }
}