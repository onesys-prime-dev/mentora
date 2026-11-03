<?php

declare(strict_types=1);

use App\Services\Grades\StudentGradesOverview;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The terms, course rows and summary behind the student Grades page.
 *
 * @internal
 */
final class StudentGradesOverviewTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private User $student;

    private StudentGradesOverview $overview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = CourseFactory::user('student');
        $this->overview = new StudentGradesOverview();
    }

    /** An offering the student is enrolled in, moved into $termId when given. */
    private function enrolled(?int $termId = null, string $status = 'enrolled', string $section = 'A'): int
    {
        $offering = CourseFactory::offering($section);
        CourseFactory::enroll($offering, $this->student, $status);

        if ($termId !== null) {
            db_connect()->table('course_offerings')->where('id', $offering)->update(['term_id' => $termId]);
        }

        return $offering;
    }

    private function termOf(int $offering): int
    {
        return (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRow()->term_id;
    }

    private function courseOf(int $offering): int
    {
        return (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRow()->course_id;
    }

    /** @return array<string, mixed> */
    private function term(int $termId): array
    {
        foreach ($this->overview->terms($this->student) as $term) {
            if ($term['id'] === $termId) {
                return $term;
            }
        }

        $this->fail("Term {$termId} is not listed.");
    }

    /** A grading scheme with the given status; returns [scheme id, scale id]. */
    private function scheme(int $offering, string $status = 'Published', ?int $scaleId = null): array
    {
        $scaleId ??= GradeFactory::scale();
        $scheme = GradeFactory::scheme($offering, 75.0, false, $scaleId);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => $status]);

        return [$scheme, $scaleId];
    }

    /** A band of a scale with a numeric value. */
    private function band(int $scaleId, string $name, ?float $value): int
    {
        $db = db_connect();
        $db->table('grade_scale_items')->insert([
            'grade_scale_id' => $scaleId,
            'name' => $name,
            'numeric_value' => $value,
            'minimum_percentage' => 0,
            'maximum_percentage' => 100,
            'sort_order' => 99,
        ]);

        return (int) $db->insertID();
    }

    /** @param array<string, mixed> $fields */
    private function record(int $offering, array $fields): void
    {
        db_connect()->table('gradebook_records')->insert($fields + [
            'course_offering_id' => $offering,
            'student_id' => $this->student->id,
            'is_override' => 0,
            'remarks' => 'Passed',
        ]);
    }

    private function finalized(int $offering, float $percentage, ?int $bandId, string $remarks = 'Passed'): void
    {
        $this->record($offering, [
            'calculated_percentage' => $percentage,
            'calculated_grade_scale_item_id' => $bandId,
            'final_percentage' => $percentage,
            'final_grade_scale_item_id' => $bandId,
            'finalized_at' => '2026-12-01 10:00:00',
            'remarks' => $remarks,
        ]);
    }

    private function units(int $offering, float $units): void
    {
        db_connect()->table('courses')->where('id', $this->courseOf($offering))->update(['units' => $units]);
    }

    /** @return array<int, array<string, mixed>> keyed by offering id */
    private function rows(int $termId): array
    {
        $out = [];

        foreach ($this->overview->courses($this->student, $this->term($termId)) as $row) {
            $out[$row['offering_id']] = $row;
        }

        return $out;
    }

    public function testTermsAndTheDefaultTerm(): void
    {
        $older = $this->enrolled();
        $newer = $this->enrolled();
        CourseFactory::offering(); // a term the student is not in

        $db = db_connect();
        $db->table('academic_terms')->where('id', $this->termOf($older))->update(['start_date' => '2025-08-01', 'end_date' => '2025-12-20', 'status' => 'completed']);
        $db->table('academic_terms')->where('id', $this->termOf($newer))->update(['start_date' => '2026-08-01', 'status' => 'upcoming']);

        $terms = $this->overview->terms($this->student);

        $this->assertSame([$this->termOf($newer), $this->termOf($older)], array_column($terms, 'id'));
        $this->assertSame(['id', 'name', 'academic_year', 'status', 'start_date', 'end_date'], array_keys($terms[0]));
        $this->assertIsInt($terms[0]['id']);
        $this->assertSame('2025-12-20', $terms[1]['end_date']);

        $this->assertSame($this->termOf($newer), $this->overview->defaultTermId($terms, null), 'no active term: the first');
        $this->assertSame($this->termOf($older), $this->overview->defaultTermId($terms, $this->termOf($older)));
        $this->assertSame($this->termOf($newer), $this->overview->defaultTermId($terms, 99999), 'an unlisted term is ignored');

        $db->table('academic_terms')->where('id', $this->termOf($older))->update(['status' => 'active']);
        $this->assertSame($this->termOf($older), $this->overview->defaultTermId($this->overview->terms($this->student), null), 'the active term first');

        $this->assertNull($this->overview->defaultTermId([], null));
    }

    public function testEachTermListsOnlyItsOwnCourses(): void
    {
        $first = $this->enrolled();
        $second = $this->enrolled();

        // The same course in both terms.
        db_connect()->table('course_offerings')->where('id', $second)->update(['course_id' => $this->courseOf($first)]);

        $this->assertSame([$first], array_keys($this->rows($this->termOf($first))));
        $this->assertSame([$second], array_keys($this->rows($this->termOf($second))));
    }

    public function testDroppedAndArchivedAreLeftOut(): void
    {
        $kept = $this->enrolled();
        $termId = $this->termOf($kept);
        $completed = $this->enrolled($termId, 'completed');
        $withdrawn = $this->enrolled($termId, 'withdrawn');
        $this->enrolled($termId, 'dropped');
        $archived = $this->enrolled($termId);
        db_connect()->table('course_offerings')->where('id', $archived)->update(['status' => 'archived']);

        // A term whose only course was dropped is not listed.
        $droppedOnly = $this->enrolled(null, 'dropped');
        // Nor one whose only course is archived.
        $archivedOnly = $this->enrolled();
        db_connect()->table('course_offerings')->where('id', $archivedOnly)->update(['status' => 'archived']);

        $ids = array_keys($this->rows($termId));
        sort($ids);
        $this->assertSame([$kept, $completed, $withdrawn], $ids);

        $termIds = array_column($this->overview->terms($this->student), 'id');
        $this->assertSame([$termId], $termIds);
        $this->assertNotContains($this->termOf($droppedOnly), $termIds);
        $this->assertNotContains($this->termOf($archivedOnly), $termIds);
    }

    public function testStatusesInOrder(): void
    {
        $notPublished = $this->enrolled();
        $termId = $this->termOf($notPublished);
        $withdrawnEnrolment = $this->enrolled($termId, 'withdrawn');
        $withdrawnRecord = $this->enrolled($termId);
        $signedOff = $this->enrolled($termId);
        $finalized = $this->enrolled($termId);
        $inProgress = $this->enrolled($termId);

        $this->scheme($notPublished, 'Draft');
        $this->finalized($notPublished, 90, null);

        foreach ([$withdrawnEnrolment, $withdrawnRecord, $signedOff, $finalized, $inProgress] as $offering) {
            $this->scheme($offering);
        }

        $this->finalized($withdrawnEnrolment, 90, null);
        $this->record($withdrawnRecord, ['calculated_percentage' => 50, 'remarks' => 'Withdrawn']);
        $this->finalized($signedOff, 88, null);
        $this->finalized($finalized, 70, null, 'Failed');
        $this->record($inProgress, ['calculated_percentage' => 82.5, 'remarks' => 'Passed', 'last_calculated_at' => '2026-11-02 09:00:00']);

        $dean = CourseFactory::user('dean');
        db_connect()->table('grade_signoffs')->insert(['offering_id' => $signedOff, 'signed_by' => $dean->id, 'signed_at' => '2026-12-02 10:00:00', 'open_marker' => 1]);
        // An ended sign-off does not count.
        db_connect()->table('grade_signoffs')->insert(['offering_id' => $finalized, 'signed_by' => $dean->id, 'signed_at' => '2026-12-02 10:00:00', 'reopened_at' => '2026-12-03 10:00:00', 'open_marker' => null]);

        $rows = $this->rows($termId);

        $this->assertSame('not_published', $rows[$notPublished]['status']);
        $this->assertSame('withdrawn', $rows[$withdrawnEnrolment]['status']);
        $this->assertTrue($rows[$withdrawnEnrolment]['withdrawn']);
        $this->assertSame('withdrawn', $rows[$withdrawnRecord]['status']);
        $this->assertSame('Withdrawn', $rows[$withdrawnRecord]['tag']);
        $this->assertSame('signed_off', $rows[$signedOff]['status']);
        $this->assertTrue($rows[$signedOff]['signed_off']);
        $this->assertTrue($rows[$signedOff]['finalized']);
        $this->assertSame('finalized', $rows[$finalized]['status']);
        $this->assertFalse($rows[$finalized]['signed_off']);
        $this->assertSame('Failed', $rows[$finalized]['tag']);
        $this->assertSame('in_progress', $rows[$inProgress]['status']);
        $this->assertSame('On track', $rows[$inProgress]['tag'], 'a running grade reads On track, not Passed');
        $this->assertSame(82.5, $rows[$inProgress]['percentage']);
        $this->assertFalse($rows[$inProgress]['finalized']);
        $this->assertSame('2026-11-02 09:00:00', $rows[$inProgress]['updated_at']);

        $row = $rows[$inProgress];
        $this->assertSame([
            'offering_id', 'course_code', 'course_title', 'section_code', 'slug', 'units', 'instructors', 'published',
            'percentage', 'band', 'band_value', 'tag', 'status', 'inc_until', 'inc_reason', 'finalized', 'signed_off',
            'withdrawn', 'updated_at', 'scale_id',
        ], array_keys($row));
        $this->assertSame(3.0, $row['units']);
        $this->assertTrue($row['published']);
        $this->assertIsInt($row['scale_id']);
    }

    public function testRowsAreOrderedByCourseCodeAndNameTheirInstructors(): void
    {
        $b = $this->enrolled();
        $termId = $this->termOf($b);
        $a = $this->enrolled($termId);
        $db = db_connect();
        $db->table('courses')->where('id', $this->courseOf($b))->update(['course_code' => 'ZZ200', 'course_title' => 'Data Structures']);
        $db->table('courses')->where('id', $this->courseOf($a))->update(['course_code' => 'AA100']);

        foreach (['Ana' => 'Reyes', 'Ben' => 'Cruz'] as $first => $last) {
            $instructor = CourseFactory::user('instructor');
            CourseFactory::assignInstructor($b, $instructor);
            $db->table('user_profiles')->insert(['user_id' => $instructor->id, 'first_name' => $first, 'last_name' => $last]);
        }

        $rows = $this->overview->courses($this->student, $this->term($termId));

        $this->assertSame([$a, $b], array_column($rows, 'offering_id'));
        $this->assertSame('Ben Cruz, Ana Reyes', $rows[1]['instructors']);
        $this->assertSame('', $rows[0]['instructors']);
        $this->assertSame('data-structures', $rows[1]['slug']);
        $this->assertSame('ZZ200', $rows[1]['course_code']);
        $this->assertSame('A', $rows[1]['section_code']);
    }

    public function testAnUnpublishedSchemeShowsNoGradeAndCreatesNoScheme(): void
    {
        $draft = $this->enrolled();
        $termId = $this->termOf($draft);
        $noScheme = $this->enrolled($termId);

        [, $scaleId] = $this->scheme($draft, 'Draft');
        $band = $this->band($scaleId, '1.75', 1.75);
        $this->record($draft, [
            'calculated_percentage' => 88.4, 'calculated_grade_scale_item_id' => $band,
            'inc_set_at' => '2026-11-01 10:00:00', 'inc_reason' => 'Missing exam', 'inc_until' => '2026-12-20', 'remarks' => 'INC',
            'last_calculated_at' => '2026-11-02 09:00:00',
        ]);

        $schemesBefore = db_connect()->table('grading_schemes')->countAllResults();
        $rows = $this->rows($termId);

        $this->assertSame($schemesBefore, db_connect()->table('grading_schemes')->countAllResults(), 'looking creates no scheme');

        foreach ([$draft, $noScheme] as $offering) {
            $row = $rows[$offering];
            $this->assertFalse($row['published']);
            $this->assertSame('not_published', $row['status']);
            $this->assertNull($row['percentage']);
            $this->assertNull($row['band']);
            $this->assertNull($row['band_value']);
            $this->assertNull($row['inc_reason']);
            $this->assertNull($row['inc_until']);
            $this->assertFalse($row['finalized']);
            $this->assertNotSame('INC', $row['tag']);
        }

        $this->assertNull($rows[$noScheme]['scale_id']);
    }

    public function testACourseWithNoRecordYetShowsADash(): void
    {
        $offering = $this->enrolled();
        $this->scheme($offering);

        $recordsBefore = db_connect()->table('gradebook_records')->countAllResults();
        $row = $this->rows($this->termOf($offering))[$offering];

        $this->assertSame($recordsBefore, db_connect()->table('gradebook_records')->countAllResults(), 'looking creates no record');
        $this->assertTrue($row['published']);
        $this->assertNull($row['percentage']);
        $this->assertNull($row['band']);
        $this->assertSame('in_progress', $row['status']);
        $this->assertSame('Incomplete', $row['tag']);
        $this->assertNull($row['updated_at']);
    }

    public function testAClassmatesRecordInTheSameCourseIsNeverShown(): void
    {
        $offering = $this->enrolled();
        $classmate = CourseFactory::user('student');
        CourseFactory::enroll($offering, $classmate);

        [, $scaleId] = $this->scheme($offering);
        $high = $this->band($scaleId, '1.25', 1.25);
        $low = $this->band($scaleId, '2.75', 2.75);

        $this->record($offering, ['calculated_percentage' => 93, 'calculated_grade_scale_item_id' => $high]);
        $this->record($offering, ['student_id' => $classmate->id, 'calculated_percentage' => 78, 'calculated_grade_scale_item_id' => $low]);

        $term = $this->term($this->termOf($offering));

        foreach ([[$this->student, 93.0, '1.25', 1.25], [$classmate, 78.0, '2.75', 2.75]] as [$who, $percentage, $band, $value]) {
            $rows = $this->overview->courses($who, $term);

            $this->assertCount(1, $rows, 'one row for the offering, not one per student in it');
            $this->assertSame($offering, $rows[0]['offering_id']);
            $this->assertSame([$percentage, $band, $value], [$rows[0]['percentage'], $rows[0]['band'], $rows[0]['band_value']]);
        }
    }

    public function testTheBandFollowsTheEffectiveBand(): void
    {
        $override = $this->enrolled();
        $termId = $this->termOf($override);
        $final = $this->enrolled($termId);
        $calculated = $this->enrolled($termId);

        [, $scaleId] = $this->scheme($override);
        $this->scheme($final, 'Published', $scaleId);
        $this->scheme($calculated, 'Published', $scaleId);

        $one = $this->band($scaleId, '1.00', 1.0);
        $two = $this->band($scaleId, '2.00', 2.0);
        $three = $this->band($scaleId, '3.00', 3.0);

        $this->record($override, [
            'calculated_percentage' => 80, 'calculated_grade_scale_item_id' => $three,
            'is_override' => 1, 'overridden_percentage' => 97, 'overridden_grade_scale_item_id' => $one,
        ]);
        $this->record($final, [
            'calculated_percentage' => 80, 'calculated_grade_scale_item_id' => $three,
            'final_percentage' => 90, 'final_grade_scale_item_id' => $two, 'finalized_at' => '2026-12-01 10:00:00',
        ]);
        $this->record($calculated, ['calculated_percentage' => 80, 'calculated_grade_scale_item_id' => $three]);

        $rows = $this->rows($termId);

        $this->assertSame(['1.00', 1.0, 97.0], [$rows[$override]['band'], $rows[$override]['band_value'], $rows[$override]['percentage']]);
        $this->assertSame(['2.00', 2.0, 90.0], [$rows[$final]['band'], $rows[$final]['band_value'], $rows[$final]['percentage']]);
        $this->assertSame(['3.00', 3.0, 80.0], [$rows[$calculated]['band'], $rows[$calculated]['band_value'], $rows[$calculated]['percentage']]);
    }

    public function testGwaIsUnitsWeightedOverFinalizedCoursesOnTheDefaultScale(): void
    {
        $three = $this->enrolled();
        $termId = $this->termOf($three);
        $two = $this->enrolled($termId);
        $running = $this->enrolled($termId);
        $other = $this->enrolled($termId);

        $db = db_connect();
        $db->table('courses')->where('id', $this->courseOf($three))->update(['course_code' => 'AA101']);
        $db->table('courses')->where('id', $this->courseOf($two))->update(['course_code' => 'AA102']);
        $db->table('courses')->where('id', $this->courseOf($running))->update(['course_code' => 'IT102']);
        $db->table('courses')->where('id', $this->courseOf($other))->update(['course_code' => 'GE101']);

        [, $default] = $this->scheme($three);
        $this->scheme($two, 'Published', $default);
        $this->scheme($running, 'Published', $default);
        [, $otherScale] = $this->scheme($other);

        $this->units($three, 3);
        $this->units($two, 2);

        $this->finalized($three, 88.4, $this->band($default, '1.75', 1.75));
        $this->finalized($two, 82, $this->band($default, '2.50', 2.50));
        $this->record($running, ['calculated_percentage' => 95, 'calculated_grade_scale_item_id' => $this->band($default, '1.25', 1.25)]);
        $this->finalized($other, 99, $this->band($otherScale, '1.00', 1.0));

        $summary = $this->overview->summary($this->overview->courses($this->student, $this->term($termId)), $default);

        // (3 × 1.75 + 2 × 2.50) / 5 = 2.05
        $this->assertSame(2.05, $summary['gwa']);
        $this->assertSame(3, $summary['passed'], 'the two default-scale courses and the other-scale one are finalized Passed');
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, $summary['inc']);
        $this->assertSame(3.0 + 2.0 + 3.0 + 3.0, $summary['units']);
        $this->assertSame([
            ['code' => 'GE101', 'reason' => 'a different grade scale'],
            ['code' => 'IT102', 'reason' => 'in progress'],
        ], $summary['not_counted']);
    }

    public function testAFailedGradeCountsInTheGwa(): void
    {
        $passed = $this->enrolled();
        $termId = $this->termOf($passed);
        $failed = $this->enrolled($termId);

        [, $scale] = $this->scheme($passed);
        $this->scheme($failed, 'Published', $scale);

        $this->finalized($passed, 90, $this->band($scale, '1.50', 1.5));
        $this->finalized($failed, 60, $this->band($scale, '5.00', 5.0), 'Failed');

        $summary = $this->overview->summary($this->overview->courses($this->student, $this->term($termId)), $scale);

        $this->assertSame(3.25, $summary['gwa']);
        $this->assertSame(1, $summary['passed']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame([], $summary['not_counted']);
    }

    public function testNoCountedCourseOrNoDefaultScaleGivesNoGwa(): void
    {
        $running = $this->enrolled();
        $termId = $this->termOf($running);
        [, $scale] = $this->scheme($running);
        $this->record($running, ['calculated_percentage' => 90, 'calculated_grade_scale_item_id' => $this->band($scale, '1.50', 1.5)]);

        $rows = $this->overview->courses($this->student, $this->term($termId));
        $summary = $this->overview->summary($rows, $scale);
        $this->assertNull($summary['gwa'], 'nothing finalized: no GWA, no division by zero');
        $this->assertSame([['code' => $rows[0]['course_code'], 'reason' => 'in progress']], $summary['not_counted']);

        $finalized = $this->enrolled($termId);
        $this->scheme($finalized, 'Published', $scale);
        $this->finalized($finalized, 90, $this->band($scale, '1.50', 1.5));

        $summary = $this->overview->summary($this->overview->courses($this->student, $this->term($termId)), null);
        $this->assertNull($summary['gwa'], 'no default scale: no GWA');
        $this->assertSame([], $summary['not_counted'], 'with no GWA nothing is "not counted" in it');
        $this->assertSame(6.0, $summary['units']);

        $empty = $this->overview->summary([], $scale);
        $this->assertSame(['units' => 0.0, 'gwa' => null, 'passed' => 0, 'failed' => 0, 'inc' => 0, 'not_counted' => []], $empty);
    }

    public function testIncAndWithdrawn(): void
    {
        $inc = $this->enrolled();
        $termId = $this->termOf($inc);
        $withdrawn = $this->enrolled($termId, 'withdrawn');
        $noScale = $this->enrolled($termId);
        $notPublished = $this->enrolled($termId);

        $db = db_connect();
        $db->table('courses')->where('id', $this->courseOf($inc))->update(['course_code' => 'AA1']);
        $db->table('courses')->where('id', $this->courseOf($withdrawn))->update(['course_code' => 'AA2']);
        $db->table('courses')->where('id', $this->courseOf($noScale))->update(['course_code' => 'AA3']);
        $db->table('courses')->where('id', $this->courseOf($notPublished))->update(['course_code' => 'AA4']);

        [, $scale] = $this->scheme($inc);
        $this->scheme($withdrawn, 'Published', $scale);
        [$noScaleScheme] = $this->scheme($noScale);
        $db->table('grading_schemes')->where('id', $noScaleScheme)->update(['grade_scale_id' => null]);
        $this->units($withdrawn, 5);

        $this->record($inc, [
            'calculated_percentage' => 60, 'remarks' => 'INC', 'inc_set_at' => '2026-11-01 10:00:00',
            'inc_reason' => 'Missing final exam', 'inc_until' => '2026-12-20',
        ]);
        // A finalized grade on a withdrawn enrolment is still not counted.
        $this->finalized($withdrawn, 95, $this->band($scale, '1.00', 1.0));
        $this->finalized($noScale, 91, null);

        $rows = $this->rows($termId);

        $this->assertSame('INC', $rows[$inc]['tag']);
        $this->assertSame('in_progress', $rows[$inc]['status']);
        $this->assertSame('Missing final exam', $rows[$inc]['inc_reason']);
        $this->assertSame('2026-12-20', $rows[$inc]['inc_until']);
        $this->assertSame('Withdrawn', $rows[$withdrawn]['tag']);
        $this->assertTrue($rows[$withdrawn]['withdrawn']);
        $this->assertNull($rows[$noScale]['scale_id']);
        $this->assertSame(91.0, $rows[$noScale]['percentage'], 'a course with no scale shows the percentage alone');

        $summary = $this->overview->summary(array_values($rows), $scale);

        $this->assertNull($summary['gwa']);
        $this->assertSame(1, $summary['inc']);
        $this->assertSame(1, $summary['passed'], 'only the no-scale course: the withdrawn one is not counted');
        $this->assertSame(9.0, $summary['units'], 'units leave the withdrawn course out');
        $this->assertSame([
            ['code' => 'AA1', 'reason' => 'Incomplete'],
            ['code' => 'AA2', 'reason' => 'Withdrawn'],
            ['code' => 'AA3', 'reason' => 'no grade scale'],
            ['code' => 'AA4', 'reason' => 'not published'],
        ], $summary['not_counted']);
    }

    public function testIncompleteRemarksWithAnIncSetReadInc(): void
    {
        $offering = $this->enrolled();
        $this->scheme($offering);
        $this->record($offering, ['calculated_percentage' => null, 'remarks' => 'Incomplete', 'inc_set_at' => '2026-11-01 10:00:00', 'inc_reason' => 'Sick']);

        $row = $this->rows($this->termOf($offering))[$offering];

        $this->assertSame('INC', $row['tag']);
        $this->assertSame(1, $this->overview->summary([$row], null)['inc']);
    }

    public function testBarLine(): void
    {
        $offering = $this->enrolled();
        $termId = $this->termOf($offering);
        $withdrawn = $this->enrolled($termId, 'withdrawn');
        $this->units($offering, 3);
        $this->units($withdrawn, 2);

        $term = $this->term($termId);
        $rows = $this->overview->courses($this->student, $term);

        $this->assertSame(
            ['name' => $term['name'], 'courses' => 2, 'units' => 3.0, 'ends' => 'ends Dec 20'],
            $this->overview->barLine($term, $rows, '2026-11-03')
        );
        $this->assertSame('ends Dec 20', $this->overview->barLine($term, $rows, '2026-12-20')['ends'], 'the last day has not ended yet');
        $this->assertSame('ended Dec 20', $this->overview->barLine($term, $rows, '2026-12-21')['ends']);
        $this->assertNull($this->overview->barLine(['end_date' => null] + $term, $rows, '2026-11-03')['ends']);
        $this->assertSame(['name' => $term['name'], 'courses' => 0, 'units' => 0.0, 'ends' => 'ended Dec 20'], $this->overview->barLine($term, [], '2027-01-05'));
    }

    public function testTheQueryCountDoesNotGrowWithCourses(): void
    {
        $few = $this->busyTerm(3);
        $many = $this->busyTerm(15);

        $this->overview->courses($this->student, $this->term($few)); // warm up
        $fewTerm = $this->term($few);
        $manyTerm = $this->term($many);

        $three = $this->queriesOf(fn () => $this->overview->courses($this->student, $fewTerm));
        $fifteen = $this->queriesOf(fn () => $this->overview->courses($this->student, $manyTerm));

        $this->assertGreaterThan(0, $three);
        $this->assertSame($three, $fifteen);
    }

    private function busyTerm(int $courses): int
    {
        $termId = null;

        for ($i = 0; $i < $courses; $i++) {
            $offering = $this->enrolled($termId);
            $termId ??= $this->termOf($offering);
            [, $scale] = $this->scheme($offering);
            $this->finalized($offering, 90, $this->band($scale, '1.50', 1.5));

            $instructor = CourseFactory::user('instructor');
            CourseFactory::assignInstructor($offering, $instructor);
            db_connect()->table('user_profiles')->insert(['user_id' => $instructor->id, 'first_name' => 'In', 'last_name' => "Structor{$i}"]);
        }

        return $termId;
    }

    private function queriesOf(callable $work): int
    {
        $queries = 0;

        $listener = static function () use (&$queries): void {
            $queries++;
        };

        Events::on('DBQuery', $listener);
        $work();
        Events::removeListener('DBQuery', $listener);

        return $queries;
    }
}
