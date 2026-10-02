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

    /**
     * Every file/link resource in a learning unit, joined to its owning
     * learning item (for title/type/status) and file (for size/mime/name).
     * Only pdf/link/video/reading items can carry a resource — quizzes and
     * assignments live on the Assessments page instead.
     */
    public function retrieveForLearningUnit(
        int $learningUnitId,
        bool $includeDrafts,
        string $search = ''
    ): array
    {
        $builder = $this->builder()
            ->select([
                'learning_resources.id AS resource_id',
                'learning_resources.title AS resource_title',
                'learning_resources.resource_type',
                'learning_resources.resource_url',
                'learning_resources.file_id',
                'learning_items.id AS item_id',
                'learning_items.title AS item_title',
                'learning_items.item_type',
                'learning_items.status AS item_status',
                'learning_items.order_index',
                'learning_items.created_at',
                'files.original_filename',
                'files.mime_type',
                'files.file_size',
            ])
            ->join('learning_items', 'learning_items.id = learning_resources.learning_item_id')
            ->join('files', 'files.id = learning_resources.file_id', 'left')
            ->where('learning_items.learning_unit_id', $learningUnitId)
            ->whereIn('learning_items.item_type', ['pdf', 'link', 'video', 'reading']);

        if (!$includeDrafts) {
            $builder->where('learning_items.status', 'published');
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('learning_resources.title', $search)
                ->orLike('learning_items.title', $search)
                ->groupEnd();
        }

        return $builder
            ->orderBy('learning_items.order_index', 'ASC')
            ->get()
            ->getResultArray();
    }
}