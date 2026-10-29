<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Services\Courses\Grades\GradeSignoffService;
use App\Services\Users\UserExport;
use CodeIgniter\Shield\Entities\User;

/**
 * The dean's department report: Monitor Department's totals and comparison, the teaching load and where
 * sign-off stands, as one print-friendly page or as a CSV of each part.
 *
 * Nothing here works anything out for itself: every part comes from the service behind the page it mirrors
 * (MonitorDepartmentService, TeachingLoadService, GradeSignoffService), given the same filters from the
 * same address, so an export holds exactly what the dean can see and never more, and cannot drift from it.
 * Cells people typed (names, titles) are made safe for a spreadsheet.
 */
class DepartmentReportService
{
    /** The parts that can be downloaded, and what each is called. */
    public const SECTIONS = [
        'monitor'       => 'Monitor Department',
        'teaching-load' => 'Teaching load',
        'signoff'       => 'Sign-off status',
    ];

    public function __construct(
        private readonly DeanAccessService $access = new DeanAccessService(),
        private readonly MonitorDepartmentService $monitor = new MonitorDepartmentService(),
        private readonly TeachingLoadService $teaching = new TeachingLoadService(),
        private readonly GradeSignoffService $signoff = new GradeSignoffService(),
    ) {
    }

    public static function isSection(string $section): bool
    {
        return isset(self::SECTIONS[$section]);
    }

    /**
     * Everything the print view shows, for the filters in the address; null for a dean with no department.
     * A download needs only its own part, so $only can name the parts to work out (the others are left empty).
     *
     * @param array<string, mixed> $get  usually $request->getGet()
     * @param list<string>|null    $only part names from SECTIONS, or null for all of them
     *
     * @return array<string, mixed>|null
     */
    public function build(User $dean, array $get, ?\DateTimeImmutable $now = null, ?array $only = null): ?array
    {
        $department = $this->access->departmentFor($dean);

        if ($department === null) {
            return null;
        }

        $monitorFilters = MonitorDepartmentFilters::fromInput($get);
        $loadFilters    = TeachingLoadFilters::fromInput($get);

        $wants = static fn (string $part): bool => $only === null || in_array($part, $only, true);

        $overview = $wants('monitor') ? $this->monitor->overview($dean, $monitorFilters) : ['totals' => [], 'groups' => []];
        $load     = $wants('teaching-load') ? $this->teaching->load($dean, $loadFilters) : ['term' => null, 'highLoadUnits' => 0, 'rows' => []];
        $board    = $wants('signoff') ? $this->signoff->board($dean, $monitorFilters->base->termId ?? $loadFilters->termId) : ['term' => null, 'programs' => []];

        // The sign-off page has only a term filter, but a report narrowed to a sub-department or a program is
        // narrowed all the way down, so what it says at the top is true of every part.
        if ($wants('signoff') && ($monitorFilters->subDepartmentId !== null || $monitorFilters->programId !== null)) {
            $inScope           = $this->monitor->programIds($dean, $monitorFilters);
            $board['programs'] = array_values(array_filter($board['programs'], static fn (array $p): bool => in_array((int) $p['id'], $inScope, true)));
        }

        $terms = [];

        foreach ($this->teaching->terms($dean) as $term) {
            $terms[(int) $term['id']] = $term['name'] . ' (' . $term['academic_year'] . ')';
        }

        $name = db_connect()->table('departments')->select('name')->where('id', (int) $department['department_id'])->get()->getRow('name');

        return [
            'department'   => (string) ($name ?? 'Department'),
            'generated_at' => ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'filters'      => $this->describeFilters($monitorFilters, $terms),
            'monitor'      => [
                'compare' => $monitorFilters->compare,
                'totals'  => $overview['totals'],
                'groups'  => $overview['groups'],
            ],
            'teaching_load' => [
                'term'           => $load['term'] === null ? null : ($terms[$load['term']] ?? null),
                'high_load_units' => $load['highLoadUnits'],
                'rows'           => $load['rows'],
            ],
            'signoff' => [
                'term'     => $board['term'] === null ? null : ($terms[$board['term']] ?? null),
                'programs' => $board['programs'],
            ],
        ];
    }

    /**
     * One part as CSV text (without the byte-order mark the download adds), from the output of build().
     *
     * @param array<string, mixed> $report
     *
     * @return array{csv: string, rows: int}
     */
    public function csv(string $section, array $report): array
    {
        [$header, $lines] = match ($section) {
            'monitor'       => $this->monitorLines($report['monitor']),
            'teaching-load' => $this->teachingLoadLines($report['teaching_load']),
            'signoff'       => $this->signoffLines($report['signoff']),
            default         => throw new \InvalidArgumentException("Unknown report part: {$section}"),
        };

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $header, ',', '"', '');

        foreach ($lines as $line) {
            // A name or a title is typed by people: never let a spreadsheet read it as a formula.
            fputcsv($stream, array_map(static fn ($cell): string => UserExport::safeCell($cell), $line), ',', '"', '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return ['csv' => $csv, 'rows' => count($lines)];
    }

    /** The file name (without extension) of a download. */
    public function fileStem(string $section, string $department, ?\DateTimeImmutable $now = null): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($department)), '-');

        return ($slug === '' ? 'department' : $slug) . '-' . $section . '-' . ($now ?? new \DateTimeImmutable())->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $monitor
     *
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function monitorLines(array $monitor): array
    {
        $byProgram = $monitor['compare'] === 'program';
        $header    = [$byProgram ? 'Program' : 'Sub-department', 'Sub-department', 'Courses', 'Instructors', 'Students', 'Completion %', 'Average grade %', 'Graded %', 'Grading turnaround (hours)', 'At risk', 'Last activity'];
        $lines     = [];

        $row = static fn (string $name, string $context, array $m): array => [
            $name, $context, $m['courses'], $m['instructors'], $m['students'], $m['completion'] ?? '', $m['average_grade'] ?? '', $m['grading_progress'] ?? '', $m['turnaround'] ?? '', $m['at_risk'], $m['last_activity'] === null ? '' : substr((string) $m['last_activity'], 0, 10),
        ];

        $lines[] = $row('Department total', '', $monitor['totals']);

        foreach ($monitor['groups'] as $group) {
            $lines[] = $row((string) $group['name'], $byProgram ? (string) ($group['context'] ?? '') : '', $group);
        }

        return [$header, $lines];
    }

    /**
     * @param array<string, mixed> $load
     *
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function teachingLoadLines(array $load): array
    {
        $lines = [];

        foreach ($load['rows'] as $r) {
            $lines[] = [$r['name'], $r['sections'], $r['students'], rtrim(rtrim(number_format((float) $r['units'], 1, '.', ''), '0'), '.'), $r['ungraded'], $r['turnaround'] ?? '', $r['high_load'] ? 'Yes' : 'No'];
        }

        return [['Instructor', 'Sections', 'Students', 'Units', 'Waiting to be graded', 'Grading turnaround (hours)', 'High load (' . $load['high_load_units'] . '+ units)'], $lines];
    }

    /**
     * @param array<string, mixed> $signoff
     *
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function signoffLines(array $signoff): array
    {
        $lines = [];

        foreach ($signoff['programs'] as $program) {
            foreach ($program['sections'] as $s) {
                $lines[] = [
                    $program['name'], $s['course_code'], $s['section_code'], $s['course_title'], $s['instructor'] ?? '', $s['students'],
                    match ($s['state']) { GradeSignoffService::SIGNED_OFF => 'Signed off', GradeSignoffService::READY => 'Ready', default => 'Blocked' },
                    implode(' ', $s['blockers']),
                    $s['signed_by_name'] ?? '',
                    $s['signed_at'] === null ? '' : substr((string) $s['signed_at'], 0, 10),
                ];
            }
        }

        return [['Program', 'Course', 'Section', 'Title', 'Instructor', 'Students', 'Status', 'Why blocked', 'Signed off by', 'Signed off on'], $lines];
    }

    /**
     * The filters in words, for the top of the print view.
     *
     * @param array<int, string> $terms
     *
     * @return list<string>
     */
    private function describeFilters(MonitorDepartmentFilters $f, array $terms): array
    {
        $out = [];

        if ($f->base->termId !== null && isset($terms[$f->base->termId])) {
            $out[] = 'Term: ' . $terms[$f->base->termId];
        }

        if ($f->base->from !== null || $f->base->to !== null) {
            $out[] = 'Dates: ' . ($f->base->from ?? 'the start') . ' to ' . ($f->base->to ?? 'today');
        }

        if ($f->subDepartmentId !== null) {
            $name = db_connect()->table('sub_departments')->select('name')->where('id', $f->subDepartmentId)->get()->getRow('name');
            $out[] = 'Sub-department: ' . ($name ?? 'unknown');
        }

        if ($f->programId !== null) {
            $name = db_connect()->table('programs')->select('name')->where('id', $f->programId)->get()->getRow('name');
            $out[] = 'Program: ' . ($name ?? 'unknown');
        }

        return $out;
    }
}
