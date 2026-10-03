<?php

namespace App\Models\Learning;

use CodeIgniter\Shield\Entities\User;

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

    /**
     * Every unit in an offering with its parent module's title, in course
     * order — flat, for a grouped picker. Modules hang off offerings, not
     * courses, so this is offering-scoped rather than course-scoped.
     */
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
                'course_modules.id AS module_id',
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
