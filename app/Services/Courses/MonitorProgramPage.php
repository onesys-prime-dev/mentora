<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * Everything the Monitor Program page shows, built from a list of offerings:
 * the snapshot, the course comparison, the at-risk students, the audit log and
 * the grade distribution. The chairperson's page (their own programs) and the
 * dean's drill-down into one program build the page from here, so the two
 * cannot show different numbers for the same program. Who decides which
 * offerings are in scope stays with the caller; this only presents them.
 */
class MonitorProgramPage
{
    public function __construct(private readonly MonitorProgramService $monitor = new MonitorProgramService())
    {
    }

    /**
     * @param list<array<string, mixed>> $offeringRows
     * @param ?int                       $auditActor   the one chairperson whose own actions the log lists, or null for everyone's in scope
     *
     * @return array{
     *     snapshot: array<string, int>,
     *     comparison: list<array<string, mixed>>,
     *     atRisk: list<array<string, mixed>>,
     *     audit: array{rows: list<array<string, mixed>>, total: int},
     *     auditPage: int,
     *     auditPages: int,
     *     gradeDistribution: array<string, int>,
     *     sort: string,
     *     dir: string,
     * }
     */
    public function build(array $offeringRows, MonitorProgramFilters $filters, string $sort, string $dir, int $auditPage, int $auditPerPage, ?int $auditActor): array
    {
        $offeringIds = array_map(static fn (array $row): int => (int) $row['offering_id'], $offeringRows);

        $comparison = $this->monitor->courseComparison($offeringRows, $filters, $sort, $dir);
        $atRisk     = $this->monitor->atRisk($offeringIds);
        $audit      = $this->monitor->auditEntries($auditActor, $offeringRows, $filters, $auditPage, $auditPerPage);

        $distribution = array_fill_keys(array_keys(MonitorProgramService::GRADE_BUCKETS), 0);

        foreach ($this->monitor->grades($offeringIds) as $grade) {
            foreach ($grade['distribution'] as $bucket => $count) {
                $distribution[$bucket] += $count;
            }
        }

        foreach ($comparison as &$row) {
            $row['slug'] = url_title((string) $row['course_title'], '-', true);
        }
        unset($row);

        foreach ($atRisk as &$row) {
            $offering = array_values(array_filter($comparison, static fn (array $c) => $c['offering_id'] === $row['offering_id']))[0] ?? null;

            $row['course_code']  = $offering['course_code'] ?? null;
            $row['section_code'] = $offering['section_code'] ?? null;
            $row['slug']         = $offering['slug'] ?? null;
        }
        unset($row);

        return [
            'snapshot'          => $this->monitor->snapshot($offeringRows),
            'comparison'        => $comparison,
            'atRisk'            => $atRisk,
            'audit'             => $audit,
            'auditPage'         => $auditPage,
            'auditPages'        => max(1, (int) ceil($audit['total'] / $auditPerPage)),
            'gradeDistribution' => $distribution,
            'sort'              => $sort,
            'dir'               => $dir,
        ];
    }
}
