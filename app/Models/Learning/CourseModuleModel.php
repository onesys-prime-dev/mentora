<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class CourseModuleModel extends Model
{
    protected $table = 'course_modules';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    // No deleted_at column on this table — see CreateLearning*/CourseModules migrations.
    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'offering_id',
        'title',
        'description',
        'order_index',
        'status',
    ];

    /**
     * Per-module cohort completion for a set of offerings.
     *
     * Returns the raw counts rather than a percentage: the denominator is
     * (published items x enrolled students), and the roster size lives in a
     * different query, so the caller does the final division.
     *
     * Only published units and items count — draft material is not something
     * a student could have completed, and including it would drag every
     * percentage down as an instructor drafts ahead.
     */
    public function retrieveModuleProgress(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->builder()
            ->select([
                'course_modules.id AS module_id',
                'course_modules.offering_id',
                'course_modules.title',
                'course_modules.order_index',
            ])
            ->select(
                "(SELECT COUNT(*)
                    FROM learning_items
                    JOIN learning_units
                      ON learning_units.id = learning_items.learning_unit_id
                   WHERE learning_units.module_id = course_modules.id
                     AND learning_units.status = 'published'
                     AND learning_items.status = 'published') AS item_count",
                false
            )
            ->select(
                "(SELECT COUNT(*)
                    FROM learning_completions
                    JOIN learning_items
                      ON learning_items.id = learning_completions.learning_item_id
                    JOIN learning_units
                      ON learning_units.id = learning_items.learning_unit_id
                   WHERE learning_units.module_id = course_modules.id
                     AND learning_completions.status = 'completed') AS completed_count",
                false
            )
            ->whereIn('course_modules.offering_id', $offeringIds)
            ->where('course_modules.status', 'published')
            ->orderBy('course_modules.offering_id', 'ASC')
            ->orderBy('course_modules.order_index', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** Draft modules across a set of offerings, newest edit first. */
    public function retrieveDrafts(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->builder()
            ->select([
                'course_modules.id AS content_id',
                'course_modules.offering_id',
                'course_modules.title',
                'course_modules.updated_at',
            ])
            ->select(
                "(SELECT COUNT(*)
                    FROM learning_units
                   WHERE learning_units.module_id = course_modules.id) AS child_count",
                false
            )
            ->whereIn('course_modules.offering_id', $offeringIds)
            ->where('course_modules.status', 'draft')
            ->orderBy('course_modules.updated_at', 'DESC')
            ->get()
            ->getResultArray();
    }
}
