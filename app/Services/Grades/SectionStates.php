<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Services\Courses\Grades\GradingSchemeService;
use App\Services\Courses\Grades\PendingGrading;

/**
 * Where each section stands on the way to finalizing, for every Grades list (the instructor's cards, the
 * chairperson's program table): waiting to grade, the state, its note, and whether it has a scheme at all.
 *
 * The rules, in order: finalized; not published (no published scheme); waiting (ungraded work, PendingGrading's
 * rule); blocked by a publish problem (GradingSchemeService::publishProblemFrom, so the course's own words) or
 * by open override requests; else ready. Everything is read from stored rows, never creating a scheme, in a
 * fixed number of queries however many sections are asked for.
 */
final class SectionStates
{
    /**
     * @param list<array{offering_id: int|string}> $offerings rows carrying the offering id
     *
     * @return array<int, array{waiting: int, state: string, note: string, has_scheme: bool}> by offering id
     */
    public static function for(array $offerings): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['offering_id'], $offerings)));

        if ($ids === []) {
            return [];
        }

        $db = db_connect();

        $hasScheme = [];
        $published = [];

        foreach ($db->table('grading_schemes')
            ->select(['id', 'course_offering_id', 'status', 'uses_periods'])
            ->whereIn('course_offering_id', $ids)
            ->get()->getResultArray() as $scheme) {
            $hasScheme[(int) $scheme['course_offering_id']] = true;

            if ($scheme['status'] === 'Published') {
                $published[(int) $scheme['course_offering_id']] = $scheme;
            }
        }

        $problems = self::publishProblems($published);

        $finalized = [];

        foreach ($db->table('grade_finalizations')
            ->select('course_offering_id')
            ->whereIn('course_offering_id', $ids)
            ->where('reopened_at IS NULL', null, false)
            ->get()->getResultArray() as $row) {
            $finalized[(int) $row['course_offering_id']] = true;
        }

        $requests = [];

        foreach ($db->table('grade_override_requests')
            ->select(['offering_id', 'COUNT(*) AS open_requests'], false)
            ->whereIn('offering_id', $ids)
            ->where('open_marker', 1)
            ->groupBy('offering_id')
            ->get()->getResultArray() as $row) {
            $requests[(int) $row['offering_id']] = (int) $row['open_requests'];
        }

        $waitingBy = (new PendingGrading())->forOfferings($ids)['byOffering'];

        $states = [];

        foreach ($ids as $id) {
            $waiting = (int) ($waitingBy[$id] ?? 0);
            $open = $requests[$id] ?? 0;

            if (isset($finalized[$id])) {
                [$state, $note] = ['finalized', 'Finalized'];
            } elseif (! isset($published[$id])) {
                [$state, $note] = ['not_published', 'Grades not published yet'];
            } elseif ($waiting > 0) {
                [$state, $note] = ['waiting', $waiting . ' waiting to be graded'];
            } elseif (isset($problems[$id])) {
                [$state, $note] = ['blocked', $problems[$id]];
            } elseif ($open > 0) {
                [$state, $note] = ['blocked', $open . ($open === 1 ? ' override request open' : ' override requests open')];
            } else {
                [$state, $note] = ['ready', 'Ready to finalize'];
            }

            $states[$id] = [
                'waiting' => $waiting,
                'state' => $state,
                'note' => $note,
                'has_scheme' => isset($hasScheme[$id]),
            ];
        }

        return $states;
    }

    /**
     * What stops each published scheme from being published now, by offering id; a scheme with nothing wrong
     * is left out. Two queries for every scheme together: the periods, then the categories.
     *
     * @param array<int, array<string, mixed>> $schemes published scheme rows, by offering id
     *
     * @return array<int, string>
     */
    private static function publishProblems(array $schemes): array
    {
        if ($schemes === []) {
            return [];
        }

        $db = db_connect();
        $schemeIds = array_map(static fn (array $scheme): int => (int) $scheme['id'], array_values($schemes));

        $periods = [];

        foreach ($db->table('grading_periods')
            ->select(['id', 'grading_scheme_id', 'name', 'weight_percentage'])
            ->whereIn('grading_scheme_id', $schemeIds)
            ->where('status', 'Active')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray() as $period) {
            $periods[(int) $period['grading_scheme_id']][] = $period;
        }

        $bySchemes = [];
        $byPeriod = [];

        foreach ($db->table('grade_categories')
            ->select(['grading_scheme_id', 'period_id', 'weight_percentage'])
            ->whereIn('grading_scheme_id', $schemeIds)
            ->where('status', 'Active')
            ->get()->getResultArray() as $category) {
            $bySchemes[(int) $category['grading_scheme_id']][] = $category;

            if ($category['period_id'] !== null) {
                $byPeriod[(int) $category['period_id']][] = $category;
            }
        }

        $problems = [];

        foreach ($schemes as $offeringId => $scheme) {
            $schemeId = (int) $scheme['id'];

            $problem = GradingSchemeService::publishProblemFrom(
                ! empty($scheme['uses_periods']),
                static fn (): array => $bySchemes[$schemeId] ?? [],
                static fn (): array => $periods[$schemeId] ?? [],
                static fn (int $periodId): array => $byPeriod[$periodId] ?? [],
            );

            if ($problem !== null) {
                $problems[$offeringId] = $problem;
            }
        }

        return $problems;
    }
}
