<?php

namespace App\Models\Grades;

use CodeIgniter\Model;

/**
 * What counts toward the grade: an assessment (assessment_id set) or a
 * manual item such as Attendance (assessment_id NULL). max_points is a
 * snapshot — see GradeItemSyncService::isOutOfSync().
 */
class GradeItemModel extends Model
{
    protected $table            = 'grade_items';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'grading_scheme_id',
        'category_id',
        'assessment_id',
        'name',
        'max_points',
        'sort_order',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status',
    ];

    /**
     * Active items of a scheme, each with its assessment's live total_points
     * (current_points) and type, so callers can spot out-of-sync snapshots.
     */
    public function listForScheme(int $schemeId, bool $countedOnly = false): array
    {
        $builder = $this->builder();

        $builder
            ->select([
                'grade_items.*',
                // Live title, so renaming the quiz renames its gradebook item.
                'a.title AS assessment_title',
                'a.total_points AS current_points',
                'a.status AS assessment_status',
                'at.name AS assessment_type',
            ])
            ->join('assessments a', 'a.id = grade_items.assessment_id', 'left')
            ->join('assessment_types at', 'at.id = a.assessment_type_id', 'left')
            ->where('grade_items.grading_scheme_id', $schemeId)
            ->where('grade_items.status', 'Active');

        // What the calculation counts: manual items, and assessments students
        // can actually see (a Draft or Archived one is not part of the grade).
        if ($countedOnly) {
            $builder->groupStart()
                ->where('grade_items.assessment_id IS NULL', null, false)
                ->orWhereIn('a.status', ['Published', 'Closed'])
                ->groupEnd();
        }

        return $builder
            ->orderBy('grade_items.sort_order', 'ASC')
            ->orderBy('grade_items.id', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function findForAssessment(int $assessmentId): ?array
    {
        return $this->where('assessment_id', $assessmentId)->first();
    }
}
