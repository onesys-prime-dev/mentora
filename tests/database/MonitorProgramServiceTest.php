<?php

declare(strict_types=1);

use App\Services\Courses\MonitorProgramFilters;
use App\Services\Courses\MonitorProgramService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Monitor Program's analytics (R5 of the chairperson plan): program
 * snapshot, enrollment/completion, grades, instructor activity and the
 * sortable course comparison — each scoped to the offerings its caller
 * passes in, so these never disagree with the dashboard or the course
 * Overview about the same underlying numbers.
 *
 * @internal
 */
final class MonitorProgramServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private MonitorProgramService $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monitor = new MonitorProgramService();
    }

    /** @return array{sub: int, program: int, institution: int} */
    private function subDepartment(string $tag): array
    {
        $db = db_connect();

        $db->table('institutions')->insert(['name' => "Inst {$tag}", 'code' => "I{$tag}"]);
        $institution = (int) $db->insertID();

        $db->table('departments')->insert(['institution_id' => $institution, 'code' => "D{$tag}", 'name' => "College {$tag}"]);
        $department = (int) $db->insertID();

        $db->table('sub_departments')->insert(['department_id' => $department, 'code' => "S{$tag}", 'name' => "Department {$tag}"]);
        $sub = (int) $db->insertID();

        $db->table('programs')->insert(['sub_department_id' => $sub, 'code' => "P{$tag}", 'name' => "Program {$tag}"]);

        return ['sub' => $sub, 'program' => (int) $db->insertID(), 'institution' => $institution];
    }

    private function offering(array $subDepartment, string $courseCode, string $sectionCode = 'A'): int
    {
        $db = db_connect();

        $db->table('academic_terms')->insert([
            'institution_id' => $subDepartment['institution'],
            'name' => 'Term ' . $courseCode,
            'academic_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-20',
        ]);
        $term = (int) $db->insertID();

        $db->table('courses')->insert([
            'program_id' => $subDepartment['program'],
            'course_code' => $courseCode,
            'course_title' => "Course {$courseCode}",
            'units' => 3,
        ]);
        $course = (int) $db->insertID();

        $db->table('course_offerings')->insert([
            'course_id' => $course,
            'term_id' => $term,
            'section_code' => $sectionCode,
        ]);

        return (int) $db->insertID();
    }

    /** A student with a profile — retrieveRosterProgress() inner-joins user_profiles. */
    private function student(int $offeringId, string $status = 'enrolled'): User
    {
        $student = CourseFactory::user('student');

        db_connect()->table('user_profiles')->insert([
            'user_id' => $student->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace' . $student->id,
        ]);

        CourseFactory::enroll($offeringId, $student, $status);

        return $student;
    }

    private function assign(int $userId, int $subDepartmentId): void
    {
        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id' => $subDepartmentId,
            'chairperson_user_id' => $userId,
            'status' => 'active',
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | offeringRows / program scoping
    |--------------------------------------------------------------------------
    */

    public function testOfferingRowsOnlyIncludesOfferingsInTheChairsProgram(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('H');
        $elsewhere = $this->subDepartment('E');
        $this->assign((int) $chair->id, $home['sub']);

        $this->offering($home, 'IT101');
        $this->offering($elsewhere, 'CS101');

        $codes = array_column($this->monitor->offeringRows($chair, new MonitorProgramFilters()), 'course_code');

        $this->assertSame(['IT101'], $codes);
    }

    public function testOfferingRowsNarrowedByTerm(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('H');
        $this->assign((int) $chair->id, $home['sub']);

        $a = $this->offering($home, 'IT101');
        $b = $this->offering($home, 'IT102');

        $termOfA = (int) db_connect()->table('course_offerings')->where('id', $a)->get()->getRowArray()['term_id'];

        $rows = $this->monitor->offeringRows($chair, new MonitorProgramFilters(termId: $termOfA));

        $this->assertSame(['IT101'], array_column($rows, 'course_code'));
    }

    /*
    |--------------------------------------------------------------------------
    | snapshot
    |--------------------------------------------------------------------------
    */

    public function testSnapshotCountsCoursesDistinctInstructorsAndStudents(): void
    {
        $rows = [
            ['instructor_id' => 1, 'enrolled' => 10],
            ['instructor_id' => 1, 'enrolled' => 5],
            ['instructor_id' => 2, 'enrolled' => 7],
            ['instructor_id' => null, 'enrolled' => 0],
        ];

        $this->assertSame(
            ['courses' => 4, 'instructors' => 2, 'students' => 22],
            $this->monitor->snapshot($rows)
        );
    }

    public function testSnapshotOfNoCoursesIsAllZeroes(): void
    {
        $this->assertSame(
            ['courses' => 0, 'instructors' => 0, 'students' => 0],
            $this->monitor->snapshot([])
        );
    }

    /*
    |--------------------------------------------------------------------------
    | enrollmentAndCompletion
    |--------------------------------------------------------------------------
    */

    public function testEnrollmentAndCompletionAveragesAcrossTheClass(): void
    {
        $offering = CourseFactory::offering();
        $unit = CourseFactory::unit(CourseFactory::module($offering));
        $items = CourseFactory::items($unit, array_fill(0, 10, []));

        $full = $this->student($offering);
        foreach ($items as $item) {
            CourseFactory::progress($item, $full);
        }

        $half = $this->student($offering);
        foreach (array_slice($items, 0, 5) as $item) {
            CourseFactory::progress($item, $half);
        }

        $result = $this->monitor->enrollmentAndCompletion([$offering]);

        $this->assertSame(2, $result[$offering]['enrolled']);
        $this->assertSame(75.0, $result[$offering]['completion_percentage']);
    }

    public function testEnrollmentAndCompletionIsNullWithNothingPublished(): void
    {
        $offering = CourseFactory::offering();
        $unit = CourseFactory::unit(CourseFactory::module($offering));
        CourseFactory::items($unit, [['status' => 'draft']]);
        $this->student($offering);

        $result = $this->monitor->enrollmentAndCompletion([$offering]);

        $this->assertSame(1, $result[$offering]['enrolled']);
        $this->assertNull($result[$offering]['completion_percentage']);
    }

    public function testEnrollmentAndCompletionOfNoOfferingsIsEmpty(): void
    {
        $this->assertSame([], $this->monitor->enrollmentAndCompletion([]));
    }

    /*
    |--------------------------------------------------------------------------
    | grades
    |--------------------------------------------------------------------------
    */

    private function record(int $offeringId, User $student, int $schemeId, float $percentage): void
    {
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $offeringId,
            'student_id' => $student->id,
            'grading_scheme_id' => $schemeId,
            'calculated_percentage' => $percentage,
            'is_override' => 0,
        ]);
    }

    public function testGradesAreExcludedWhenTheSchemeIsNotPublished(): void
    {
        $offering = CourseFactory::offering();
        $schemeId = GradeFactory::scheme($offering); // status defaults to 'Active', not 'Published'
        $student = CourseFactory::user('student');
        $this->record($offering, $student, $schemeId, 90.0);

        $this->assertSame([], $this->monitor->grades([$offering]));
    }

    public function testGradesAveragesAndBucketsPublishedPercentages(): void
    {
        $offering = CourseFactory::offering();
        $schemeId = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $schemeId)->update(['status' => 'Published']);

        foreach ([55.0, 92.0, 92.0] as $percentage) {
            $student = $this->student($offering);
            $this->record($offering, $student, $schemeId, $percentage);
        }

        $result = $this->monitor->grades([$offering]);

        $this->assertSame(79.7, $result[$offering]['average_percentage']);
        $this->assertSame(1, $result[$offering]['distribution']['0-59']);
        $this->assertSame(2, $result[$offering]['distribution']['90-100']);
        $this->assertSame(0, $result[$offering]['distribution']['70-79']);
        $this->assertSame(3, $result[$offering]['graded_count']);
        $this->assertSame(3, $result[$offering]['enrolled']);
    }

    public function testGradesHonoursAnOverrideOverTheCalculatedPercentage(): void
    {
        $offering = CourseFactory::offering();
        $schemeId = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $schemeId)->update(['status' => 'Published']);

        $student = CourseFactory::user('student');
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $offering,
            'student_id' => $student->id,
            'grading_scheme_id' => $schemeId,
            'calculated_percentage' => 40.0,
            'is_override' => 1,
            'overridden_percentage' => 85.0,
        ]);

        $result = $this->monitor->grades([$offering]);

        $this->assertSame(85.0, $result[$offering]['average_percentage']);
    }

    public function testGradesOfNoOfferingsIsEmpty(): void
    {
        $this->assertSame([], $this->monitor->grades([]));
    }

    /*
    |--------------------------------------------------------------------------
    | instructorActivity
    |--------------------------------------------------------------------------
    */

    public function testInstructorActivityCountsUpdatesAndAnnouncementsAndTurnaround(): void
    {
        $offering = CourseFactory::offering();
        $module = CourseFactory::module($offering);

        $instructor = CourseFactory::user('instructor');

        db_connect()->table('announcements')->insert([
            'offering_id' => $offering,
            'author_id' => $instructor->id,
            'subject' => 'Welcome',
            'message' => 'Hi',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $assessment = CourseFactory::assessment($offering, $instructor, $module);
        $student = CourseFactory::user('student');

        db_connect()->table('assessment_submissions')->insert([
            'assessment_id' => $assessment,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'submitted_at' => '2026-09-01 08:00:00',
            'graded_at' => '2026-09-03 08:00:00',
            'is_graded' => 1,
            'status' => 'Graded',
        ]);

        $result = $this->monitor->instructorActivity([$offering], new MonitorProgramFilters());

        $this->assertSame(1, $result[$offering]['content_updates']);
        $this->assertSame(1, $result[$offering]['announcements']);
        $this->assertSame(48.0, $result[$offering]['avg_grading_turnaround_hours']);
        $this->assertSame(1, $result[$offering]['graded_count']);
    }

    public function testInstructorActivityIgnoresUngradedSubmissions(): void
    {
        $offering = CourseFactory::offering();
        $module = CourseFactory::module($offering);
        $instructor = CourseFactory::user('instructor');
        $assessment = CourseFactory::assessment($offering, $instructor, $module);
        $student = CourseFactory::user('student');

        db_connect()->table('assessment_submissions')->insert([
            'assessment_id' => $assessment,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'submitted_at' => '2026-09-01 08:00:00',
            'graded_at' => null,
            'is_graded' => 0,
            'status' => 'Submitted',
        ]);

        $result = $this->monitor->instructorActivity([$offering], new MonitorProgramFilters());

        $this->assertNull($result[$offering]['avg_grading_turnaround_hours']);
        $this->assertSame(0, $result[$offering]['graded_count']);
    }

    public function testInstructorActivityNarrowedByDateRangeExcludesOutsideActivity(): void
    {
        $offering = CourseFactory::offering();

        db_connect()->table('course_modules')
            ->where('id', CourseFactory::module($offering))
            ->update(['updated_at' => '2025-01-01 00:00:00']);

        $result = $this->monitor->instructorActivity(
            [$offering],
            new MonitorProgramFilters(from: '2026-01-01', to: '2026-12-31')
        );

        $this->assertSame(0, $result[$offering]['content_updates']);
    }

    public function testInstructorActivityOfNoOfferingsIsEmpty(): void
    {
        $this->assertSame([], $this->monitor->instructorActivity([], new MonitorProgramFilters()));
    }

    /*
    |--------------------------------------------------------------------------
    | atRisk — thin delegate to RosterRiskService
    |--------------------------------------------------------------------------
    */

    public function testAtRiskDelegatesToRosterRiskService(): void
    {
        $offering = CourseFactory::offering();
        $unit = CourseFactory::unit(CourseFactory::module($offering));
        CourseFactory::items($unit, array_fill(0, 4, []));
        $this->student($offering);

        $result = $this->monitor->atRisk([$offering]);

        $this->assertCount(1, $result);
        $this->assertSame('inactive', $result[0]['reason']);
    }

    /*
    |--------------------------------------------------------------------------
    | courseComparison
    |--------------------------------------------------------------------------
    */

    public function testCourseComparisonSortsByEnrollmentDescending(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('H');
        $this->assign((int) $chair->id, $home['sub']);

        $small = $this->offering($home, 'IT101');
        $big = $this->offering($home, 'IT102');

        $this->student($small);
        foreach (range(1, 3) as $ignored) {
            $this->student($big);
        }

        $rows = $this->monitor->courseComparison(
            $this->monitor->offeringRows($chair, new MonitorProgramFilters()),
            new MonitorProgramFilters(),
            sort: 'enrolled',
            dir: 'desc'
        );

        $this->assertSame(['IT102', 'IT101'], array_column($rows, 'course_code'));
    }

    public function testCourseComparisonPutsOfferingsWithNoGradeDataLastRegardlessOfDirection(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('H');
        $this->assign((int) $chair->id, $home['sub']);

        $this->offering($home, 'IT101');

        $graded = $this->offering($home, 'IT102');
        $schemeId = GradeFactory::scheme($graded);
        db_connect()->table('grading_schemes')->where('id', $schemeId)->update(['status' => 'Published']);
        $student = $this->student($graded);
        $this->record($graded, $student, $schemeId, 88.0);

        $rows = $this->monitor->courseComparison(
            $this->monitor->offeringRows($chair, new MonitorProgramFilters()),
            new MonitorProgramFilters(),
            sort: 'average_percentage',
            dir: 'desc'
        );

        $this->assertSame(['IT102', 'IT101'], array_column($rows, 'course_code'));
    }

    /*
    |--------------------------------------------------------------------------
    | auditLog
    |--------------------------------------------------------------------------
    */

    public function testAuditLogShowsOnlyThisChairsOwnChairpersonScopedActions(): void
    {
        $chair = CourseFactory::user('chairperson');
        $other = CourseFactory::user('chairperson');
        $offering = CourseFactory::offering();

        service('auditLogger')->log('course.chairperson_action', $chair, [
            'target_type' => 'course_offering',
            'target_id' => $offering,
            'new' => ['method' => 'POST'],
        ]);

        // Not this chair's action.
        service('auditLogger')->log('course.chairperson_action', $other, [
            'target_type' => 'course_offering',
            'target_id' => $offering,
            'new' => ['method' => 'POST'],
        ]);

        // This chair's action, but not a chairperson-scoped one.
        service('auditLogger')->log('course.announcement_created', $chair, [
            'target_type' => 'course_offering',
            'target_id' => $offering,
        ]);

        $result = $this->monitor->auditLog($chair, [], new MonitorProgramFilters());

        $this->assertSame(1, $result['total']);
        $this->assertSame('Created something', $result['rows'][0]['description']);
    }

    public function testAuditLogDescribesEachHttpMethod(): void
    {
        $chair = CourseFactory::user('chairperson');
        $offering = CourseFactory::offering();

        foreach ([['POST'], ['PATCH'], ['DELETE'], [null]] as [$method]) {
            service('auditLogger')->log('course.chairperson_action', $chair, [
                'target_type' => 'course_offering',
                'target_id' => $offering,
                'new' => $method === null ? [] : ['method' => $method],
            ]);
        }

        $descriptions = array_column($this->monitor->auditLog($chair, [], new MonitorProgramFilters(), perPage: 10)['rows'], 'description');

        $this->assertSame(['Made a change', 'Deleted something', 'Updated something', 'Created something'], $descriptions);
    }

    public function testAuditLogAttachesTheCourseLabelFromTheGivenOfferingRows(): void
    {
        $chair = CourseFactory::user('chairperson');
        $offering = CourseFactory::offering();

        service('auditLogger')->log('course.chairperson_action', $chair, [
            'target_type' => 'course_offering',
            'target_id' => $offering,
            'new' => ['method' => 'POST'],
        ]);

        $offeringRows = [['offering_id' => $offering, 'course_code' => 'IT101', 'section_code' => 'A', 'course_title' => 'Course IT101']];

        $row = $this->monitor->auditLog($chair, $offeringRows, new MonitorProgramFilters())['rows'][0];

        $this->assertSame('IT101', $row['course_code']);
        $this->assertSame('A', $row['section_code']);
        $this->assertNotNull($row['slug']);
    }

    public function testAuditLogWithNoKnownOfferingLeavesTheCourseLabelNull(): void
    {
        $chair = CourseFactory::user('chairperson');
        $offering = CourseFactory::offering();

        service('auditLogger')->log('course.chairperson_action', $chair, [
            'target_type' => 'course_offering',
            'target_id' => $offering,
            'new' => ['method' => 'POST'],
        ]);

        $row = $this->monitor->auditLog($chair, [], new MonitorProgramFilters())['rows'][0];

        $this->assertNull($row['course_code']);
        $this->assertNull($row['slug']);
    }

    public function testAuditLogIsEmptyWithNoActivity(): void
    {
        $chair = CourseFactory::user('chairperson');

        $this->assertSame(['rows' => [], 'total' => 0], $this->monitor->auditLog($chair, [], new MonitorProgramFilters()));
    }

    public function testAuditLogPaginates(): void
    {
        $chair = CourseFactory::user('chairperson');
        $offering = CourseFactory::offering();

        for ($i = 0; $i < 3; $i++) {
            service('auditLogger')->log('course.chairperson_action', $chair, [
                'target_type' => 'course_offering',
                'target_id' => $offering,
                'new' => ['method' => 'POST'],
            ]);
        }

        $result = $this->monitor->auditLog($chair, [], new MonitorProgramFilters(), page: 1, perPage: 2);

        $this->assertSame(3, $result['total']);
        $this->assertCount(2, $result['rows']);
    }

    public function testAuditLogNarrowedByDateRangeExcludesOutsideActivity(): void
    {
        $chair = CourseFactory::user('chairperson');
        $offering = CourseFactory::offering();

        $id = service('auditLogger')->log('course.chairperson_action', $chair, [
            'target_type' => 'course_offering',
            'target_id' => $offering,
            'new' => ['method' => 'POST'],
        ]);

        db_connect()->table('audit_logs')->where('id', $id)->update(['created_at' => '2025-01-01 00:00:00']);

        $result = $this->monitor->auditLog($chair, [], new MonitorProgramFilters(from: '2026-01-01', to: '2026-12-31'));

        $this->assertSame(0, $result['total']);
    }

    public function testCourseComparisonFallsBackToCourseCodeForAnUnknownSortColumn(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('H');
        $this->assign((int) $chair->id, $home['sub']);

        $this->offering($home, 'IT102');
        $this->offering($home, 'IT101');

        $rows = $this->monitor->courseComparison(
            $this->monitor->offeringRows($chair, new MonitorProgramFilters()),
            new MonitorProgramFilters(),
            sort: 'not_a_real_column'
        );

        $this->assertSame(['IT101', 'IT102'], array_column($rows, 'course_code'));
    }
}
