<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Models\Assessments\GradebookRecordModel;
use App\Models\Assignments\ChairpersonAssignmentModel;
use CodeIgniter\Shield\Entities\User;

/**
 * The terms and section table of the chairperson's Program tab, and the sections of their My sections tab.
 *
 * A chairperson reaches the sections of the sub-departments they actively chair (ChairpersonAccessService's
 * rule: course → program → sub-department), active or completed. Every number is read from stored rows: the
 * state from SectionStates (the instructor's rules), the average and the at-risk count from the gradebook
 * records. Nothing is created, and the query count does not grow with the number of sections; search,
 * filter, sort and pages are applied in PHP over the term's rows.
 */
final class ChairpersonProgramOverview
{
    public const PER_PAGE = 25;

    public const SORTS = ['section', 'instructor', 'average', 'at_risk', 'waiting', 'status'];

    /** The offering statuses a chairperson's Grades page lists (as the instructor's). */
    private const OPEN = ['active', 'completed'];

    /** Status sort order, ascending: what needs attention first, finalized last. */
    private const STATE_RANK = ['waiting' => 0, 'blocked' => 1, 'not_published' => 2, 'ready' => 3, 'finalized' => 4];

    /**
     * Terms of the sections the chairperson reaches, newest first.
     *
     * @return list<array{id: int, name: string, academic_year: string, status: string}>
     */
    public function terms(User $chair): array
    {
        return $this->termsIn($this->subDepartments($chair));
    }

    /**
     * Terms of the active or completed sections in these sub-departments, newest first: what terms() reads for
     * the chairperson, and the dean's Department tab for the sub-departments of their department(s).
     *
     * @param list<int> $subDepartments
     *
     * @return list<array{id: int, name: string, academic_year: string, status: string}>
     */
    public function termsIn(array $subDepartments): array
    {
        if ($subDepartments === []) {
            return [];
        }

        $rows = db_connect()->table('course_offerings co')
            // start_date is selected because DISTINCT can only order by what it selects.
            ->select(['t.id', 't.name', 't.academic_year', 't.status', 't.start_date'])
            ->distinct()
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('academic_terms t', 't.id = co.term_id')
            ->whereIn('p.sub_department_id', $subDepartments)
            ->whereIn('co.status', self::OPEN)
            ->orderBy('t.start_date', 'DESC')
            ->orderBy('t.id', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'academic_year' => (string) $row['academic_year'],
            'status' => (string) $row['status'],
        ], $rows);
    }

    /**
     * @param list<array{id: int, status: string}> $terms
     */
    public function defaultTermId(array $terms, ?int $requested): ?int
    {
        return (new InstructorGradesOverview())->defaultTermId($terms, $requested);
    }

    /**
     * One page of the term's sections, searched, filtered and sorted, with the tracker over the whole term.
     *
     * @param array{q?: string, notFinalized?: bool, sort?: string, dir?: string, page?: int} $opts
     *
     * @return array{rows: list<array<string, mixed>>, total: int, tracker: array{finalized: int, ready: int, blocked: int, total: int}}
     */
    public function sections(User $chair, int $termId, array $opts = []): array
    {
        return $this->pageOf($this->termRows($chair, $termId), $opts);
    }

    /**
     * sections() over rows already read with all().
     *
     * $sorts are the columns the table may sort by; a caller whose rows carry more columns (the dean's Signed
     * off) passes its own list.
     *
     * @param list<array<string, mixed>>                                                         $all
     * @param array{q?: string, notFinalized?: bool, sort?: string, dir?: string, page?: int} $opts
     * @param list<string>                                                                       $sorts
     *
     * @return array{rows: list<array<string, mixed>>, total: int, tracker: array{finalized: int, ready: int, blocked: int, total: int}}
     */
    public function pageOf(array $all, array $opts = [], array $sorts = self::SORTS): array
    {
        $tracker = ['finalized' => 0, 'ready' => 0, 'blocked' => 0, 'total' => count($all)];

        foreach ($all as $row) {
            if ($row['state'] === 'finalized') {
                $tracker['finalized']++;
            } elseif ($row['state'] === 'ready') {
                $tracker['ready']++;
            } else {
                // Anything else cannot be finalized yet: waiting, blocked or not published.
                $tracker['blocked']++;
            }
        }

        $rows = $this->search($all, trim((string) ($opts['q'] ?? '')));

        if (! empty($opts['notFinalized'])) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['state'] !== 'finalized'));
        }

        $sort = in_array($opts['sort'] ?? null, $sorts, true) ? $opts['sort'] : 'section';
        $rows = $this->sort($rows, $sort, ($opts['dir'] ?? 'asc') === 'desc');

        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($opts['page'] ?? 1)), $pages);

        return [
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'tracker' => $tracker,
        ];
    }

    /**
     * Every section of the term the chairperson reaches, unpaginated, in section order: what the Grades page
     * checks a chosen section against and builds the program's at-risk list from. pageOf() then makes the
     * table's page from these same rows, so the page reads them once.
     *
     * @return list<array<string, mixed>>
     */
    public function all(User $chair, int $termId): array
    {
        return $this->termRows($chair, $termId);
    }

    /** The term's last day (Y-m-d), what the nudge words "the term ends" from; null for an unknown term. */
    public function termEnd(int $termId): ?string
    {
        $end = db_connect()->table('academic_terms')->select('end_date')->where('id', $termId)->get()->getRow('end_date');

        return $end === null ? null : (string) $end;
    }

    /**
     * InstructorGradesOverview::sections() rows for the sections the chairperson teaches and may also reach as
     * chairperson; a section they teach outside what they chair is left out (the chairperson area cannot
     * open it).
     *
     * @return list<array<string, mixed>>
     */
    public function mySections(User $chair, int $termId): array
    {
        $taught = (new InstructorGradesOverview())->sections($chair, $termId);
        $subDepartments = $this->subDepartments($chair);

        if ($taught === [] || $subDepartments === []) {
            return [];
        }

        $reachable = array_flip(array_map('intval', array_column(
            db_connect()->table('course_offerings co')
                ->select('co.id')
                ->join('courses c', 'c.id = co.course_id')
                ->join('programs p', 'p.id = c.program_id')
                ->whereIn('co.id', array_column($taught, 'offering_id'))
                ->whereIn('p.sub_department_id', $subDepartments)
                ->get()->getResultArray(),
            'id'
        )));

        return array_values(array_filter($taught, static fn (array $row): bool => isset($reachable[$row['offering_id']])));
    }

    /** @return list<int> */
    private function subDepartments(User $chair): array
    {
        return (new ChairpersonAssignmentModel())->subDepartmentIdsFor((int) $chair->id);
    }

    /**
     * Every section of the term the chairperson reaches, in section order, with all its columns.
     *
     * @return list<array<string, mixed>>
     */
    private function termRows(User $chair, int $termId): array
    {
        // The chairperson's rows have no program column: they chair the programs of their sub-departments.
        return array_map(static function (array $row): array {
            unset($row['program_id']);

            return $row;
        }, $this->rowsIn($this->subDepartments($chair), $termId));
    }

    /**
     * Every active or completed section of the term in these sub-departments (and only this program, when one
     * is given), in section order, with all the table's columns and the section's program_id: the chairperson's
     * Program tab reads it for the sub-departments they chair, the dean's Department tab for those of their
     * department(s). The query count does not grow with the number of sections.
     *
     * @param list<int> $subDepartments
     *
     * @return list<array<string, mixed>>
     */
    public function rowsIn(array $subDepartments, int $termId, ?int $programId = null): array
    {
        if ($subDepartments === []) {
            return [];
        }

        $db = db_connect();

        $builder = $db->table('course_offerings co')
            ->select(['co.id AS offering_id', 'co.section_code', 'co.status AS offering_status', 'c.course_code', 'c.course_title', 'p.id AS program_id'])
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->whereIn('p.sub_department_id', $subDepartments)
            ->where('co.term_id', $termId)
            ->whereIn('co.status', self::OPEN);

        if ($programId !== null) {
            $builder->where('p.id', $programId);
        }

        $offerings = $builder
            ->orderBy('c.course_code', 'ASC')
            ->orderBy('co.section_code', 'ASC')
            ->orderBy('co.id', 'ASC')
            ->get()
            ->getResultArray();

        if ($offerings === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['offering_id'], $offerings);
        $states = SectionStates::for($offerings);
        $instructors = $this->instructors($ids);
        [$averages, $atRisk] = $this->standings($ids);

        helper('url');

        $rows = [];

        foreach ($offerings as $offering) {
            $id = (int) $offering['offering_id'];
            $state = $states[$id];

            $rows[] = [
                'offering_id' => $id,
                'course_code' => (string) $offering['course_code'],
                'course_title' => (string) $offering['course_title'],
                'section_code' => (string) $offering['section_code'],
                'slug' => url_title((string) $offering['course_title'], '-', true),
                'instructors' => $instructors[$id] ?? [],
                'average' => $averages[$id] ?? null,
                'at_risk' => $atRisk[$id] ?? 0,
                'waiting' => $state['waiting'],
                'state' => $state['state'],
                'note' => $state['note'],
                'has_scheme' => $state['has_scheme'],
                'offering_status' => (string) $offering['offering_status'],
                'program_id' => (int) $offering['program_id'],
            ];
        }

        return $rows;
    }

    /**
     * The instructors of each section, by last then first name. One query.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<array{id: int, name: string}>>
     */
    private function instructors(array $ids): array
    {
        $names = [];

        foreach (db_connect()->table('instructor_assignments ia')
            ->select(['ia.offering_id', 'ia.instructor_user_id', 'u.username', 'up.first_name', 'up.last_name'])
            ->join('users u', 'u.id = ia.instructor_user_id')
            ->join('user_profiles up', 'up.user_id = ia.instructor_user_id', 'left')
            ->whereIn('ia.offering_id', $ids)
            ->orderBy('up.last_name', 'ASC')
            ->orderBy('up.first_name', 'ASC')
            ->orderBy('ia.instructor_user_id', 'ASC')
            ->get()->getResultArray() as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

            $names[(int) $row['offering_id']][] = [
                'id' => (int) $row['instructor_user_id'],
                'name' => $name !== '' ? $name : (string) $row['username'],
            ];
        }

        return $names;
    }

    /**
     * Per section, the mean effective percentage of its enrolled and completed students who have one, and how
     * many of them are at risk by grade (failing, failed or INC; published schemes only, as GradesAtRisk).
     * One query.
     *
     * @param list<int> $ids
     *
     * @return array{0: array<int, float>, 1: array<int, int>}
     */
    private function standings(array $ids): array
    {
        $db = db_connect();

        $records = $db->table('gradebook_records gr')
            ->select([
                'gr.course_offering_id', 'gr.student_id', 'gr.remarks', 'gr.inc_set_at', 'gr.is_override',
                'gr.overridden_percentage', 'gr.calculated_percentage', 'gr.finalized_at', 'gr.final_percentage',
                'gs.id AS published_scheme_id',
            ])
            ->join('enrollments e', 'e.offering_id = gr.course_offering_id AND e.student_user_id = gr.student_id')
            ->join('grading_schemes gs', 'gs.course_offering_id = gr.course_offering_id AND gs.status = ' . $db->escape('Published'), 'left')
            ->whereIn('gr.course_offering_id', $ids)
            ->whereIn('e.status', ['enrolled', 'completed'])
            ->get()
            ->getResultArray();

        $percentages = [];
        $risky = [];

        foreach ($records as $record) {
            $offeringId = (int) $record['course_offering_id'];
            $studentId = (int) $record['student_id'];
            $percentage = GradebookRecordModel::effectivePercentage($record);

            // Keyed by student, so a duplicate join row cannot count anyone twice.
            if ($percentage !== null) {
                $percentages[$offeringId][$studentId] = $percentage;
            }

            if ($record['published_scheme_id'] !== null && ($record['remarks'] === 'Failed' || ! empty($record['inc_set_at']))) {
                $risky[$offeringId][$studentId] = true;
            }
        }

        $averages = array_map(static fn (array $values): float => array_sum($values) / count($values), $percentages);
        $atRisk = array_map('count', $risky);

        return [$averages, $atRisk];
    }

    /**
     * Rows whose course code, title, section label ("IT101-A") or an instructor's name holds $q, ignoring case.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function search(array $rows, string $q): array
    {
        if ($q === '') {
            return $rows;
        }

        $needle = mb_strtolower($q);

        return array_values(array_filter($rows, static function (array $row) use ($needle): bool {
            $haystack = [
                $row['course_code'],
                $row['course_title'],
                $row['course_code'] . '-' . $row['section_code'],
                ...array_column($row['instructors'], 'name'),
            ];

            foreach ($haystack as $text) {
                if (str_contains(mb_strtolower((string) $text), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Sorted by the column, either way; ties keep the section order. A section without an average (or whose
     * average is hidden, not published) sorts last either way by Average, one without an instructor last either
     * way by Instructor.
     *
     * @param list<array<string, mixed>> $rows in section order
     *
     * @return list<array<string, mixed>>
     */
    private function sort(array $rows, string $sort, bool $desc): array
    {
        if ($sort === 'section') {
            return $desc ? array_reverse($rows) : $rows;
        }

        $position = array_flip(array_column($rows, 'offering_id'));

        // What sorts last either way: no average to compare (none, or hidden while not published), no instructor.
        $missing = static fn (array $row): bool => match ($sort) {
            'average' => $row['average'] === null || $row['state'] === 'not_published',
            'instructor' => ($row['instructors'][0]['name'] ?? '') === '',
            default => false,
        };

        usort($rows, static function (array $a, array $b) use ($sort, $desc, $position, $missing): int {
            if ($missing($a) || $missing($b)) {
                $order = $missing($a) <=> $missing($b);
            } else {
                $key = static fn (array $row): mixed => match ($sort) {
                    'instructor' => mb_strtolower($row['instructors'][0]['name'] ?? ''),
                    'status' => self::STATE_RANK[$row['state']] ?? 99,
                    default => $row[$sort],
                };

                $order = $key($a) <=> $key($b);

                if ($desc) {
                    $order = -$order;
                }
            }

            return $order !== 0 ? $order : $position[$a['offering_id']] <=> $position[$b['offering_id']];
        });

        return $rows;
    }
}
