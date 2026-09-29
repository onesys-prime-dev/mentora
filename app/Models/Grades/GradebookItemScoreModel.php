<?php

namespace App\Models\Grades;

use CodeIgniter\Model;

/**
 * Each student's score on each grade item — the gradebook's ledger.
 * Assessment-backed rows mirror the counted submission; manual rows are
 * entered by the instructor (currently through the offline import).
 */
class GradebookItemScoreModel extends Model
{
    protected $table            = 'gradebook_item_scores';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'course_offering_id',
        'student_id',
        'grade_item_id',
        'assessment_submission_id',
        'raw_score',
        'raw_percentage',
        'is_missing',
        'graded_by',
        'graded_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Insert or update the one row for (grade item, student).
     */
    public function upsertScore(int $offeringId, int $studentId, int $gradeItemId, array $values): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->where('grade_item_id', $gradeItemId)
            ->where('student_id', $studentId)
            ->first();

        if ($existing) {
            $this->update($existing['id'], $values + ['updated_at' => $now]);
            return;
        }

        $this->insert($values + [
            'course_offering_id' => $offeringId,
            'student_id' => $studentId,
            'grade_item_id' => $gradeItemId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Scores for a set of grade items, keyed [grade_item_id][student_id].
     */
    public function mapForItems(array $gradeItemIds, ?array $studentIds = null): array
    {
        if ($gradeItemIds === []) {
            return [];
        }

        $builder = $this->whereIn('grade_item_id', $gradeItemIds);

        if ($studentIds !== null) {
            if ($studentIds === []) {
                return [];
            }
            $builder->whereIn('student_id', $studentIds);
        }

        $map = [];

        foreach ($builder->findAll() as $row) {
            $map[(int) $row['grade_item_id']][(int) $row['student_id']] = $row;
        }

        return $map;
    }
}
