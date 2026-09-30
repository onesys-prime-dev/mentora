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
