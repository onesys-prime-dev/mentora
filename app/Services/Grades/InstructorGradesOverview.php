<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Services\Courses\Grades\GradingSchemeService;
use App\Services\Courses\Grades\PendingGrading;
use CodeIgniter\Shield\Entities\User;

/**
 * The terms and section cards of the instructor Grades page.
 *
 * Everything is read from stored rows. In particular a section without a
 * grading scheme reads as "not published"; this never creates one. The query
 * count does not grow with the number of sections.
 *
 * Only active and completed sections are listed: an archived one is closed to its instructor on every course
 * route (CourseMembership), so it is left out here too, with any term it is the only section of.
 */
final class InstructorGradesOverview
{
    /** The offering statuses an instructor can still open (InstructorAssignmentModel::retrieveInstructorCourseOffering). */
    private const OPEN = ['active', 'completed'];

    /**
     * Terms of the offerings the user is assigned to teach, newest first.
     *
     * @return list<array{id: int, name: string, academic_year: string, status: string}>
     */
    public function terms(User $user): array
    {
        $rows = db_connect()->table('instructor_assignments ia')
            // start_date is selected because DISTINCT can only order by what it selects.
            ->select(['t.id', 't.name', 't.academic_year', 't.status', 't.start_date'])
            ->distinct()
            ->join('course_offerings co', 'co.id = ia.offering_id')
            ->join('academic_terms t', 't.id = co.term_id')
            ->where('ia.instructor_user_id', (int) $user->id)
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
        $ids = array_column($terms, 'id');

        if ($requested !== null && in_array($requested, $ids, true)) {
            return $requested;
        }

        foreach ($terms as $term) {
            if ($term['status'] === 'active') {
                return (int) $term['id'];
            }
        }

        return $terms === [] ? null : (int) $terms[0]['id'];
    }

    /**
     * A section whose scheme is published but could not be published now (weights that no longer add up, a
     * category gone) is blocked with the reason GradingSchemeService::publishProblem gives, since Finalize
     * would refuse it; that check is made from rows read for every section at once. has_scheme says whether
     * the section has any scheme at all: without one its grid is not opened, since opening it creates one.
     *
     * @return list<array{offering_id: int, course_code: string, course_title: string, section_code: string, slug: string, students: int, waiting: int, state: string, note: string, offering_status: string, has_scheme: bool}>
     */
    public function sections(User $user, int $termId): array
    {
        $db = db_connect();

        $rows = $db->table('instructor_assignments ia')
            ->select([
                'co.id AS offering_id',
                'co.section_code',
                'co.status AS offering_status',
                'c.course_code',
                'c.course_title',
            ])
            ->select(
                "(SELECT COUNT(*) FROM enrollments e
                   WHERE e.offering_id = co.id
                     AND e.status IN ('enrolled', 'completed')) AS students",
                false
            )
            ->join('course_offerings co', 'co.id = ia.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->where('ia.instructor_user_id', (int) $user->id)
            ->where('co.term_id', $termId)
            ->whereIn('co.status', self::OPEN)
            ->orderBy('c.course_code', 'ASC')
            ->orderBy('co.section_code', 'ASC')
            ->orderBy('co.id', 'ASC')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['offering_id'], $rows);

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

        $problems = $this->publishProblems($published);

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

        helper('url');

        $cards = [];

        foreach ($rows as $row) {
            $id = (int) $row['offering_id'];
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

            $cards[] = [
                'offering_id' => $id,
                'course_code' => (string) $row['course_code'],
                'course_title' => (string) $row['course_title'],
                'section_code' => (string) $row['section_code'],
                'slug' => url_title((string) $row['course_title'], '-', true),
                'students' => (int) $row['students'],
                'waiting' => $waiting,
                'state' => $state,
                'note' => $note,
                'offering_status' => (string) $row['offering_status'],
                'has_scheme' => isset($hasScheme[$id]),
            ];
        }

        return $cards;
    }

    /**
     * What stops each published scheme from being published now, by offering id; a scheme with nothing wrong
     * is left out. Two queries for every scheme together: the periods, then the categories.
     *
     * @param array<int, array<string, mixed>> $schemes published scheme rows, by offering id
     *
     * @return array<int, string>
     */
    private function publishProblems(array $schemes): array
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

    /**
     * @param list<array{offering_id: int, waiting: int}> $sections
     */
    public function defaultSectionId(array $sections, ?int $requested): ?int
    {
        if ($sections === []) {
            return null;
        }

        if ($requested !== null) {
            foreach ($sections as $section) {
                if ($section['offering_id'] === $requested) {
                    return $requested;
                }
            }
        }

        $best = $sections[0];

        foreach ($sections as $section) {
            if ($section['waiting'] > $best['waiting']) {
                $best = $section;
            }
        }

        return (int) $best['offering_id'];
    }

    /**
     * @param list<array{waiting: int, state: string}> $sections
     *
     * @return array{waiting: int, ready: int, finalized: int, total: int}
     */
    public function summary(array $sections): array
    {
        $summary = ['waiting' => 0, 'ready' => 0, 'finalized' => 0, 'total' => count($sections)];

        foreach ($sections as $section) {
            $summary['waiting'] += $section['waiting'];

            if ($section['state'] === 'ready') {
                $summary['ready']++;
            } elseif ($section['state'] === 'finalized') {
                $summary['finalized']++;
            }
        }

        return $summary;
    }
}
