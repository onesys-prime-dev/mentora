<?php

declare(strict_types=1);

namespace App\Services\Courses\Grades;

use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\Assessments\AssessmentResultService;

/**
 * The one rule for work waiting to be graded: pending work that can still
 * change the grade (any ungraded quiz attempt, an assignment's latest
 * submission), from students enrolled or completed, on assessments that are
 * not archived. The Assessments tab, Finalize and the Grades page all read it
 * from here, so they cannot disagree.
 *
 * Three queries however many offerings are asked for.
 */
final class PendingGrading
{
    /**
     * @param list<int> $offeringIds
     *
     * @return array{
     *     assessments: list<array{offering_id: int, assessment_id: int, title: string, type: string, waiting: int, late: int, oldest_at: ?int, first_student_id: ?int}>,
     *     byOffering: array<int, int>
     * }
     */
    public function forOfferings(array $offeringIds): array
    {
        $offeringIds = array_values(array_unique(array_map('intval', $offeringIds)));
        $byOffering = array_fill_keys($offeringIds, 0);

        if ($offeringIds === []) {
            return ['assessments' => [], 'byOffering' => []];
        }

        $db = db_connect();

        $assessments = $db->table('assessments a')
            ->select(['a.id', 'a.title', 'a.due_date', 'cm.offering_id', 'at.name AS type_name'])
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->join('assessment_types at', 'at.id = a.assessment_type_id', 'left')
            ->whereIn('cm.offering_id', $offeringIds)
            ->where('a.status <>', 'Archived')
            ->orderBy('a.id', 'ASC')
            ->get()
            ->getResultArray();

        if ($assessments === []) {
            return ['assessments' => [], 'byOffering' => $byOffering];
        }

        $enrolled = [];
        $studentIds = [];

        foreach ($db->table('enrollments')
            ->select(['offering_id', 'student_user_id'])
            ->whereIn('offering_id', $offeringIds)
            ->whereIn('status', ['enrolled', 'completed'])
            ->get()
            ->getResultArray() as $row) {
            $enrolled[(int) $row['offering_id']][(int) $row['student_user_id']] = true;
            $studentIds[(int) $row['student_user_id']] = true;
        }

        $attempts = (new AssessmentOverviewService())->latestAttempts(
            array_map('intval', array_column($assessments, 'id')),
            array_keys($studentIds),
        );

        $results = new AssessmentResultService();
        $listed = [];

        foreach ($assessments as $assessment) {
            $id = (int) $assessment['id'];
            $offeringId = (int) $assessment['offering_id'];
            $type = strtolower((string) $assessment['type_name']);
            $due = $assessment['due_date'] !== null ? (strtotime((string) $assessment['due_date']) ?: null) : null;

            $waiting = 0;
            $late = 0;
            $oldest = null;
            $first = null;

            foreach ($attempts[$id] ?? [] as $studentId => $attempt) {
                if (! isset($enrolled[$offeringId][$studentId])) {
                    continue;
                }

                foreach ($results->resultFor($type === 'quiz', $attempt['attempts'])['pending'] as $pending) {
                    $waiting++;

                    $at = strtotime((string) ($pending['submitted_at'] ?? '')) ?: null;

                    if ($at !== null && $due !== null && $at > $due) {
                        $late++;
                    }

                    if ($at !== null && ($oldest === null || $at < $oldest)) {
                        $oldest = $at;
                        $first = (int) $studentId;
                    }

                    $first ??= (int) $studentId;
                }
            }

            if ($waiting === 0) {
                continue;
            }

            $byOffering[$offeringId] += $waiting;
            $listed[] = [
                'offering_id'      => $offeringId,
                'assessment_id'    => $id,
                'title'            => (string) $assessment['title'],
                'type'             => $type,
                'waiting'          => $waiting,
                'late'             => $late,
                'oldest_at'        => $oldest,
                'first_student_id' => $first,
            ];
        }

        return ['assessments' => $listed, 'byOffering' => $byOffering];
    }
}
