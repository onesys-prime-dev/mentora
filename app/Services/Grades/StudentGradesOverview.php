<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Models\Assessments\GradebookRecordModel;
use App\Services\Courses\Grades\GradingEngineService;
use CodeIgniter\Shield\Entities\User;

/**
 * The terms, course rows and summary of the student Grades page.
 *
 * Everything is read from stored rows: a course without a grading scheme, or
 * with one that is not Published, reads as "not published" and shows no grade;
 * this never creates a scheme or a gradebook record. The query count does not
 * grow with the number of courses.
 *
 * Enrolments shown are enrolled, completed and withdrawn (never dropped), in
 * offerings that are active or completed (archived ones are closed on every
 * course route, so they are left out here too, with any term they are the only
 * course of).
 */
final class StudentGradesOverview
{
    private const ENROLMENTS = ['enrolled', 'completed', 'withdrawn'];

    private const OFFERINGS = ['active', 'completed'];

    /**
     * Terms of the student's shown enrolments, newest first.
     *
     * @return list<array{id: int, name: string, academic_year: string, status: string, start_date: ?string, end_date: ?string}>
     */
    public function terms(User $student): array
    {
        $rows = db_connect()->table('enrollments e')
            ->select(['t.id', 't.name', 't.academic_year', 't.status', 't.start_date', 't.end_date'])
            ->distinct()
            ->join('course_offerings co', 'co.id = e.offering_id')
            ->join('academic_terms t', 't.id = co.term_id')
            ->where('e.student_user_id', (int) $student->id)
            ->whereIn('e.status', self::ENROLMENTS)
            ->whereIn('co.status', self::OFFERINGS)
            ->orderBy('t.start_date', 'DESC')
            ->orderBy('t.id', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'academic_year' => (string) $row['academic_year'],
            'status' => (string) $row['status'],
            'start_date' => $row['start_date'] === null ? null : (string) $row['start_date'],
            'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
        ], $rows);
    }

    /**
     * The requested term when it is listed; else the first active one; else the first.
     *
     * @param list<array{id: int, status: string}> $terms
     */
    public function defaultTermId(array $terms, ?int $requested): ?int
    {
        if ($requested !== null && in_array($requested, array_column($terms, 'id'), true)) {
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
     * One row per shown course of the term, ordered by course code.
     *
     * A grade (percentage, band, INC details, finalized, signed off) shows only
     * when the scheme is Published, as on the course's Grades tab. A withdrawn
     * course carries the tag Withdrawn whatever its record says, so it is never
     * counted as passed, failed or in the GWA.
     *
     * @param array{id: int} $term
     *
     * @return list<array{offering_id: int, course_code: string, course_title: string, section_code: string, slug: string, units: float, instructors: string, published: bool, percentage: ?float, band: ?string, band_value: ?float, tag: string, status: string, inc_until: ?string, inc_reason: ?string, finalized: bool, signed_off: bool, withdrawn: bool, updated_at: ?string, scale_id: ?int}>
     */
    public function courses(User $student, array $term): array
    {
        $db = db_connect();

        $rows = $db->table('enrollments e')
            ->select([
                'co.id AS offering_id',
                'co.section_code',
                'c.course_code',
                'c.course_title',
                'c.units',
                'e.status AS enrolment_status',
                'gs.status AS scheme_status',
                'gs.grade_scale_id',
                'gr.id AS record_id',
                'gr.remarks',
                'gr.is_override',
                'gr.overridden_percentage',
                'gr.overridden_grade_scale_item_id',
                'gr.calculated_percentage',
                'gr.calculated_grade_scale_item_id',
                'gr.finalized_at',
                'gr.final_percentage',
                'gr.final_grade_scale_item_id',
                'gr.inc_set_at',
                'gr.inc_reason',
                'gr.inc_until',
                'gr.last_calculated_at',
                'so.id AS signoff_id',
            ])
            ->join('course_offerings co', 'co.id = e.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('grading_schemes gs', 'gs.course_offering_id = co.id', 'left')
            ->join('gradebook_records gr', 'gr.course_offering_id = co.id AND gr.student_id = e.student_user_id', 'left')
            ->join('grade_signoffs so', 'so.offering_id = co.id AND so.open_marker = 1', 'left')
            ->where('e.student_user_id', (int) $student->id)
            ->whereIn('e.status', self::ENROLMENTS)
            ->whereIn('co.status', self::OFFERINGS)
            ->where('co.term_id', (int) $term['id'])
            ->orderBy('c.course_code', 'ASC')
            ->orderBy('co.section_code', 'ASC')
            ->orderBy('co.id', 'ASC')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return [];
        }

        $bands = $this->bands($rows);
        $instructors = $this->instructors(array_map(static fn (array $row): int => (int) $row['offering_id'], $rows));

        return array_map(fn (array $row): array => $this->row($row, $bands, $instructors), $rows);
    }

    /**
     * The term's units, GWA, Passed / Failed / INC counts, and each course left
     * out of the GWA with the first reason that applies.
     *
     * With no default scale there is no GWA, so no course is listed as not
     * counted in it.
     *
     * @param list<array<string, mixed>> $rows from courses()
     *
     * @return array{units: float, gwa: ?float, passed: int, failed: int, inc: int, not_counted: list<array{code: string, reason: string}>}
     */
    public function summary(array $rows, ?int $defaultScaleId): array
    {
        $units = 0.0;
        $weighted = 0.0;
        $counted = 0.0;
        $passed = 0;
        $failed = 0;
        $inc = 0;
        $notCounted = [];

        foreach ($rows as $row) {
            if (!$row['withdrawn']) {
                $units += $row['units'];
            }

            if ($row['finalized'] && $row['tag'] === 'Passed') {
                $passed++;
            }

            if ($row['finalized'] && $row['tag'] === 'Failed') {
                $failed++;
            }

            if ($row['tag'] === 'INC') {
                $inc++;
            }

            if ($defaultScaleId === null) {
                continue;
            }

            $reason = $this->notCountedReason($row, $defaultScaleId);

            if ($reason !== null) {
                $notCounted[] = ['code' => $row['course_code'], 'reason' => $reason];
                continue;
            }

            // Counted only with a band that has a value (a letter-only band has none).
            if ($row['band_value'] !== null) {
                $weighted += $row['band_value'] * $row['units'];
                $counted += $row['units'];
            }
        }

        return [
            'units' => $units,
            'gwa' => $counted > 0 ? round($weighted / $counted, 2) : null,
            'passed' => $passed,
            'failed' => $failed,
            'inc' => $inc,
            'not_counted' => $notCounted,
        ];
    }

    /**
     * The figures of the bar's summary line; units leave withdrawn courses out.
     * `ends` reads "ends Dec 20" until the end date has passed, then "ended Dec 20".
     *
     * @param array{name: string, end_date?: ?string} $term
     * @param list<array<string, mixed>>               $rows  from courses()
     * @param string                                    $today Y-m-d
     *
     * @return array{name: string, courses: int, units: float, ends: ?string}
     */
    public function barLine(array $term, array $rows, string $today): array
    {
        $units = 0.0;

        foreach ($rows as $row) {
            if (!$row['withdrawn']) {
                $units += $row['units'];
            }
        }

        $end = $term['end_date'] ?? null;
        $ends = null;

        if ($end !== null && $end !== '') {
            $ends = ($end < $today ? 'ended ' : 'ends ') . date('M j', strtotime($end));
        }

        return [
            'name' => (string) $term['name'],
            'courses' => count($rows),
            'units' => $units,
            'ends' => $ends,
        ];
    }

    /** @param array<string, mixed> $row */
    private function notCountedReason(array $row, int $defaultScaleId): ?string
    {
        return match (true) {
            $row['withdrawn'] => 'Withdrawn',
            !$row['published'] => 'not published',
            $row['tag'] === 'INC' => 'Incomplete',
            !$row['finalized'] => 'in progress',
            $row['scale_id'] === null => 'no grade scale',
            $row['scale_id'] !== $defaultScaleId => 'a different grade scale',
            default => null,
        };
    }

    /**
     * @param array<string, mixed>                                  $row
     * @param array<int, array{name: string, numeric_value: ?float}> $bands
     * @param array<int, list<string>>                              $instructors
     */
    private function row(array $row, array $bands, array $instructors): array
    {
        $offeringId = (int) $row['offering_id'];
        $published = $row['scheme_status'] === 'Published';
        $record = $row['record_id'] === null ? null : $row;
        $remarks = (string) ($record['remarks'] ?? 'Incomplete');

        $withdrawn = $row['enrolment_status'] === 'withdrawn' || $remarks === 'Withdrawn';
        $finalized = $published && !empty($record['finalized_at']);
        $signedOff = $published && $row['signoff_id'] !== null;
        $incSet = $published && !empty($record['inc_set_at']) && !$withdrawn;

        $band = null;

        if ($published) {
            $bandId = GradebookRecordModel::effectiveBandId($record);
            $band = $bandId === null ? null : ($bands[$bandId] ?? null);
        }

        $tag = match (true) {
            $withdrawn => 'Withdrawn',
            !$published => 'Incomplete',
            $incSet => 'INC',
            default => GradingEngineService::displayTag($remarks, $finalized),
        };

        $status = match (true) {
            !$published => 'not_published',
            $withdrawn => 'withdrawn',
            $signedOff => 'signed_off',
            $finalized => 'finalized',
            default => 'in_progress',
        };

        return [
            'offering_id' => $offeringId,
            'course_code' => (string) $row['course_code'],
            'course_title' => (string) $row['course_title'],
            'section_code' => (string) $row['section_code'],
            'slug' => url_title((string) $row['course_title'], '-', true),
            'units' => (float) $row['units'],
            'instructors' => implode(', ', $instructors[$offeringId] ?? []),
            'published' => $published,
            'percentage' => $published ? GradebookRecordModel::effectivePercentage($record) : null,
            'band' => $band['name'] ?? null,
            'band_value' => $band['numeric_value'] ?? null,
            'tag' => $tag,
            'status' => $status,
            'inc_until' => $incSet && $record['inc_until'] !== null ? (string) $record['inc_until'] : null,
            'inc_reason' => $incSet && $record['inc_reason'] !== null ? (string) $record['inc_reason'] : null,
            'finalized' => $finalized,
            'signed_off' => $signedOff,
            'withdrawn' => $withdrawn,
            'updated_at' => $published && $record !== null && $record['last_calculated_at'] !== null ? (string) $record['last_calculated_at'] : null,
            'scale_id' => $row['grade_scale_id'] === null ? null : (int) $row['grade_scale_id'],
        ];
    }

    /**
     * The bands the rows' records point at, in one query.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, array{name: string, numeric_value: ?float}>
     */
    private function bands(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $id = $row['record_id'] === null ? null : GradebookRecordModel::effectiveBandId($row);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $bands = [];

        foreach (db_connect()->table('grade_scale_items')
            ->select(['id', 'name', 'numeric_value'])
            ->whereIn('id', array_values($ids))
            ->get()->getResultArray() as $item) {
            $bands[(int) $item['id']] = [
                'name' => (string) $item['name'],
                'numeric_value' => $item['numeric_value'] === null ? null : (float) $item['numeric_value'],
            ];
        }

        return $bands;
    }

    /**
     * Instructor names per offering, in one query, by last then first name.
     *
     * @param list<int> $offeringIds
     *
     * @return array<int, list<string>>
     */
    private function instructors(array $offeringIds): array
    {
        $names = [];

        foreach (db_connect()->table('instructor_assignments ia')
            ->select(['ia.offering_id', 'u.username', 'up.first_name', 'up.last_name'])
            ->join('users u', 'u.id = ia.instructor_user_id')
            ->join('user_profiles up', 'up.user_id = ia.instructor_user_id', 'left')
            ->whereIn('ia.offering_id', $offeringIds)
            ->orderBy('up.last_name', 'ASC')
            ->orderBy('up.first_name', 'ASC')
            ->orderBy('ia.instructor_user_id', 'ASC')
            ->get()->getResultArray() as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $names[(int) $row['offering_id']][] = $name !== '' ? $name : (string) $row['username'];
        }

        return $names;
    }
}
