<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Models\Assignments\DeanAssignmentModel;
use CodeIgniter\Shield\Entities\User;

/**
 * The programs, terms and section table of the dean's College tab.
 *
 * A dean reaches the sections of the college(s) they are actively assigned to (every department and
 * program under them), active or completed. The table is the chairperson's (ChairpersonProgramOverview's
 * query over the college's departments) with the section's program and a Signed off column, and a
 * strip in place of the tracker: finalized, signed off and the open grade-override requests waiting, over the
 * whole program choice. Every number is read from stored rows; nothing is created, and the query count does
 * not grow with the number of sections.
 */
final class DeanCollegeOverview
{
    public const SORTS = [...ChairpersonProgramOverview::SORTS, 'signed_off'];

    private readonly ChairpersonProgramOverview $program;

    public function __construct()
    {
        $this->program = new ChairpersonProgramOverview();
    }

    /**
     * The programs of the dean's college(s), by name: the picker after "All programs".
     *
     * @return list<array{id: int, name: string}>
     */
    public function programs(User $dean): array
    {
        $colleges = $this->colleges($dean);

        if ($colleges === []) {
            return [];
        }

        $rows = db_connect()->table('programs p')
            ->select(['p.id', 'p.name'])
            ->join('departments sd', 'sd.id = p.department_id')
            ->whereIn('sd.college_id', $colleges)
            ->orderBy('p.name', 'ASC')
            ->orderBy('p.id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']], $rows);
    }

    /**
     * Terms of the sections the dean reaches, newest first.
     *
     * @return list<array{id: int, name: string, academic_year: string, status: string}>
     */
    public function terms(User $dean): array
    {
        return $this->program->termsIn($this->departments($dean));
    }

    /**
     * @param list<array{id: int, status: string}> $terms
     */
    public function defaultTermId(array $terms, ?int $requested): ?int
    {
        return $this->program->defaultTermId($terms, $requested);
    }

    /**
     * One page of the term's sections (of one program, or all of them with null), searched, filtered and
     * sorted, with the strip over every section of that choice whatever the search and filter.
     *
     * @param array{q?: string, notFinalized?: bool, sort?: string, dir?: string, page?: int} $opts
     *
     * @return array{rows: list<array<string, mixed>>, total: int, strip: array{finalized: int, signed_off: int, total: int, requests_waiting: int}}
     */
    public function sections(User $dean, int $termId, ?int $programId, array $opts = []): array
    {
        return $this->pageOf($this->all($dean, $termId, $programId), $opts);
    }

    /**
     * sections() over rows already read with all(), so the page reads them once.
     *
     * @param list<array<string, mixed>>                                                         $all
     * @param array{q?: string, notFinalized?: bool, sort?: string, dir?: string, page?: int} $opts
     *
     * @return array{rows: list<array<string, mixed>>, total: int, strip: array{finalized: int, signed_off: int, total: int, requests_waiting: int}}
     */
    public function pageOf(array $all, array $opts = []): array
    {
        $page = $this->program->pageOf($all, $opts, self::SORTS);

        return [
            'rows' => $page['rows'],
            'total' => $page['total'],
            'strip' => [
                'finalized' => $page['tracker']['finalized'],
                'signed_off' => count(array_filter($all, static fn (array $row): bool => $row['signed_off'])),
                'total' => $page['tracker']['total'],
                'requests_waiting' => $this->requestsWaiting(array_column($all, 'offering_id')),
            ],
        ];
    }

    /**
     * Every section of the term the dean reaches (of one program, or all with null), unpaginated, in section
     * order: the chairperson's row plus program_id and signed_off (a standing sign-off).
     *
     * @return list<array<string, mixed>>
     */
    public function all(User $dean, int $termId, ?int $programId): array
    {
        $rows = $this->program->rowsIn($this->departments($dean), $termId, $programId);

        if ($rows === []) {
            return [];
        }

        $signed = array_flip(array_map('intval', array_column(
            db_connect()->table('grade_signoffs')
                ->select('offering_id')
                ->whereIn('offering_id', array_column($rows, 'offering_id'))
                ->where('open_marker', 1)
                ->get()->getResultArray(),
            'offering_id'
        )));

        foreach ($rows as &$row) {
            $row['signed_off'] = isset($signed[$row['offering_id']]);
        }

        unset($row);

        return $rows;
    }

    /** The term's last day (Y-m-d), what the nudge words "the term ends" from; null for an unknown term. */
    public function termEnd(int $termId): ?string
    {
        return $this->program->termEnd($termId);
    }

    /**
     * Open grade-override requests (each request, not each section) on these sections. One query.
     *
     * @param list<int> $offeringIds
     */
    private function requestsWaiting(array $offeringIds): int
    {
        if ($offeringIds === []) {
            return 0;
        }

        return db_connect()->table('grade_override_requests')
            ->whereIn('offering_id', $offeringIds)
            ->where('open_marker', 1)
            ->countAllResults();
    }

    /** @return list<int> the colleges the dean is actively assigned to */
    private function colleges(User $dean): array
    {
        return (new DeanAssignmentModel())->collegeIdsOfDean((int) $dean->id);
    }

    /** @return list<int> every department of the dean's college(s) */
    private function departments(User $dean): array
    {
        $colleges = $this->colleges($dean);

        if ($colleges === []) {
            return [];
        }

        return array_map('intval', array_column(
            db_connect()->table('departments')->select('id')->whereIn('college_id', $colleges)->get()->getResultArray(),
            'id'
        ));
    }
}
