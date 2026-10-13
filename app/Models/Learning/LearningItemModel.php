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

        if (! \App\Services\Courses\CourseViewer::seesInstructorView($user)) {
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

        if (! \App\Services\Courses\CourseViewer::seesInstructorView($user)) {
            $builder->where('status', 'published');
        }

        return $builder
            ->get()
            ->getRowArray();
    }

    /**
     * Draft learning items across the given offerings, for the instructor
     * dashboard's Unpublished widget.
     */
    public function retrieveDrafts(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->builder()
            ->select([
                'learning_items.id AS content_id',
                'learning_items.learning_unit_id AS unit_id',
                'learning_items.item_type',
                'learning_units.module_id',
                'course_modules.offering_id',
                'learning_items.title',
                'learning_items.updated_at',
            ])
            ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->whereIn('course_modules.offering_id', $offeringIds)
            ->where('learning_items.status', 'draft')
            ->orderBy('learning_items.updated_at', 'DESC')
            ->get()
            ->getResultArray();
    }
}
