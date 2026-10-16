<?php

declare(strict_types=1);

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

/**
 * Monitor Department: the chairperson's Monitor Program numbers for a whole
 * department, compared per sub-department and per program.
 *
 * Nothing here recalculates a metric: the offerings, completion, grades,
 * grading turnaround and at-risk list come from MonitorProgramService (the same
 * calculations the chairperson's page and the institution's reports use), over
 * the programs under the dean's department. This class only groups them. Every
 * average is weighted by what it averages (students, graded submissions), so a
 * group's figure is the figure over all its people and work, not an average of
 * averages. Two columns are new here: grading progress (work graded out of work
 * submitted) and last activity (the latest content update, announcement or
 * grading).
 */
class MonitorDepartmentService
{
    public function __construct(
        private readonly MonitorProgramService $monitor = new MonitorProgramService(),
        private readonly DeanAccessService $access = new DeanAccessService(),
    ) {
    }

    /**
     * The programs in scope: the dean's department, narrowed by the sub-department and
     * program filters. A program or sub-department outside the department narrows to
     * nothing, never to someone else's data.
     *
     * @return list<int>
     */
    public function programIds(User $user, MonitorDepartmentFilters $filters): array
    {
        $ids = $this->access->programIds($user);

        if ($filters->programId !== null) {
            $ids = in_array($filters->programId, $ids, true) ? [$filters->programId] : [];
        }

        if ($filters->subDepartmentId !== null && $ids !== []) {
            $ids = array_map('intval', array_column(
                db_connect()->table('programs')->select('id')->where('sub_department_id', $filters->subDepartmentId)->whereIn('id', $ids)->get()->getResultArray(),
                'id'
            ));
        }

        return $ids;
    }

    /**
     * The terms the department's courses run in, for the term filter.
     *
     * @return list<array<string, mixed>>
     */
    public function terms(User $user): array
    {
        return $this->monitor->termsForPrograms($this->access->programIds($user));
    }

    /**
     * Everything the page shows.
     *
     * @return array{
     *     totals: array<string, mixed>,
     *     groups: list<array<string, mixed>>,
     *     gradeDistribution: array<string, int>,
     *     atRisk: list<array<string, mixed>>,
     * }
     */
    public function overview(User $user, MonitorDepartmentFilters $filters): array
    {
        $programIds   = $this->programIds($user, $filters);
        $offeringRows = $this->monitor->offeringRowsForPrograms($programIds, $filters->base);
        $offeringIds  = array_map(static fn (array $row): int => (int) $row['offering_id'], $offeringRows);

        $facts = [
            'completion' => $this->monitor->enrollmentAndCompletion($offeringIds),
            'grades'     => $this->monitor->grades($offeringIds),
            'activity'   => $this->monitor->instructorActivity($offeringIds, $filters->base),
            'counts'     => $this->gradingCounts($offeringIds, $filters->base),
            'last'       => $this->lastActivity($offeringIds),
        ];

        $atRisk = $this->monitor->atRisk($offeringIds);

        $facts['atRiskByOffering'] = [];

        foreach ($atRisk as $student) {
            $facts['atRiskByOffering'][(int) $student['offering_id']] = ($facts['atRiskByOffering'][(int) $student['offering_id']] ?? 0) + 1;
        }

        $distribution = array_fill_keys(array_keys(MonitorProgramService::GRADE_BUCKETS), 0);

        foreach ($facts['grades'] as $grade) {
            foreach ($grade['distribution'] as $bucket => $count) {
                $distribution[$bucket] += $count;
            }
        }

        $labels = [];

        foreach ($offeringRows as $row) {
            $labels[(int) $row['offering_id']] = ['code' => $row['course_code'], 'section' => $row['section_code'], 'title' => $row['course_title']];
        }

        foreach ($atRisk as &$student) {
            $label                  = $labels[(int) $student['offering_id']] ?? [];
            $student['course_code']  = $label['code'] ?? null;
            $student['section_code'] = $label['section'] ?? null;
            $student['slug']         = isset($label['title']) ? url_title((string) $label['title'], '-', true) : null;
        }
        unset($student);

        return [
            'totals'            => ['name' => 'Department'] + $this->metrics($offeringRows, $facts),
            'groups'            => $this->groups($user, $filters, $programIds, $offeringRows, $facts),
            'gradeDistribution' => $distribution,
            'atRisk'            => $atRisk,
        ];
    }

    /**
     * The comparison rows for the chosen grouping, sorted. Every sub-department or program in
     * scope is a row, including one with no courses yet (zeros and dashes, not a missing row).
     *
     * @param list<int>                $programIds
     * @param list<array<string, mixed>> $offeringRows
     * @param array<string, mixed>     $facts
     *
     * @return list<array<string, mixed>>
     */
    private function groups(User $user, MonitorDepartmentFilters $filters, array $programIds, array $offeringRows, array $facts): array
    {
        $db = db_connect();

        if ($filters->compare === 'program') {
            $definitions = $programIds === [] ? [] : $db->table('programs p')
                ->select(['p.id', 'p.name', 'sd.name AS context'])
                ->join('sub_departments sd', 'sd.id = p.sub_department_id')
                ->whereIn('p.id', $programIds)->get()->getResultArray();
            $key = 'program_id';
        } else {
            $department  = $this->access->departmentFor($user);
            $definitions = $department === null ? [] : $db->table('sub_departments')
                ->select(['id', 'name'])
                ->where('department_id', (int) $department['department_id'])
                ->get()->getResultArray();

            // Narrowed by a sub-department or a program: only the sub-departments that hold what is in scope.
            if ($filters->subDepartmentId !== null) {
                $definitions = array_filter($definitions, static fn (array $d): bool => (int) $d['id'] === $filters->subDepartmentId);
            } elseif ($filters->programId !== null) {
                $holding     = array_unique(array_map('intval', array_column($offeringRows, 'sub_department_id')));
                $inProgram   = $programIds === [] ? [] : array_map('intval', array_column($db->table('programs')->select('sub_department_id')->whereIn('id', $programIds)->get()->getResultArray(), 'sub_department_id'));
                $definitions = array_filter($definitions, static fn (array $d): bool => in_array((int) $d['id'], array_merge($holding, $inProgram), true));
            }

            $key = 'sub_department_id';
        }

        $rows = [];

        foreach ($definitions as $definition) {
            $members = array_values(array_filter($offeringRows, static fn (array $row): bool => (int) $row[$key] === (int) $definition['id']));

            $rows[] = ['id' => (int) $definition['id'], 'name' => (string) $definition['name'], 'context' => $definition['context'] ?? null] + $this->metrics($members, $facts);
        }

        return $this->sorted($rows, $filters->sort, $filters->dir);
    }

    /**
     * One group's numbers, from its offerings. Averages are weighted by what they average.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $facts
     *
     * @return array<string, mixed>
     */
    private function metrics(array $rows, array $facts): array
    {
        $students = 0;
        $instructors = [];
        $completionSum = 0.0;
        $completionWeight = 0;
        $gradeSum = 0.0;
        $gradeWeight = 0;
        $turnaroundSum = 0.0;
        $turnaroundWeight = 0;
        $submitted = 0;
        $graded = 0;
        $atRisk = 0;
        $last = null;

        foreach ($rows as $row) {
            $id = (int) $row['offering_id'];

            $students += (int) ($facts['completion'][$id]['enrolled'] ?? $row['enrolled']);

            if (! empty($row['instructor_id'])) {
                $instructors[(int) $row['instructor_id']] = true;
            }

            $completion = $facts['completion'][$id] ?? null;

            if ($completion !== null && $completion['completion_percentage'] !== null && $completion['enrolled'] > 0) {
                $completionSum    += $completion['completion_percentage'] * $completion['enrolled'];
                $completionWeight += $completion['enrolled'];
            }

            $grade = $facts['grades'][$id] ?? null;

            if ($grade !== null && $grade['average_percentage'] !== null && $grade['graded_count'] > 0) {
                $gradeSum    += $grade['average_percentage'] * $grade['graded_count'];
                $gradeWeight += $grade['graded_count'];
            }

            $activity = $facts['activity'][$id] ?? null;

            if ($activity !== null && $activity['avg_grading_turnaround_hours'] !== null && $activity['graded_count'] > 0) {
                $turnaroundSum    += $activity['avg_grading_turnaround_hours'] * $activity['graded_count'];
                $turnaroundWeight += $activity['graded_count'];
            }

            $submitted += $facts['counts'][$id]['submitted'] ?? 0;
            $graded    += $facts['counts'][$id]['graded'] ?? 0;
            $atRisk    += $facts['atRiskByOffering'][$id] ?? 0;

            $when = $facts['last'][$id] ?? null;

            if ($when !== null && ($last === null || $when > $last)) {
                $last = $when;
            }
        }

        return [
            'courses'          => count($rows),
            'instructors'      => count($instructors),
            'students'         => $students,
            'completion'       => $completionWeight === 0 ? null : round($completionSum / $completionWeight, 1),
            'average_grade'    => $gradeWeight === 0 ? null : round($gradeSum / $gradeWeight, 1),
            'grading_progress' => $submitted === 0 ? null : round($graded / $submitted * 100, 1),
            'turnaround'       => $turnaroundWeight === 0 ? null : round($turnaroundSum / $turnaroundWeight, 1),
            'at_risk'          => $atRisk,
            'last_activity'    => $last,
        ];
    }

    /**
     * Sorted by a column; a missing value (no data yet) always sorts last whatever the direction,
     * and equal values keep name order, so the order never jumps between reloads.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows, string $sort, string $dir): array
    {
        usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
            $left  = $a[$sort] ?? null;
            $right = $b[$sort] ?? null;

            if ($left === null && $right !== null) {
                return 1;
            }

            if ($right === null && $left !== null) {
                return -1;
            }

            $result = $left === $right ? 0 : ($left <=> $right);
            $result = $dir === 'desc' ? -$result : $result;

            return $result !== 0 ? $result : strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $rows;
    }

    /**
     * Work submitted and work graded, per offering, for what was submitted in the date range.
     *
     * @param list<int> $offeringIds
     *
     * @return array<int, array{submitted: int, graded: int}>
     */
    private function gradingCounts(array $offeringIds, MonitorProgramFilters $filters): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $builder = db_connect()->table('assessment_submissions s')
            ->select(['cm.offering_id', 'COUNT(*) AS submitted', 'SUM(CASE WHEN s.is_graded = 1 THEN 1 ELSE 0 END) AS graded'], false)
            ->join('assessments a', 'a.id = s.assessment_id')
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->whereIn('cm.offering_id', $offeringIds)
            ->where('s.submitted_at IS NOT NULL', null, false);

        if ($filters->fromTimestamp() !== null) {
            $builder->where('s.submitted_at >=', $filters->fromTimestamp());
        }

        if ($filters->untilTimestamp() !== null) {
            $builder->where('s.submitted_at <', $filters->untilTimestamp());
        }

        $counts = [];

        foreach ($builder->groupBy('cm.offering_id')->get()->getResultArray() as $row) {
            $counts[(int) $row['offering_id']] = ['submitted' => (int) $row['submitted'], 'graded' => (int) $row['graded']];
        }

        return $counts;
    }

    /**
     * The latest content update, announcement or grading of each offering (not limited to the date range).
     *
     * @param list<int> $offeringIds
     *
     * @return array<int, string>
     */
    private function lastActivity(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $db   = db_connect();
        $last = [];

        $sources = [
            $db->table('course_modules')->select(['offering_id', 'MAX(updated_at) AS at'])->whereIn('offering_id', $offeringIds)->groupBy('offering_id'),
            $db->table('announcements')->select(['offering_id', 'MAX(created_at) AS at'])->whereIn('offering_id', $offeringIds)->groupBy('offering_id'),
            $db->table('assessment_submissions s')->select(['cm.offering_id', 'MAX(s.graded_at) AS at'], false)
                ->join('assessments a', 'a.id = s.assessment_id')->join('course_modules cm', 'cm.id = a.module_id')
                ->whereIn('cm.offering_id', $offeringIds)->groupBy('cm.offering_id'),
        ];

        foreach ($sources as $query) {
            foreach ($query->get()->getResultArray() as $row) {
                $at = $row['at'];

                if ($at !== null && ($last[(int) $row['offering_id']] ?? '') < $at) {
                    $last[(int) $row['offering_id']] = $at;
                }
            }
        }

        return $last;
    }
}
