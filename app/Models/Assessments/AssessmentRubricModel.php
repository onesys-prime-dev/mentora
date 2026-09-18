<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AssessmentRubricModel extends Model
{
    protected $table            = 'assessment_rubrics';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'question_id',
        'assessment_id',
        'title',
        'description',
        'total_points',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status'
    ];

    public function findByAssessment(int $assessmentId): ?array
    {
        return $this
            ->where('assessment_id', $assessmentId)
            ->where('status', 'Active')
            ->first();
    }

    public function listForCourse(int $courseId, ?int $excludeAssessmentId = null): array
    {
        $builder = $this->db->table('assessment_rubrics r');

        $builder->select([
            'r.id',
            'r.title',
            'r.description',
            'r.total_points',
            'r.assessment_id',
            '(SELECT COUNT(*) FROM assessment_rubric_criteria c WHERE c.rubric_id = r.id) as criteria_count',
        ]);

        $builder->join('assessments a', 'a.id = r.assessment_id');

        $builder->where('a.course_id', $courseId);
        $builder->where('r.status', 'Active');

        if ($excludeAssessmentId) {
            $builder->where('r.assessment_id !=', $excludeAssessmentId);
        }

        $builder->orderBy('r.created_at', 'DESC');

        return $builder->get()->getResultArray();
    }

    public function getWithCriteria(int $rubricId): ?array
    {
        $rubric = $this->find($rubricId);

        if (!$rubric) {
            return null;
        }

        $criteriaModel = new AssessmentRubricCriteriaModel();

        $rubric['criteria'] = $criteriaModel
            ->where('rubric_id', $rubricId)
            ->orderBy('order_index', 'ASC')
            ->findAll();

        return $rubric;
    }

    public function recalculateTotal(int $rubricId): void
    {
        $criteriaModel = new AssessmentRubricCriteriaModel();

        $total = $criteriaModel
            ->selectSum('max_points')
            ->where('rubric_id', $rubricId)
            ->first();

        $this->update($rubricId, [
            'total_points' => $total['max_points'] ?? 0,
        ]);
    }
}