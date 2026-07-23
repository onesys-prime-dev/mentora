<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

class InstitutionUserModel extends Model
{
    protected $table = 'institution_users';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'institution_id',
        'user_id',

    ];

    protected $validationRules = [

        'institution_id' => 'required|is_natural_no_zero',

        'user_id' => 'required|is_natural_no_zero',

    ];

    /**
     * Retrieve the institution ID that the user belongs to.
     */
    public function retrieveInstitutionId(int $userId): ?int
    {
        $row = $this->builder()
            ->select('institution_id')
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        return $row['institution_id'] ?? null;
    }
}