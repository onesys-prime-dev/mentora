<?php

declare(strict_types=1);

namespace App\Services\Grades;

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
     * The states come from SectionStates, which the chairperson's program table reads too.
     *
     * @return list<array{offering_id: int, course_code: string, course_title: string, section_code: string, slug: string, students: int, waiting: int, state: string, note: string, offering_status: string, has_scheme: bool}>
     */
    public function sections(User $user, int $termId): array
    {
        $rows = db_connect()->table('instructor_assignments ia')
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

        $states = SectionStates::for($rows);

        helper('url');

        $cards = [];

        foreach ($rows as $row) {
            $id = (int) $row['offering_id'];
            $status = $states[$id];

            $cards[] = [
                'offering_id' => $id,
                'course_code' => (string) $row['course_code'],
                'course_title' => (string) $row['course_title'],
                'section_code' => (string) $row['section_code'],
                'slug' => url_title((string) $row['course_title'], '-', true),
                'students' => (int) $row['students'],
                'waiting' => $status['waiting'],
                'state' => $status['state'],
                'note' => $status['note'],
                'offering_status' => (string) $row['offering_status'],
                'has_scheme' => $status['has_scheme'],
            ];
        }

        return $cards;
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
