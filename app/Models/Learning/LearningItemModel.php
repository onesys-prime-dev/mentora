<?php

namespace App\Models\Learning;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Model;

class LearningItemModel extends Model
{
    protected $table = 'learning_items';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    // No deleted_at column on this table — see CreateLearning*/CourseModules migrations.
    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'learning_unit_id',
        'title',
        'item_type',
        'content',
        'estimated_minutes',
        'order_index',
        'status',
        'prerequisite_mode',
    ];

    public function retrieveLearningItems(int $learningUnitId, User $user): array
    {
        $builder = $this->builder();

        $builder
            ->select([
                'id',
                'learning_unit_id',
                'title',
                'item_type',
                'content',
                'estimated_minutes',
                'order_index',
                'status',
                'prerequisite_mode',
            ])
            ->where('learning_unit_id', $learningUnitId);

        if (! $user->inGroup('instructor')) {
            $builder->where('status', 'published');
        }

        return $builder
            ->orderBy('order_index')
            ->get()
            ->getResultArray();
    }

    public function retrieveLearningItem(int $learningItemId, User $user): ?array
    {
        $builder = $this->builder();

        $builder
            ->select([
                'id',
                'learning_unit_id',
                'title',
                'item_type',
                'content',
                'estimated_minutes',
                'order_index',
                'status',
                'prerequisite_mode',
            ])
            ->where('id', $learningItemId);

        if (! $user->inGroup('instructor')) {
            $builder->where('status', 'published');
        }

        return $builder
            ->get()
            ->getRowArray();
    }
}