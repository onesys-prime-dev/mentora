<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class LearningUnitModel extends Model
{
    protected $table = 'learning_units';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    // No deleted_at column on this table — see CreateLearning*/CourseModules migrations.
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

    /**
     * Draft units across a set of offerings. A unit can sit in draft under a
     * published module, which is the case students notice — the module opens
     * but part of it is missing.
     */
    public function retrieveDrafts(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->builder()
            ->select([
                'learning_units.id AS content_id',
                'course_modules.offering_id',
                'learning_units.title',
                'learning_units.updated_at',
            ])
            ->select(
                "(SELECT COUNT(*)
                    FROM learning_items
                   WHERE learning_items.learning_unit_id = learning_units.id) AS child_count",
                false
            )
            ->join(
                'course_modules',
                'course_modules.id = learning_units.module_id'
            )
            ->whereIn('course_modules.offering_id', $offeringIds)
            ->where('learning_units.status', 'draft')
            ->orderBy('learning_units.updated_at', 'DESC')
            ->get()
            ->getResultArray();
    }
}
