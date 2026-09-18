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

    public function getAssessmentsWithSubmissions(int $courseId, int $studentId): array
    {
        $builder = $this->db->table('assessments a');

        $builder->select([
            'a.id',
            'a.title',
            'a.assessment_type_id',
            'at.name as assessment_type',
            'a.due_date',
            'a.total_points',
            'a.status',
            's.status as submission_status',
            's.score as submission_score',
            '(SELECT COUNT(*) FROM assessment_questions q WHERE q.assessment_id = a.id) as question_count',
        ]);

        $builder->join(
            'assessment_types at',
            'at.id = a.assessment_type_id',
            'left'
        );

        $builder->join(
            'assessment_submissions s',
            "s.assessment_id = a.id AND s.student_id = " . $studentId,
            'left'
        );

        $builder->where('a.course_id', $courseId);
        $builder->orderBy('a.due_date', 'ASC');

        return $builder->get()->getResultArray();
    }
}