<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Courses\MonitorProgramFilters;
use App\Services\Courses\MonitorProgramService;
use App\Services\Users\UserExport;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;

/**
 * The institution's reports: how its courses are doing, across everything or
 * narrowed to a term, a department or a program, and down to one course.
 *
 * Nothing here is calculated again: it is Monitor Program (the chairperson's
 * analytics) over the set of programs in scope, so the institutional
 * administrator, the chairperson and the dashboards always read the same
 * numbers, with the same thresholds (the institution's academic defaults).
 *
 * Scope is the institution's own and decided here: a department or program
 * that is not the institution's is a 404, never an empty page, and a term that
 * is not its own simply matches nothing.
 */
class InstitutionReports
{
    /** The exports, and the file each one is called. */
    public const EXPORTS = [
        'courses'  => 'Course comparison',
        'at-risk'  => 'At-risk students',
        'grades'   => 'Grades and grading',
        'activity' => 'Instructor activity',
    ];

    private MonitorProgramService $monitor;

    public function __construct(?MonitorProgramService $monitor = null)
    {
        $this->monitor = $monitor ?? new MonitorProgramService();
    }

    /**
     * What the filters can offer: the institution's terms, departments and
     * programs.
     *
     * @return array{terms: list<array<string, mixed>>, departments: list<array<string, mixed>>, programs: list<array<string, mixed>>}
     */
    public function options(int $institutionId): array
    {
        $db = db_connect();

        return [
            'terms' => $db->table('academic_terms')->select('id, name, academic_year, start_date')->where('institution_id', $institutionId)->orderBy('start_date', 'DESC')->get()->getResultArray(),
            'departments' => $db->table('departments')->select('id, name, code')->where('institution_id', $institutionId)->orderBy('name')->get()->getResultArray(),
            'programs' => $db->table('programs p')
                ->select('p.id, p.name, p.code, d.id AS department_id')
                ->join('sub_departments sd', 'sd.id = p.sub_department_id')
                ->join('departments d', 'd.id = sd.department_id')
                ->where('d.institution_id', $institutionId)
                ->orderBy('p.name')
                ->get()->getResultArray(),
        ];
    }

    /**
     * The programs a report covers.
     *
     * @return list<int>
     *
     * @throws PageNotFoundException when the department or program is not the institution's
     */
    public function programIds(int $institutionId, ?int $departmentId = null, ?int $programId = null): array
    {
        $scope = InstitutionScope::forInstitution($institutionId);

        if ($departmentId !== null) {
            $scope->require('department', $departmentId);
        }

        if ($programId !== null) {
            $scope->require('program', $programId);
        }

        $builder = db_connect()->table('programs p')
            ->select('p.id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId);

        if ($departmentId !== null) {
            $builder->where('d.id', $departmentId);
        }

        if ($programId !== null) {
            $builder->where('p.id', $programId);
        }

        return array_map(static fn (array $r): int => (int) $r['id'], $builder->get()->getResultArray());
    }

    /**
     * The whole report for a scope.
     *
     * @return array<string, mixed>
     */
    public function report(int $institutionId, MonitorProgramFilters $filters, ?int $departmentId = null, ?int $programId = null, string $sort = 'course_code', string $dir = 'asc'): array
    {
        $programs = $this->programIds($institutionId, $departmentId, $programId);
        $rows     = $this->monitor->offeringRowsForPrograms($programs, $filters);
        $ids      = $this->offeringIds($rows);

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['offering_id']] = $row;
        }

        $comparison = $this->monitor->courseComparison($rows, $filters, $sort, $dir);

        foreach ($comparison as &$course) {
            $source                  = $byId[$course['offering_id']];
            $course['program_name']  = (string) $source['program_name'];
            $course['term_name']     = (string) $source['term_name'] . ' ' . (string) $source['academic_year'];
            $course['department_name'] = (string) $source['department_name'];
            $course['status']        = (string) $source['offering_status'];
        }

        unset($course);

        $grades = $this->monitor->grades($ids);

        $distribution = array_fill_keys(array_keys(MonitorProgramService::GRADE_BUCKETS), 0);
        $graded       = 0;
        $enrolledIn   = 0;
        $weighted     = 0.0;
        $weights      = 0;

        foreach ($grades as $grade) {
            foreach ($grade['distribution'] as $bucket => $count) {
                $distribution[$bucket] += $count;
            }

            $graded     += $grade['graded_count'];
            $enrolledIn += $grade['enrolled'];

            if ($grade['average_percentage'] !== null && $grade['graded_count'] > 0) {
                $weighted += $grade['average_percentage'] * $grade['graded_count'];
                $weights  += $grade['graded_count'];
            }
        }

        $atRisk = $this->monitor->atRisk($ids);

        foreach ($atRisk as &$student) {
            $source                    = $byId[$student['offering_id']] ?? null;
            $student['course_code']    = $source['course_code'] ?? '';
            $student['section_code']   = $source['section_code'] ?? '';
            $student['course_title']   = $source['course_title'] ?? '';
        }

        unset($student);

        $turnarounds = array_values(array_filter(array_column($comparison, 'avg_grading_turnaround_hours'), static fn ($v): bool => $v !== null));

        return [
            'rows'         => $rows,
            'snapshot'     => $this->monitor->snapshot($rows),
            'comparison'   => $comparison,
            'distribution' => $distribution,
            'grading'      => [
                'graded'   => $graded,
                'enrolled' => $enrolledIn,
                'percent'  => $enrolledIn === 0 ? null : (int) round($graded / $enrolledIn * 100),
                'average'  => $weights === 0 ? null : round($weighted / $weights, 1),
                'turnaround' => $turnarounds === [] ? null : round(array_sum($turnarounds) / count($turnarounds), 1),
            ],
            'atRisk'       => $atRisk,
            'programIds'   => $programs,
        ];
    }

    /**
     * One course (an offering), opened from the report: its own numbers and
     * the students who need attention in it.
     *
     * @return array<string, mixed>
     *
     * @throws PageNotFoundException when the offering is not the institution's
     */
    public function offering(int $institutionId, int $offeringId, MonitorProgramFilters $filters): array
    {
        InstitutionScope::forInstitution($institutionId)->require('offering', $offeringId);

        $programId = (int) db_connect()->table('course_offerings o')->select('c.program_id')->join('courses c', 'c.id = o.course_id')->where('o.id', $offeringId)->get()->getRow('program_id');

        $rows = array_values(array_filter(
            $this->monitor->offeringRowsForPrograms([$programId], new MonitorProgramFilters()),
            static fn (array $r): bool => (int) $r['offering_id'] === $offeringId,
        ));

        $row = $rows[0] ?? throw PageNotFoundException::forPageNotFound();

        $completion = $this->monitor->enrollmentAndCompletion([$offeringId])[$offeringId] ?? ['enrolled' => 0, 'completion_percentage' => null];
        $grades     = $this->monitor->grades([$offeringId])[$offeringId] ?? null;
        $activity   = $this->monitor->instructorActivity([$offeringId], $filters)[$offeringId];

        return [
            'offering'     => $row,
            'enrolled'     => (int) $completion['enrolled'],
            'completion'   => $completion['completion_percentage'],
            'grades'       => $grades,
            'activity'     => $activity,
            'atRisk'       => $this->monitor->atRisk([$offeringId]),
            'instructors'  => $this->instructorsOf($offeringId),
        ];
    }

    /**
     * One export as CSV text, for the same scope and filters as the page.
     *
     * @param array<string, mixed> $report the output of report()
     *
     * @return array{csv: string, rows: int}
     */
    public function csv(string $kind, array $report, MonitorProgramFilters $filters): array
    {
        $header = [];
        $lines  = [];

        switch ($kind) {
            case 'courses':
                $header = ['Course', 'Title', 'Section', 'Program', 'Term', 'Instructor', 'Enrolled', 'Completion %', 'Average grade %', 'Grading turnaround (hours)'];

                foreach ($report['comparison'] as $c) {
                    $lines[] = [$c['course_code'], $c['course_title'], $c['section_code'], $c['program_name'], $c['term_name'], (string) $c['instructor_name'], $c['enrolled'], $c['completion_percentage'] ?? '', $c['average_percentage'] ?? '', $c['avg_grading_turnaround_hours'] ?? ''];
                }

                break;

            case 'at-risk':
                $header = ['Student', 'Course', 'Section', 'Why', 'Progress %', 'Days since last activity'];

                foreach ($report['atRisk'] as $s) {
                    $lines[] = [$s['name'], $s['course_code'], $s['section_code'], $s['reason'] === 'inactive' ? 'No recent activity' : 'Behind', $s['progress'], $s['days_since'] ?? 'never'];
                }

                break;

            case 'grades':
                $header = ['Course', 'Section', 'Program', 'Enrolled', 'Graded', 'Average %', ...array_keys(MonitorProgramService::GRADE_BUCKETS)];
                $grades = $this->monitor->grades(array_map(static fn (array $r): int => (int) $r['offering_id'], $report['rows']));

                foreach ($report['comparison'] as $c) {
                    $g = $grades[$c['offering_id']] ?? null;

                    $lines[] = array_merge(
                        [$c['course_code'], $c['section_code'], $c['program_name'], $g['enrolled'] ?? $c['enrolled'], $g['graded_count'] ?? 0, $g['average_percentage'] ?? ''],
                        $g === null ? array_fill(0, count(MonitorProgramService::GRADE_BUCKETS), '') : array_values($g['distribution']),
                    );
                }

                break;

            case 'activity':
                $header   = ['Course', 'Section', 'Instructor', 'Graded', 'Grading turnaround (hours)', 'Content updates', 'Announcements'];
                $activity = $this->monitor->instructorActivity(array_map(static fn (array $r): int => (int) $r['offering_id'], $report['rows']), $filters);

                foreach ($report['comparison'] as $c) {
                    $a = $activity[$c['offering_id']] ?? ['graded_count' => 0, 'avg_grading_turnaround_hours' => null, 'content_updates' => 0, 'announcements' => 0];

                    $lines[] = [$c['course_code'], $c['section_code'], (string) $c['instructor_name'], $a['graded_count'], $a['avg_grading_turnaround_hours'] ?? '', $a['content_updates'], $a['announcements']];
                }

                break;

            default:
                throw PageNotFoundException::forPageNotFound();
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $header);

        foreach ($lines as $line) {
            // A name or title is typed by people: never let a spreadsheet read it as a formula.
            fputcsv($stream, array_map(static fn ($cell): string => UserExport::safeCell($cell), $line));
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return ['csv' => $csv, 'rows' => count($lines)];
    }

    /** Whether a name is one of the exports. */
    public static function isExport(string $kind): bool
    {
        return isset(self::EXPORTS[$kind]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private function offeringIds(array $rows): array
    {
        return array_map(static fn (array $r): int => (int) $r['offering_id'], $rows);
    }

    /** @return list<string> */
    private function instructorsOf(int $offeringId): array
    {
        $rows = db_connect()->table('instructor_assignments ia')
            ->select('up.first_name, up.last_name')
            ->join('user_profiles up', 'up.user_id = ia.instructor_user_id', 'left')
            ->where('ia.offering_id', $offeringId)
            ->orderBy('up.last_name')
            ->get()->getResultArray();

        return array_map(static fn (array $r): string => trim($r['first_name'] . ' ' . $r['last_name']), $rows);
    }
}
