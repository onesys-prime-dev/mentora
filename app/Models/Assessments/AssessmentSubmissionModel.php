<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AssessmentSubmissionModel extends Model
{
    protected $table            = 'assessment_submissions';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'assessment_id',
        'student_id',
        'attempt_number',
        'started_at',
        'submitted_at',
        'time_spent',
        'submission_text',
        'submission_link',
        'submission_file_id',
        'score',
        'percentage',
        'is_late_submission',
        'is_graded',
        'graded_by',
        'graded_at',
        'feedback',
        'status',
        'grading_mode',
        'rubric_scores',
        'questions_snapshot',
    ];

    /**
     * Average grading turnaround (submitted -> graded, in hours) per
     * offering, over graded submissions whose grading fell inside
     * [$from, $until) when given. A submission still waiting to be graded
     * isn't turnaround yet, so only graded ones count.
     *
     * @return array<int, array{avg_hours: float, graded_count: int}>
     */
    public function retrieveGradingTurnaroundByOffering(array $offeringIds, ?string $from = null, ?string $until = null): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $builder = $this->builder()
            ->select([
                'course_modules.offering_id',
                'AVG(TIMESTAMPDIFF(HOUR, assessment_submissions.submitted_at, assessment_submissions.graded_at)) AS avg_hours',
                'COUNT(*) AS graded_count',
            ])
            ->join('assessments', 'assessments.id = assessment_submissions.assessment_id')
            ->join('course_modules', 'course_modules.id = assessments.module_id')
            ->whereIn('course_modules.offering_id', $offeringIds)
            ->where('assessment_submissions.is_graded', 1)
            ->where('assessment_submissions.graded_at IS NOT NULL', null, false)
            ->where('assessment_submissions.submitted_at IS NOT NULL', null, false);

        if ($from !== null) {
            $builder->where('assessment_submissions.graded_at >=', $from);
        }

        if ($until !== null) {
            $builder->where('assessment_submissions.graded_at <', $until);
        }

        $rows = $builder->groupBy('course_modules.offering_id')->get()->getResultArray();

        $byOffering = [];

        foreach ($rows as $row) {
            $byOffering[(int) $row['offering_id']] = [
                'avg_hours' => round((float) $row['avg_hours'], 1),
                'graded_count' => (int) $row['graded_count'],
            ];
        }

        return $byOffering;
    }
}