<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AssessmentModel extends Model
{
    protected $table            = 'assessments';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'course_id',
        'module_id',
        'learning_item_id',
        'assessment_type_id',
        'title',
        'description',
        'instructions',
        'available_from',
        'due_date',
        'available_until',
        'duration',
        'total_points',
        'weight_percentage',
        'attempt_limit',
        'passing_score',
        'passing_percentage',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status'
    ];

    public function findByLearningItem(int $learningItemId): ?array
    {
        return $this->where('learning_item_id', $learningItemId)->first();
    }

    /**
     * One row per assessment in a course, each carrying how much grading
     * work it represents — this is what the instructor's Grades page lists
     * as clickable items, so the counts here are what tells an instructor
     * which one needs their attention without opening any of them.
     *
     * graded_count / needs_review_count are correlated subqueries rather
     * than a join, so an assessment with zero submissions still gets one
     * row (0/0) instead of disappearing from the list entirely.
     */
    public function getAssessmentSummaryForCourse(int $courseId): array
    {
        $builder = $this->db->table('assessments a');

        $builder->select([
            'a.id',
            'a.title',
            'at.name as assessment_type',
            'a.due_date',
            'a.total_points',
        ]);

        $builder->select(
            "(SELECT COUNT(*) FROM assessment_submissions s
                WHERE s.assessment_id = a.id AND s.is_graded = 1) AS graded_count",
            false
        );

        $builder->select(
            "(SELECT COUNT(*) FROM assessment_submissions s
                WHERE s.assessment_id = a.id AND s.is_graded = 0
                  AND s.status IN ('Submitted', 'Grading')) AS needs_review_count",
            false
        );

        $builder->join('assessment_types at', 'at.id = a.assessment_type_id', 'left');

        $builder->where('a.course_id', $courseId);
        $builder->orderBy('a.due_date', 'ASC');

        return $builder->get()->getResultArray();
    }
}