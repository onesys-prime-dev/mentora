<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class LearningResourceModel extends Model
{
    protected $table = 'learning_resources';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    // No deleted_at column on this table — see CreateLearning*/CourseModules migrations.
    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'learning_item_id',
        'file_id',
        'title',
        'resource_type',
        'resource_url',
    ];

    /**
     * Retrieve the resource attached to a learning item.
     */
    public function retrieveLearningResource(
        int $learningItemId
    ): ?array
    {
        return $this->builder()
            ->select([
                'id',
                'learning_item_id',
                'file_id',
                'title',
                'resource_type',
                'resource_url',
            ])
            ->where('learning_item_id', $learningItemId)
            ->get()
            ->getRowArray();
    }

    /**
     * Retrieve all resources for a collection of learning items.
     * This will be used later when we optimize away N+1 queries.
     */
    public function retrieveLearningResources(
        array $learningItemIds
    ): array
    {
        if ($learningItemIds === []) {
            return [];
        }

        return $this->builder()
            ->select([
                'id',
                'learning_item_id',
                'file_id',
                'title',
                'resource_type',
                'resource_url',
            ])
            ->whereIn('learning_item_id', $learningItemIds)
            ->get()
            ->getResultArray();
    }
}