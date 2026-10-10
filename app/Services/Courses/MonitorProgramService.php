<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Models\Assignments\ChairpersonAssignmentModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Assessments\GradebookRecordModel;
use App\Models\Audit\AuditLogModel;
use App\Models\Courses\AnnouncementModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Grades\GradingSchemeModel;
use App\Models\Learning\CourseModuleModel;
use CodeIgniter\Shield\Entities\User;

/**
 * R5 of the chairperson plan: everything Monitor Program shows about how a
 * chaired program is performing — as opposed to the dashboard's "what needs
 * attention now". Each metric is its own method, scoped by a plain list of
 * offering ids, so it can be unit-tested with fixtures independently of the
 * other metrics and of how that list of ids was produced.
 *
 * Grade distribution / average only ever counts an offering whose grading
 * scheme is Published — same publish-gating rule GradeService follows, so
 * Monitor Program never shows a number the course's own Grades page
 * wouldn't.
 */
class MonitorProgramService
{
    /** Grade distribution buckets, worst first. */
    public const GRADE_BUCKETS = [
        '0-59' => [0, 59],
        '60-69' => [60, 69],
        '70-79' => [70, 79],
        '80-89' => [80, 89],
        '90-100' => [90, 100],
    ];

    protected ChairpersonAssignmentModel $assignmentModel;

    protected EnrollmentModel $enrollmentModel;

    protected CourseModuleModel $courseModuleModel;

    protected GradingSchemeModel $gradingSchemeModel;

    protected GradebookRecordModel $gradebookRecordModel;

    protected AssessmentSubmissionModel $submissionModel;

    protected AnnouncementModel $announcementModel;

    protected AuditLogModel $auditLogModel;

    protected RosterRiskService $riskService;

    public function __construct()
    {
        $this->assignmentModel = new ChairpersonAssignmentModel();
        $this->enrollmentModel = new EnrollmentModel();
        $this->courseModuleModel = new CourseModuleModel();
        $this->gradingSchemeModel = new GradingSchemeModel();
        $this->gradebookRecordModel = new GradebookRecordModel();
        $this->submissionModel = new AssessmentSubmissionModel();
        $this->announcementModel = new AnnouncementModel();
        $this->auditLogModel = new AuditLogModel();
        $this->riskService = new RosterRiskService();
    }

    /**
     * Every offering across every sub-department this user chairs, narrowed
     * to one term when the filter asks for it. The full joined row shape
     * from ChairpersonAssignmentModel::chairedOfferingsBuilder() — the same
     * one the Courses page reads — so course/program/instructor labels are
     * already attached.
     */
    public function offeringRows(User $user, MonitorProgramFilters $filters): array
    {
        $builder = $this->assignmentModel->chairedOfferingsBuilder($user->id, null);

        if ($filters->termId !== null) {
            $builder->where('academic_terms.id', $filters->termId);
        }

        return $builder->orderBy('courses.course_code', 'ASC')->get()->getResultArray();
    }

    /**
     * Program snapshot: courses, distinct instructors, enrolled students —
     * the same three numbers the dashboard widget shows, but for the whole
     * (optionally term-filtered) program rather than the dashboard's
     * always-active set.
     */
    public function snapshot(array $offeringRows): array
    {
        return [
            'courses' => count($offeringRows),
            'instructors' => count(array_unique(array_filter(array_column($offeringRows, 'instructor_id')))),
            'students' => array_sum(array_map(static fn (array $row) => (int) $row['enrolled'], $offeringRows)),
        ];
    }

    /**
     * Per-course enrollment and completion: how many are enrolled, and the
     * average percentage of published items the class has completed. An
     * offering with nothing published yet shows null rather than 0%, since
     * 0 of 0 isn't "behind" — same rule RosterRiskService uses.
     *
     * @param  list<int> $offeringIds
     * @return array<int, array{offering_id: int, enrolled: int, completion_percentage: ?float}>
     */
    public function enrollmentAndCompletion(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $itemTotals = [];

        foreach ($this->courseModuleModel->retrieveModuleProgress($offeringIds) as $module) {
            $offeringId = (int) $module['offering_id'];
            $itemTotals[$offeringId] = ($itemTotals[$offeringId] ?? 0) + (int) $module['item_count'];
        }

        $completed = [];
        $enrolled = [];

        foreach ($this->enrollmentModel->retrieveRosterProgress($offeringIds) as $row) {
            $offeringId = (int) $row['offering_id'];
            $enrolled[$offeringId] = ($enrolled[$offeringId] ?? 0) + 1;
            $completed[$offeringId] = ($completed[$offeringId] ?? 0) + (int) $row['completed_count'];
        }

        $result = [];

        foreach ($offeringIds as $offeringId) {

            $total = $itemTotals[$offeringId] ?? 0;
            $enrolledCount = $enrolled[$offeringId] ?? 0;

            $percentage = ($total === 0 || $enrolledCount === 0)
                ? null
                : round(($completed[$offeringId] ?? 0) / ($total * $enrolledCount) * 100, 1);

            $result[$offeringId] = [
                'offering_id' => $offeringId,
                'enrolled' => $enrolledCount,
                'completion_percentage' => $percentage,
            ];

        }

        return $result;
    }

    /**
     * Per-course grade average, distribution across GRADE_BUCKETS and
     * grading progress (how many enrolled students have an effective
     * percentage yet). Only offerings with a Published scheme are included
     * at all — an unpublished scheme has no grade worth showing.
     *
     * @param  list<int> $offeringIds
     * @return array<int, array{offering_id: int, average_percentage: ?float, distribution: array<string, int>, graded_count: int, enrolled: int}>
     */
    public function grades(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $publishedIds = $this->gradingSchemeModel->retrievePublishedOfferingIds($offeringIds);

        if ($publishedIds === []) {
            return [];
        }

        $percentagesByOffering = $this->gradebookRecordModel->retrievePercentagesByOffering($publishedIds);

        $enrolledCounts = [];

        foreach ($this->enrollmentModel->retrieveRosterProgress($publishedIds) as $row) {
            $offeringId = (int) $row['offering_id'];
            $enrolledCounts[$offeringId] = ($enrolledCounts[$offeringId] ?? 0) + 1;
        }

        $result = [];

        foreach ($publishedIds as $offeringId) {

            $percentages = $percentagesByOffering[$offeringId] ?? [];

            $distribution = array_fill_keys(array_keys(self::GRADE_BUCKETS), 0);

            foreach ($percentages as $percentage) {
                foreach (self::GRADE_BUCKETS as $label => [$low, $high]) {
                    if ($percentage >= $low && $percentage <= $high) {
                        $distribution[$label]++;
                        break;
                    }
                }
            }

            $result[$offeringId] = [
                'offering_id' => $offeringId,
                'average_percentage' => $percentages === [] ? null : round(array_sum($percentages) / count($percentages), 1),
                'distribution' => $distribution,
                'graded_count' => count($percentages),
                'enrolled' => $enrolledCounts[$offeringId] ?? 0,
            ];

        }

        return $result;
    }

    /**
     * Per-course instructor activity: average grading turnaround (hours,
     * submitted -> graded), content updates and announcements — each
     * optionally narrowed to [$filters->fromTimestamp(), $filters->untilTimestamp()).
     *
     * @param  list<int> $offeringIds
     * @return array<int, array{offering_id: int, avg_grading_turnaround_hours: ?float, graded_count: int, content_updates: int, announcements: int}>
     */
    public function instructorActivity(array $offeringIds, MonitorProgramFilters $filters): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $from = $filters->fromTimestamp();
        $until = $filters->untilTimestamp();

        $turnaround = $this->submissionModel->retrieveGradingTurnaroundByOffering($offeringIds, $from, $until);
        $contentUpdates = $this->courseModuleModel->retrieveUpdateCountsByOffering($offeringIds, $from, $until);
        $announcements = $this->announcementModel->retrieveCountsByOffering($offeringIds, $from, $until);

        $result = [];

        foreach ($offeringIds as $offeringId) {
            $result[$offeringId] = [
                'offering_id' => $offeringId,
                'avg_grading_turnaround_hours' => $turnaround[$offeringId]['avg_hours'] ?? null,
                'graded_count' => $turnaround[$offeringId]['graded_count'] ?? 0,
                'content_updates' => $contentUpdates[$offeringId] ?? 0,
                'announcements' => $announcements[$offeringId] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * At-risk students across these offerings — delegates to
     * RosterRiskService so this can never disagree with the dashboard
     * widget or the course Overview about who counts as at risk.
     *
     * @param  list<int> $offeringIds
     */
    public function atRisk(array $offeringIds): array
    {
        return $this->riskService->atRisk($offeringIds);
    }

    /**
     * The sortable course-comparison table: one row per offering, combining
     * its label with enrollment/completion, grade average and instructor
     * activity. Rows for an offering with no grade data yet still appear,
     * just with a null average rather than being dropped.
     *
     * @param  list<array<string, mixed>> $offeringRows the output of offeringRows()
     */
    public function courseComparison(array $offeringRows, MonitorProgramFilters $filters, string $sort = 'course_code', string $dir = 'asc'): array
    {
        $offeringIds = array_map(static fn (array $row) => (int) $row['offering_id'], $offeringRows);

        $completion = $this->enrollmentAndCompletion($offeringIds);
        $grades = $this->grades($offeringIds);
        $activity = $this->instructorActivity($offeringIds, $filters);

        $rows = array_map(static function (array $row) use ($completion, $grades, $activity) {

            $offeringId = (int) $row['offering_id'];

            return [
                'offering_id' => $offeringId,
                'course_code' => $row['course_code'],
                'course_title' => $row['course_title'],
                'section_code' => $row['section_code'],
                'instructor_name' => trim((string) $row['instructor_name']) !== '' ? $row['instructor_name'] : null,
                'enrolled' => $completion[$offeringId]['enrolled'] ?? (int) $row['enrolled'],
                'completion_percentage' => $completion[$offeringId]['completion_percentage'] ?? null,
                'average_percentage' => $grades[$offeringId]['average_percentage'] ?? null,
                'avg_grading_turnaround_hours' => $activity[$offeringId]['avg_grading_turnaround_hours'] ?? null,
            ];

        }, $offeringRows);

        $sortable = ['course_code', 'enrolled', 'completion_percentage', 'average_percentage', 'avg_grading_turnaround_hours'];

        $sortKey = in_array($sort, $sortable, true) ? $sort : 'course_code';

        usort($rows, static function (array $a, array $b) use ($sortKey, $dir) {

            $valueA = $a[$sortKey];
            $valueB = $b[$sortKey];

            // Nulls (no data yet) always sort last, regardless of direction.
            if ($valueA === null && $valueB === null) {
                return 0;
            }
            if ($valueA === null) {
                return 1;
            }
            if ($valueB === null) {
                return -1;
            }

            $result = $valueA <=> $valueB;

            return $dir === 'desc' ? -$result : $result;

        });

        return $rows;
    }

    /**
     * The audit log viewer: this chairperson's own chairperson-scoped
     * actions, newest first, within the filtered date range. Only
     * course.chairperson_action entries — the ones ChairpersonCourseAudit
     * writes — so a chairperson's ordinary instructor-parity mutations show
     * up here the same way they do on the dashboard's Audit Highlights
     * widget, and nothing outside that acting-role context leaks in.
     *
     * @param  list<array<string, mixed>> $offeringRows the output of offeringRows(), for course labels
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function auditLog(User $user, array $offeringRows, MonitorProgramFilters $filters, int $page = 1, int $perPage = 10): array
    {
        $query = $this->auditLogModel
            ->where('actor_user_id', $user->id)
            ->where('action', 'course.chairperson_action');

        $from = $filters->fromTimestamp();
        $until = $filters->untilTimestamp();

        if ($from !== null) {
            $query = $query->where('created_at >=', $from);
        }

        if ($until !== null) {
            $query = $query->where('created_at <', $until);
        }

        $total = $query->countAllResults(false);

        $rows = $query->orderBy('created_at', 'DESC')
            ->limit($perPage, max(0, $page - 1) * $perPage)
            ->find();

        $offeringsById = [];

        foreach ($offeringRows as $row) {
            $offeringsById[(int) $row['offering_id']] = $row;
        }

        $entries = array_map(function (array $row) use ($offeringsById) {

            $offeringId = $row['target_id'] !== null ? (int) $row['target_id'] : null;
            $offering = $offeringId !== null ? ($offeringsById[$offeringId] ?? null) : null;

            $new = json_decode((string) ($row['new_values'] ?? ''), true);
            $method = is_array($new) ? ($new['method'] ?? null) : null;

            return [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'],
                'acting_role' => $row['acting_role'],
                'offering_id' => $offeringId,
                'course_code' => $offering['course_code'] ?? null,
                'section_code' => $offering['section_code'] ?? null,
                'slug' => $offering !== null ? url_title((string) $offering['course_title'], '-', true) : null,
                'description' => self::describeAuditMethod($method),
            ];

        }, $rows);

        return ['rows' => $entries, 'total' => $total];
    }

    /** "Created something" / "Updated something" / "Deleted something" / "Made a change", from the HTTP method a mutation used. */
    private static function describeAuditMethod(?string $method): string
    {
        return match ($method) {
            'POST' => 'Created something',
            'PATCH', 'PUT' => 'Updated something',
            'DELETE' => 'Deleted something',
            default => 'Made a change',
        };
    }
}
