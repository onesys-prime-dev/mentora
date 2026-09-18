<?php

namespace App\Models\Learning;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Model;

class LearningUnitModel extends Model
{
    protected $table = 'learning_units';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = false;

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

    public function retrieveLearningUnits(int $moduleId, User $user): array
    {
        $builder = $this->builder();

        $builder
            ->select([
                'id',
                'module_id',
                'title',
                'description',
                'order_index',
                'status',
            ])
            ->where('module_id', $moduleId);

        if (! $user->inGroup('instructor')) {

            $builder->where(
                'status',
                'published'
            );

        }

        return $builder
            ->orderBy('order_index')
            ->get()
            ->getResultArray();
    }

    public function retrieveLearningUnit(int $learningUnitId, User $user): ?array
    {
        $builder = $this->builder();

        $builder
            ->select([
                'id',
                'module_id',
                'title',
                'description',
                'order_index',
                'status',
            ])
            ->where('id', $learningUnitId);

        if (! $user->inGroup('instructor')) {

            $builder->where(
                'status',
                'published'
            );

        }

        return $builder
            ->get()
            ->getRowArray();
    }
}