<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

class DepartmentModel extends Model
{
    protected $table = 'departments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'college_id',
        'code',
        'name',
        'is_active',

    ];

    protected $validationRules = [

        'college_id' => [
            'rules' => 'required|is_natural_no_zero',
        ],

        'code' => [
            'rules' => 'required|max_length[50]',
        ],

        'name' => [
            'rules' => 'required|min_length[3]|max_length[255]',
        ],

        'is_active' => [
            'rules' => 'permit_empty|in_list[0,1]',
        ],

    ];

    protected $validationMessages = [

        'college_id' => [
            'required' => 'College is required.',
        ],

        'code' => [
            'required' => 'Department code is required.',
        ],

        'name' => [
            'required' => 'Department name is required.',
        ],

    ];

    /** A college's departments, by name; inactive ones only when asked for. */
    public function forCollege(int $collegeId, bool $includeInactive = false): array
    {
        $builder = $this->where('college_id', $collegeId);

        if (! $includeInactive) {
            $builder->where('is_active', 1);
        }

        return $builder->orderBy('name')->findAll();
    }
}
