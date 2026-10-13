<?php

namespace App\Models\Learning;

use CodeIgniter\Shield\Entities\User;

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

    public function retrieveCourseModules(int $offeringId, User $user): array
    {
        $builder = $this->builder();

        $builder
            ->select([
                'id',
                'title',
                'description',
                'order_index',
                'status',
            ])
            ->where('offering_id', $offeringId);

        if (! \App\Services\Courses\CourseViewer::seesInstructorView($user)) {

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

    public function retrieveCourseModule(
        int $moduleId,
        User $user
    ): ?array {

        $builder = $this->builder();

        $builder
            ->select([
                'id',
                'offering_id',
                'title',
                'description',
                'order_index',
                'status',
            ])
            ->where('id', $moduleId);

        if (! \App\Services\Courses\CourseViewer::seesInstructorView($user)) {

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

    /**
     * Summed estimated_minutes of each module's published items — the
     * "duration" badge on the module list. Keyed by module id; a module
     * with nothing published (or nothing estimated) is simply absent.
     */
    public function retrieveDurationMinutes(array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }

        $rows = $this->builder()
            ->select([
                'course_modules.id AS module_id',
                'SUM(learning_items.estimated_minutes) AS minutes',
            ])
            ->join('learning_units', 'learning_units.module_id = course_modules.id')
            ->join('learning_items', 'learning_items.learning_unit_id = learning_units.id')
            ->whereIn('course_modules.id', $moduleIds)
            ->where('learning_units.status', 'published')
            ->where('learning_items.status', 'published')
            ->where('learning_items.estimated_minutes IS NOT NULL')
            ->groupBy('course_modules.id')
            ->get()
            ->getResultArray();

        $byModule = [];

        foreach ($rows as $row) {
            $byModule[(int) $row['module_id']] = (int) $row['minutes'];
        }

        return $byModule;
    }

    /**
     * How many published items of each type ("Video", "PDF", …) sit under
     * each module — the "What's included" row. Keyed by module id, each
     * value a map of item_type => count.
     */
    public function retrieveItemTypeCounts(array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }

        $rows = $this->builder()
            ->select([
                'course_modules.id AS module_id',
                'learning_items.item_type',
                'COUNT(*) AS item_count',
            ])
            ->join('learning_units', 'learning_units.module_id = course_modules.id')
            ->join('learning_items', 'learning_items.learning_unit_id = learning_units.id')
            ->whereIn('course_modules.id', $moduleIds)
            ->where('learning_units.status', 'published')
            ->where('learning_items.status', 'published')
            ->groupBy(['course_modules.id', 'learning_items.item_type'])
            ->get()
            ->getResultArray();

        $byModule = [];

        foreach ($rows as $row) {
            $byModule[(int) $row['module_id']][$row['item_type']] = (int) $row['item_count'];
        }

        return $byModule;
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

    /**
     * The most recent module edit per offering — a course's last sign of
     * life, for flagging one that's gone quiet. An offering with no modules
     * at all (never set up) just doesn't appear in the result; the caller
     * treats that the same as "no activity yet".
     *
     * @return array<int, string> offering_id => datetime
     */
    public function retrieveLastActivityByOffering(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $rows = $this->builder()
            ->select(['offering_id', 'MAX(updated_at) AS last_activity'])
            ->whereIn('offering_id', $offeringIds)
            ->groupBy('offering_id')
            ->get()
            ->getResultArray();

        $byOffering = [];

        foreach ($rows as $row) {
            $byOffering[(int) $row['offering_id']] = $row['last_activity'];
        }

        return $byOffering;
    }

    /**
     * How many modules were touched per offering within [$from, $until) when
     * given — the "content updates" side of instructor activity.
     *
     * @return array<int, int>
     */
    public function retrieveUpdateCountsByOffering(array $offeringIds, ?string $from = null, ?string $until = null): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $builder = $this->builder()
            ->select(['offering_id', 'COUNT(*) AS update_count'])
            ->whereIn('offering_id', $offeringIds);

        if ($from !== null) {
            $builder->where('updated_at >=', $from);
        }

        if ($until !== null) {
            $builder->where('updated_at <', $until);
        }

        $rows = $builder->groupBy('offering_id')->get()->getResultArray();

        $byOffering = [];

        foreach ($rows as $row) {
            $byOffering[(int) $row['offering_id']] = (int) $row['update_count'];
        }

        return $byOffering;
    }
}
