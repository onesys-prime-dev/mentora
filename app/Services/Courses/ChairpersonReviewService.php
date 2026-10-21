<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Services\Audit\AuditLogger;
use App\Services\Auth\ActiveRoleService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Shield\Entities\User;

/**
 * What chairpersons changed in the department's courses, for the dean to look over.
 *
 * The list is the audit entries ChairpersonCourseAudit writes whenever a chairperson changes a
 * course through the chairperson area, limited to the dean's department and to courses the
 * chairperson does not teach themselves (what they change as the instructor is the instructor's
 * own work, and does not come through here). It is review only: nothing is blocked, held or
 * reverted. The dean marks entries as reviewed; the mark is theirs alone and changes nothing about
 * the entry.
 */
class ChairpersonReviewService
{
    public function __construct(
        private readonly DeanAccessService $access = new DeanAccessService(),
        private readonly MonitorProgramService $monitor = new MonitorProgramService(),
    ) {
    }

    /**
     * A page of entries within the filters, newest first, and how many are still to review and already
     * reviewed under the same people and dates (so the tabs can show their numbers).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, counts: array{unreviewed: int, reviewed: int}}
     */
    public function entries(User $dean, ChairpersonReviewFilters $filters, int $page = 1, int $perPage = 15): array
    {
        $courses = $this->courses($dean, $filters->subDepartmentId);
        $counts  = [
            'unreviewed' => $this->scoped($dean, $filters, $courses, 'unreviewed')->countAllResults(),
            'reviewed'   => $this->scoped($dean, $filters, $courses, 'reviewed')->countAllResults(),
        ];

        $total = $filters->view === 'all' ? $counts['unreviewed'] + $counts['reviewed'] : $counts[$filters->view];

        $rows = $this->scoped($dean, $filters, $courses, $filters->view)
            ->select(['a.id', 'a.actor_user_id', 'a.actor_label', 'a.created_at', 'a.target_id', 'a.new_values', 'r.reviewed_at'])
            ->orderBy('a.created_at', 'DESC')->orderBy('a.id', 'DESC')
            ->limit($perPage, max(0, $page - 1) * $perPage)
            ->get()->getResultArray();

        $entries = array_map(static function (array $row) use ($courses): array {
            $course = $courses[(int) $row['target_id']] ?? null;
            $new    = json_decode((string) ($row['new_values'] ?? ''), true);
            $new    = is_array($new) ? $new : [];

            return [
                'id'             => (int) $row['id'],
                'created_at'     => $row['created_at'],
                'actor_id'       => (int) $row['actor_user_id'],
                'actor'          => $row['actor_label'],
                'offering_id'    => (int) $row['target_id'],
                'course_code'    => $course['course_code'] ?? null,
                'section_code'   => $course['section_code'] ?? null,
                'sub_department' => $course['sub_department'] ?? null,
                'slug'           => $course !== null ? url_title((string) $course['course_title'], '-', true) : null,
                'description'    => self::describe($new['method'] ?? null, $new['path'] ?? null),
                'reviewed_at'    => $row['reviewed_at'],
            ];
        }, $rows);

        return ['rows' => $entries, 'total' => $total, 'counts' => $counts];
    }

    /** How many entries this dean has not yet reviewed, for the dashboard. */
    public function pendingCount(User $dean): int
    {
        $filters = new ChairpersonReviewFilters();

        return $this->scoped($dean, $filters, $this->courses($dean, null), 'unreviewed')->countAllResults();
    }

    /**
     * The dean marks entries as reviewed. Only entries of their own department count; any other id is
     * ignored, and one already marked is left as it is.
     *
     * @param list<int> $auditIds
     *
     * @return array{success: bool, message: string, marked: int}
     */
    public function markReviewed(User $dean, array $auditIds): array
    {
        if ((new ActiveRoleService())->resolve($dean) !== 'dean' || $this->access->departmentFor($dean) === null) {
            return ['success' => false, 'message' => 'Only the dean of the department reviews these.', 'marked' => 0];
        }

        $auditIds = array_values(array_unique(array_filter(array_map('intval', $auditIds), static fn (int $id): bool => $id > 0)));

        if ($auditIds === []) {
            return ['success' => false, 'message' => 'Choose what you have reviewed.', 'marked' => 0];
        }

        $filters = new ChairpersonReviewFilters(view: 'unreviewed');
        $found   = $this->scoped($dean, $filters, $this->courses($dean, null), 'unreviewed')
            ->select('a.id')->whereIn('a.id', $auditIds)->get()->getResultArray();

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $found);

        if ($ids === []) {
            return ['success' => false, 'message' => 'Nothing there is left to review.', 'marked' => 0];
        }

        $now = date('Y-m-d H:i:s');

        db_connect()->table('chairperson_action_reviews')->insertBatch(array_map(
            static fn (int $id): array => ['audit_log_id' => $id, 'reviewer_id' => (int) $dean->id, 'reviewed_at' => $now],
            $ids
        ));

        (new AuditLogger())->log('chairperson_review.marked', $dean, [
            'acting_role' => 'dean',
            'target_type' => 'department',
            'target_id'   => (int) $this->access->departmentFor($dean)['department_id'],
            'new'         => ['audit_ids' => $ids],
        ]);

        return ['success' => true, 'message' => count($ids) === 1 ? 'Marked as reviewed.' : count($ids) . ' marked as reviewed.', 'marked' => count($ids)];
    }

    /**
     * The chairpersons of the department's sub-departments, now or before, for the filter.
     *
     * @return list<array{id: int, name: string}>
     */
    public function chairpersons(User $dean): array
    {
        $department = $this->access->departmentFor($dean);

        if ($department === null) {
            return [];
        }

        $rows = db_connect()->table('chairperson_assignments ca')
            ->select(['ca.chairperson_user_id AS id', "TRIM(CONCAT_WS(' ', up.first_name, up.last_name)) AS name"], false)
            ->join('sub_departments sd', 'sd.id = ca.sub_department_id')
            ->join('user_profiles up', 'up.user_id = ca.chairperson_user_id', 'left')
            ->where('sd.department_id', (int) $department['department_id'])
            ->groupBy('ca.chairperson_user_id')->groupBy('up.first_name')->groupBy('up.last_name')
            ->orderBy('up.first_name', 'ASC')->orderBy('up.last_name', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']], $rows);
    }

    /** "Created an announcement", "Updated course content", "Changed grades": what a recorded change was, in words. */
    public static function describe(?string $method, ?string $path): string
    {
        $segments = array_values(array_filter(explode('/', (string) $path), static fn (string $s): bool => $s !== ''));
        $at       = array_search('courses', $segments, true);
        $area     = $at !== false ? ($segments[$at + 2] ?? null) : null;

        if ($area === 'grades') {
            return 'Changed grades';
        }

        $verb = match (strtoupper((string) $method)) {
            'POST'         => 'Created',
            'PATCH', 'PUT' => 'Updated',
            'DELETE'       => 'Deleted',
            default        => 'Changed',
        };

        $thing = match ($area) {
            'announcements'          => 'an announcement',
            'modules', 'units', 'items', 'content' => 'course content',
            'assessments', 'quizzes' => 'an assessment',
            'submissions'            => 'a submission',
            'discussions'            => 'a discussion',
            default                  => 'something in the course',
        };

        return $verb . ' ' . $thing;
    }

    /**
     * The department's courses by offering id, for labels and for the boundary of the list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function courses(User $dean, ?int $subDepartmentId): array
    {
        $rows = $this->monitor->offeringRowsForPrograms($this->access->programIds($dean), new MonitorProgramFilters());
        $by   = [];

        foreach ($rows as $row) {
            if ($subDepartmentId !== null && (int) $row['sub_department_id'] !== $subDepartmentId) {
                continue;
            }

            $by[(int) $row['offering_id']] = $row;
        }

        if ($by !== []) {
            $names = db_connect()->table('sub_departments')->select(['id', 'name'])->whereIn('id', array_unique(array_map(static fn (array $r): int => (int) $r['sub_department_id'], $by)))->get()->getResultArray();
            $names = array_column($names, 'name', 'id');

            foreach ($by as &$row) {
                $row['sub_department'] = $names[$row['sub_department_id']] ?? null;
            }
            unset($row);
        }

        return $by;
    }

    /**
     * Chairperson actions in these courses that the chairperson did not take as the course's own instructor,
     * within the people and dates of the filters and the review state asked for.
     *
     * @param array<int, array<string, mixed>> $courses
     */
    private function scoped(User $dean, ChairpersonReviewFilters $filters, array $courses, string $view): BaseBuilder
    {
        $builder = db_connect()->table('audit_logs a')
            ->join('chairperson_action_reviews r', 'r.audit_log_id = a.id AND r.reviewer_id = ' . (int) $dean->id, 'left')
            ->where('a.action', 'course.chairperson_action');

        // No courses (no department, or none in it) reaches nothing: an empty IN () is an error, not "none".
        if ($courses === []) {
            return $builder->where('1 = 0', null, false);
        }

        $builder->whereIn('a.target_id', array_map('strval', array_keys($courses)))
            ->where('NOT EXISTS (SELECT 1 FROM instructor_assignments ia WHERE ia.offering_id = CAST(a.target_id AS UNSIGNED) AND ia.instructor_user_id = a.actor_user_id)', null, false);

        if ($filters->chairpersonId !== null) {
            $builder->where('a.actor_user_id', $filters->chairpersonId);
        }

        if ($filters->fromTimestamp() !== null) {
            $builder->where('a.created_at >=', $filters->fromTimestamp());
        }

        if ($filters->untilTimestamp() !== null) {
            $builder->where('a.created_at <', $filters->untilTimestamp());
        }

        if ($view === 'unreviewed') {
            $builder->where('r.id IS NULL', null, false);
        } elseif ($view === 'reviewed') {
            $builder->where('r.id IS NOT NULL', null, false);
        }

        return $builder;
    }
}
