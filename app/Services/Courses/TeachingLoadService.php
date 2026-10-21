<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Services\Institutions\AcademicDefaults;
use CodeIgniter\Shield\Entities\User;

/**
 * What each instructor of the dean's department carries in a term: sections, students, units, work waiting
 * to be graded and how fast they grade, with a marker for a high load. View only.
 *
 * Only the sections of the dean's department count, so an instructor who also teaches elsewhere appears
 * here with the load they carry here, and a dean sees nobody else's. A section with two instructors counts
 * for both. The high-load line is the institution's own setting (AcademicDefaults), like the other shared
 * thresholds. Turnaround is the monitoring service's per-section figure, weighted by the work graded.
 */
class TeachingLoadService
{
    private const ENROLLED = ['enrolled', 'completed'];

    public function __construct(
        private readonly DeanAccessService $access = new DeanAccessService(),
        private readonly MonitorProgramService $monitor = new MonitorProgramService(),
        private readonly AcademicDefaults $defaults = new AcademicDefaults(),
    ) {
    }

    /**
     * The terms the department's courses run in, newest first, for the term filter.
     *
     * @return list<array<string, mixed>>
     */
    public function terms(User $dean): array
    {
        return $this->monitor->termsForPrograms($this->access->programIds($dean));
    }

    /**
     * The term the page opens on: the one asked for if it is the department's, else the newest.
     */
    public function termFor(User $dean, ?int $asked): ?int
    {
        $terms = $this->terms($dean);
        $known = array_map(static fn (array $t): int => (int) $t['id'], $terms);

        return $asked !== null && in_array($asked, $known, true) ? $asked : ($known[0] ?? null);
    }

    /**
     * One row per instructor of the department for the term, sorted.
     *
     * @return array{term: ?int, highLoadUnits: int, rows: list<array<string, mixed>>}
     */
    public function load(User $dean, TeachingLoadFilters $filters): array
    {
        $term      = $this->termFor($dean, $filters->termId);
        $threshold = $this->threshold($dean);

        if ($term === null) {
            return ['term' => null, 'highLoadUnits' => $threshold, 'rows' => []];
        }

        $sections = $this->sections($dean, $term, $filters->subDepartmentId);
        $rows     = array_map(fn (array $group): array => $this->summarise($group, $threshold), $this->byInstructor($sections));

        return ['term' => $term, 'highLoadUnits' => $threshold, 'rows' => RowSort::by(array_values($rows), $filters->sort, $filters->dir)];
    }

    /**
     * One instructor's sections in the department for the term, or null when they teach none of the
     * department's (so an id cannot be guessed into somebody else's load).
     *
     * @return array{instructor: array<string, mixed>, term: ?int, highLoadUnits: int, sections: list<array<string, mixed>>}|null
     */
    public function instructor(User $dean, int $instructorId, ?int $termId): ?array
    {
        $term = $this->termFor($dean, $termId);

        if ($term === null) {
            return null;
        }

        $groups = $this->byInstructor($this->sections($dean, $term, null));
        $group  = $groups[$instructorId] ?? null;

        if ($group === null) {
            return null;
        }

        $threshold = $this->threshold($dean);

        return [
            'instructor'    => $this->summarise($group, $threshold),
            'term'          => $term,
            'highLoadUnits' => $threshold,
            'sections'      => $group['sections'],
        ];
    }

    /** The instructors of the department in any term, for telling "teaches nothing this term" from "not ours". */
    public function teachesInDepartment(User $dean, int $instructorId): bool
    {
        $programIds = $this->access->programIds($dean);

        if ($programIds === []) {
            return false;
        }

        return db_connect()->table('instructor_assignments ia')
            ->join('course_offerings co', 'co.id = ia.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->where('ia.instructor_user_id', $instructorId)
            ->whereIn('c.program_id', $programIds)
            ->countAllResults() > 0;
    }

    /**
     * Every section of the department in a term that has an instructor, one entry per instructor on it, with
     * the section's own figures.
     *
     * @return list<array<string, mixed>>
     */
    private function sections(User $dean, int $termId, ?int $subDepartmentId): array
    {
        $programIds = $this->access->programIds($dean);
        $rows       = $this->monitor->offeringRowsForPrograms($programIds, new MonitorProgramFilters(termId: $termId));

        if ($subDepartmentId !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['sub_department_id'] === $subDepartmentId));
        }

        $offeringIds = array_map(static fn (array $r): int => (int) $r['offering_id'], $rows);

        if ($offeringIds === []) {
            return [];
        }

        $db = db_connect();

        $teachers = $db->table('instructor_assignments ia')
            ->select(['ia.offering_id', 'ia.instructor_user_id', "TRIM(CONCAT_WS(' ', up.first_name, up.last_name)) AS name"], false)
            ->join('user_profiles up', 'up.user_id = ia.instructor_user_id', 'left')
            ->whereIn('ia.offering_id', $offeringIds)
            ->get()->getResultArray();

        $units = array_column($db->table('course_offerings co')->select(['co.id', 'c.units'])->join('courses c', 'c.id = co.course_id')->whereIn('co.id', $offeringIds)->get()->getResultArray(), 'units', 'id');

        $students = [];

        foreach ($db->table('enrollments')->select(['offering_id', 'student_user_id'])->whereIn('offering_id', $offeringIds)->whereIn('status', self::ENROLLED)->get()->getResultArray() as $e) {
            $students[(int) $e['offering_id']][] = (int) $e['student_user_id'];
        }

        $waiting = array_column($db->table('assessment_submissions s')
            ->select(['cm.offering_id', 'COUNT(*) AS waiting'], false)
            ->join('assessments a', 'a.id = s.assessment_id')
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->whereIn('cm.offering_id', $offeringIds)
            ->where('s.submitted_at IS NOT NULL', null, false)
            ->where('s.is_graded', 0)
            ->groupBy('cm.offering_id')
            ->get()->getResultArray(), 'waiting', 'offering_id');

        $activity = $this->monitor->instructorActivity($offeringIds, new MonitorProgramFilters());
        $byOffering = [];

        foreach ($rows as $r) {
            $byOffering[(int) $r['offering_id']] = $r;
        }

        $sections = [];

        foreach ($teachers as $t) {
            $id  = (int) $t['offering_id'];
            $r   = $byOffering[$id];
            $act = $activity[$id] ?? null;

            $sections[] = [
                'instructor_id'  => (int) $t['instructor_user_id'],
                'instructor'     => trim((string) $t['name']) !== '' ? trim((string) $t['name']) : 'Unnamed instructor',
                'offering_id'    => $id,
                'course_code'    => $r['course_code'],
                'section_code'   => $r['section_code'],
                'course_title'   => $r['course_title'],
                'slug'           => url_title((string) $r['course_title'], '-', true),
                'student_ids'    => $students[$id] ?? [],
                'units'          => (float) ($units[$id] ?? 0),
                'ungraded'       => (int) ($waiting[$id] ?? 0),
                'graded_count'   => (int) ($act['graded_count'] ?? 0),
                'turnaround'     => $act['avg_grading_turnaround_hours'] ?? null,
            ];
        }

        return $sections;
    }

    /**
     * @param list<array<string, mixed>> $sections
     *
     * @return array<int, array{id: int, name: string, sections: list<array<string, mixed>>}>
     */
    private function byInstructor(array $sections): array
    {
        $groups = [];

        foreach ($sections as $s) {
            $id = (int) $s['instructor_id'];

            $groups[$id] ??= ['id' => $id, 'name' => (string) $s['instructor'], 'sections' => []];
            $groups[$id]['sections'][] = $s;
        }

        return $groups;
    }

    /**
     * @param array{id: int, name: string, sections: list<array<string, mixed>>} $group
     *
     * @return array<string, mixed>
     */
    private function summarise(array $group, int $threshold): array
    {
        $students = [];
        $units    = 0.0;
        $ungraded = 0;
        $graded   = 0;
        $weighted = 0.0;

        foreach ($group['sections'] as $s) {
            // A student in two of the same instructor's sections is one student.
            foreach ($s['student_ids'] as $studentId) {
                $students[$studentId] = true;
            }

            $units    += $s['units'];
            $ungraded += $s['ungraded'];

            if ($s['turnaround'] !== null && $s['graded_count'] > 0) {
                $graded   += $s['graded_count'];
                $weighted += $s['turnaround'] * $s['graded_count'];
            }
        }

        return [
            'id'         => $group['id'],
            'name'       => $group['name'],
            'sections'   => count($group['sections']),
            'students'   => count($students),
            'units'      => $units,
            'ungraded'   => $ungraded,
            'turnaround' => $graded === 0 ? null : round($weighted / $graded, 1),
            'high_load'  => $units >= $threshold,
        ];
    }

    private function threshold(User $dean): int
    {
        $department = $this->access->departmentFor($dean);

        if ($department === null) {
            return AcademicDefaults::PLATFORM_HIGH_LOAD_UNITS;
        }

        $institution = db_connect()->table('departments')->select('institution_id')->where('id', (int) $department['department_id'])->get()->getRow('institution_id');

        return $institution === null ? AcademicDefaults::PLATFORM_HIGH_LOAD_UNITS : $this->defaults->highLoadUnitsFor((int) $institution);
    }
}
