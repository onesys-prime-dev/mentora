<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class LearningUnitModel extends Model
{
    protected $table = 'learning_units';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'module_id',
        'title',
        'description',
        'order_index',
        'status',
    ];

    public function retrieveLearningUnits(int $moduleId): array
    {
        $builder = $this->builder();

        return $builder
            ->select([
                'id',
                'title',
                'description',
                'order_index',
            ])
            ->where('module_id', $moduleId)
            ->where('status', 'published')
            ->orderBy('order_index')
            ->get()
            ->getResultArray();
    }
}