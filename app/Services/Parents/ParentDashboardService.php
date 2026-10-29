<?php

declare(strict_types=1);

namespace App\Services\Parents;

use App\Models\Assessments\GradebookRecordModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Grades\GradingSchemeModel;
use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\GradeService;
use CodeIgniter\Shield\Entities\User;

/**
 * What a parent's dashboard shows about one linked child: the courses with
 * the standing the student sees on their own Grades tab, past terms, what
 * needs attention and the next fourteen days of deadlines.
 *
 * Everything is read through the services the student's own pages use, with
 * the child as the student, so the two never disagree. Nothing about a single
 * item is passed on: no per-assessment score, no submission, no feedback.
 * A grade is shown only once its scheme is published (the student's rule),
 * and "At risk" is the gradebook's tag for a running grade below the passing
 * mark; INC and Withdrawn replace it.
 */
class ParentDashboardService
{
    /** How far ahead deadlines are listed, and how long a new final grade stays in "needs attention". */
    public const WINDOW_DAYS = 14;

    /**
     * @return ?array{child: array{id: int, name: string}, courses: list<array<string, mixed>>, past: list<array<string, mixed>>, attention: list<array<string, mixed>>, deadlines: list<array<string, mixed>>} null when the parent may not reach the child
     */
    public function forChild(User $parent, int $studentId, ?int $now = null): ?array
    {
        $access = new ParentAccessService();
        $child  = $access->childUser($parent, $studentId);

        if ($child === null) {
            return null;
        }

        $now   ??= time();
        $names   = array_column($access->children($parent), 'name', 'student_id');
        $result  = [
            'child'     => ['id' => $studentId, 'name' => (string) ($names[$studentId] ?? '')],
            'courses'   => [],
            'past'      => [],
            'attention' => [],
            'deadlines' => [],
        ];

        $atRisk = $missing = $final = $deadlines = [];

        foreach ((new EnrollmentModel())->retrieveStudentCourseOfferings($studentId, false, true) as $row) {
            $current = $row['enrollment_status'] === 'enrolled' && $row['offering_status'] === 'active';

            [$standing, $assessments, $finalizedAt] = $this->standing($child, $row);

            $card = [
                'offering_id'       => (int) $row['offering_id'],
                'course_code'       => (string) $row['course_code'],
                'section_code'      => (string) $row['section_code'],
                'course_title'      => (string) $row['course_title'],
                'instructor_name'   => trim((string) ($row['instructor_name'] ?? '')),
                'term_name'         => (string) $row['term_name'],
                'enrollment_status' => (string) $row['enrollment_status'],
                'offering_status'   => (string) $row['offering_status'],
            ] + $standing;

            if (! $current) {
                $result['past'][] = $card;

                continue;
            }

            $result['courses'][] = $card;

            if ($card['tag'] === 'At risk') {
                $atRisk[] = ['kind' => 'at_risk', 'tone' => 'warning', 'offering_id' => $card['offering_id'], 'text' => 'At risk in ' . $card['course_code'] . ': the grade so far is below the passing mark.'];
            }

            if ($card['is_final'] && $finalizedAt !== null && strtotime($finalizedAt) >= $now - self::WINDOW_DAYS * 86400) {
                $final[] = ['kind' => 'final', 'tone' => 'success', 'offering_id' => $card['offering_id'], 'text' => 'Final grade available: ' . $card['course_code'] . '.'];
            }

            $excused = $this->excusedAssessmentIds($studentId, array_column($assessments, 'id'));

            foreach ($assessments as $assessment) {
                $key = $assessment['student']['key'] ?? '';
                $due = $assessment['due_ts'];

                if (in_array($key, ['overdue', 'missed'], true) && ! in_array((int) $assessment['id'], $excused, true)) {
                    $missing[] = ['kind' => 'missing', 'tone' => 'danger', 'offering_id' => $card['offering_id'], 'text' => 'Not handed in: ' . $assessment['title'] . ', ' . $card['course_code'] . ', was due ' . date('M j', (int) $due) . '.'];
                }

                if ($due !== null && $due >= $now && $due <= $now + self::WINDOW_DAYS * 86400) {
                    $deadlines[] = ['title' => (string) $assessment['title'], 'course_code' => $card['course_code'], 'due_ts' => (int) $due, 'status' => (string) $assessment['student']['label']];
                }
            }
        }

        usort($deadlines, static fn (array $a, array $b): int => $a['due_ts'] <=> $b['due_ts']);

        $result['attention'] = array_merge($atRisk, $missing, $final);
        $result['deadlines'] = $deadlines;

        return $result;
    }

    /**
     * The standing of the child in one course, as the student's own Grades tab
     * gives it, plus the child's assessments there and when it was finalized.
     * A course whose grading scheme has not been set up yet has no standing
     * (and none is created by looking at it).
     *
     * @param array<string, mixed> $row
     *
     * @return array{array{percentage: ?float, band: ?string, tag: string, is_final: bool, graded: bool}, list<array<string, mixed>>, ?string}
     */
    private function standing(User $child, array $row): array
    {
        $none = ['percentage' => null, 'band' => null, 'tag' => 'Incomplete', 'is_final' => false, 'graded' => false];

        $offeringId = (int) $row['offering_id'];

        // A student who also holds a staff role is read by the course services as that staff
        // member (instructor-shaped data, and a dean's "active role" lookup would overwrite the
        // parent's own). Their courses are listed, but no grade or assessment is read.
        if (! ParentAccessService::readsAsStudent($child)) {
            return [$none, [], null];
        }

        if ((new GradingSchemeModel())->findForOffering($offeringId) === null) {
            return [$none, [], null];
        }

        $overview = (new AssessmentOverviewService())->build($child, $offeringId, (int) $row['course_id']);
        $page     = (new GradeService())->buildStudentGrades($child, ['id' => $offeringId, 'course_id' => (int) $row['course_id']], $overview);
        $summary  = $page['summary'];

        $percentage = $summary['is_published'] && $summary['overall_percentage'] !== null ? (float) $summary['overall_percentage'] : null;

        $standing = [
            'percentage' => $percentage,
            'band'       => $percentage !== null ? ($summary['grade_label'] ?? null) : null,
            'tag'        => (string) $summary['tag'],
            'is_final'   => (bool) $summary['is_final'],
            'graded'     => $percentage !== null,
        ];

        $record = $summary['is_final'] ? (new GradebookRecordModel())->findForStudent($offeringId, (int) $child->id) : null;

        return [$standing, $overview['assessments'], $record['finalized_at'] ?? null];
    }

    /**
     * Which of these assessments the instructor excused the child from (the
     * gradebook item behind it holds an Excused mark): never "missing".
     *
     * @param list<int|string> $assessmentIds
     *
     * @return list<int>
     */
    private function excusedAssessmentIds(int $studentId, array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return [];
        }

        $rows = db_connect()->table('gradebook_item_scores s')
            ->select('gi.assessment_id')
            ->join('grade_items gi', 'gi.id = s.grade_item_id')
            ->where('s.student_id', $studentId)
            ->where('s.is_missing', 1)
            ->whereIn('gi.assessment_id', array_map('intval', $assessmentIds))
            ->get()->getResultArray();

        return array_map('intval', array_column($rows, 'assessment_id'));
    }
}
