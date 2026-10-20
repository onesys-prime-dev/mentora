<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

/**
 * Cached final grade per student per course offering. Written only by
 * GradingEngineService (recalculation / overrides) — read everywhere else.
 */
class GradebookRecordModel extends Model
{
    protected $table            = 'gradebook_records';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'course_offering_id',
        'student_id',
        'grading_scheme_id',
        'calculated_percentage',
        'calculated_grade_scale_item_id',
        'is_override',
        'overridden_percentage',
        'overridden_grade_scale_item_id',
        'override_reason',
        'overridden_by',
        'remarks',
        'inc_set_at',
        'inc_set_by',
        'inc_reason',
        'inc_until',
        'last_calculated_at',
        'created_at',
        'updated_at',
    ];

    public function findForStudent(int $offeringId, int $studentId): ?array
    {
        return $this->where('course_offering_id', $offeringId)
            ->where('student_id', $studentId)
            ->first();
    }

    /**
     * Every student's effective percentage, grouped by offering — the raw
     * input for a class average or a grade distribution. A student with
     * nothing calculated yet (no record at all) is simply absent, same as
     * "Incomplete" everywhere else this model is read.
     *
     * @return array<int, list<float>>
     */
    public function retrievePercentagesByOffering(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $rows = $this->select(['course_offering_id', 'calculated_percentage', 'overridden_percentage', 'is_override'])
            ->whereIn('course_offering_id', $offeringIds)
            ->findAll();

        $byOffering = [];

        foreach ($rows as $row) {

            $percentage = self::effectivePercentage($row);

            if ($percentage === null) {
                continue;
            }

            $byOffering[(int) $row['course_offering_id']][] = $percentage;

        }

        return $byOffering;
    }

    /**
     * The percentage that counts: the override when set, else the calculated one.
     */
    public static function effectivePercentage(?array $record): ?float
    {
        if (!$record) {
            return null;
        }

        $value = !empty($record['is_override'])
            ? $record['overridden_percentage']
            : $record['calculated_percentage'];

        return $value === null ? null : (float) $value;
    }
}
