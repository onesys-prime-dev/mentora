<?php

declare(strict_types=1);

use App\Services\Courses\GradeService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * What the Grades page is built from: the student's own page (final grade
 * only once the scheme is published, an instructor's override winning,
 * per-category breakdown, the assessments they can see) and the
 * instructor's page (assessments by type, class average, passing count,
 * "needs review").
 *
 * @internal
 */
final class GradeServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offeringId;
    private int $courseId;
    private int $moduleId;
    private int $schemeId;
    private int $quizAssessment;
    private int $quizItem;
    private int $examItem;
    private User $instructor;
    private User $ada;
    private User $bob;
    private GradeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GradeService();
        $this->offeringId = CourseFactory::offering();
        $this->courseId = (int) db_connect()->table('course_offerings')->where('id', $this->offeringId)->get()->getRowArray()['course_id'];
        $this->moduleId = CourseFactory::module($this->offeringId);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offeringId, $this->instructor);
        $this->ada = $this->enrolled();
        $this->bob = $this->enrolled();

        // Quizzes 40% (one quiz /10 tied to an assessment) and Exams 60% (a manual exam /100).
        $this->schemeId = GradeFactory::scheme($this->offeringId, 75.0);
        $quizzes = GradeFactory::category($this->schemeId, 'Quizzes', 40);
        $exams = GradeFactory::category($this->schemeId, 'Exams', 60, null, 2);

        $this->quizAssessment = $this->assessment('Quiz', 'Published', 10);
        $this->quizItem = GradeFactory::item($this->schemeId, $quizzes, 'Quiz One', 10, 1, $this->quizAssessment);
        $this->examItem = GradeFactory::item($this->schemeId, $exams, 'Midterm', 100, 2);
    }

    private function enrolled(): User
    {
        $student = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'S', 'last_name' => 'T' . $student->id]);
        CourseFactory::enroll($this->offeringId, $student);

        return $student;
    }

    private function assessment(string $type, string $status, float $points, ?string $title = null): int
    {
        $id = CourseFactory::assessment($this->offeringId, $this->instructor, $this->moduleId, null, $type);

        db_connect()->table('assessments')->where('id', $id)->update(array_filter([
            'status' => $status,
            'total_points' => $points,
            'title' => $title,
        ], static fn ($v) => $v !== null));

        return $id;
    }

    private function attempt(int $assessmentId, User $student, float $score, string $status = 'Graded'): void
    {
        $graded = in_array($status, ['Graded', 'Returned'], true);

        db_connect()->table('assessment_submissions')->insert([
            'assessment_id' => $assessmentId,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'score' => $score,
            'is_graded' => $graded ? 1 : 0,
            'status' => $status,
            'graded_by' => $graded ? $this->instructor->id : null,
            'graded_at' => $graded ? '2026-10-01 10:00:00' : null,
        ]);
    }

    private function publish(bool $published = true): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->schemeId)->update([
            'status' => $published ? 'Published' : 'Active',
            'needs_recalculation' => 0,
        ]);
    }

    private function offering(): array
    {
        return db_connect()->table('course_offerings')->where('id', $this->offeringId)->get()->getRowArray();
    }

    private function studentPage(User $student): array
    {
        return $this->service->buildStudentGrades($student, $this->offering());
    }

    /** The instructor builder is protected: reach it through a subclass. */
    private function instructorPage(): array
    {
        $service = new class () extends GradeService {
            public function page(User $user, array $offering): array
            {
                return $this->buildInstructorGradebook($user, $offering);
            }
        };

        return $service->page($this->instructor, $this->offering());
    }

    /*
    |--------------------------------------------------------------------------
    | show()
    |--------------------------------------------------------------------------
    */

    public function testAnUnknownSectionIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->show($this->ada, 999999);
    }

    /*
    |--------------------------------------------------------------------------
    | Student: final grade
    |--------------------------------------------------------------------------
    */

    public function testTheFinalGradeIsHiddenUntilTheSchemeIsPublished(): void
    {
        GradeFactory::score($this->offeringId, $this->ada, $this->quizItem, 10.0);
        $this->publish();
        $this->studentPage($this->ada); // a grade has been calculated…
        $this->publish(false);          // …but the instructor took the scheme back to draft

        $page = $this->studentPage($this->ada);

        $this->assertSame(1, db_connect()->table('gradebook_records')->where('student_id', $this->ada->id)->countAllResults());
        $this->assertFalse($page['summary']['is_published']);
        $this->assertNull($page['summary']['overall_percentage']);
        $this->assertNull($page['summary']['grade_label']);
        $this->assertSame('Incomplete', $page['summary']['remarks']);
        $this->assertSame([], $page['categoryBreakdown']);
        $this->assertSame([], $page['periodBreakdown']);
        $this->assertSame(75.0, $page['summary']['passing_percentage']);
    }

    public function testAPublishedSchemeShowsTheCalculatedGradeAndBreakdown(): void
    {
        GradeFactory::score($this->offeringId, $this->ada, $this->quizItem, 9.0);
        GradeFactory::score($this->offeringId, $this->ada, $this->examItem, 80.0);
        $this->publish();

        $page = $this->studentPage($this->ada);

        // 90% * 40% + 80% * 60% = 84
        $this->assertTrue($page['summary']['is_published']);
        $this->assertEquals(84, $page['summary']['overall_percentage']);
        $this->assertSame('B', $page['summary']['grade_label']);
        $this->assertSame('Passed', $page['summary']['remarks']);
        $this->assertFalse($page['summary']['is_override']);
        $this->assertEquals(89, $page['summary']['points_earned']);
        $this->assertEquals(110, $page['summary']['points_possible']);
        $this->assertSame(['Quizzes', 'Exams'], array_column($page['categoryBreakdown'], 'name'));
    }

    public function testAStudentNeverCalculatedYetIsCalculatedOnTheFirstVisit(): void
    {
        GradeFactory::score($this->offeringId, $this->ada, $this->examItem, 100.0);
        $this->publish();
        $this->assertSame(0, db_connect()->table('gradebook_records')->where('student_id', $this->ada->id)->countAllResults());

        $page = $this->studentPage($this->ada);

        $this->assertEquals(100, $page['summary']['overall_percentage']);
        $this->assertSame(1, db_connect()->table('gradebook_records')->where('student_id', $this->ada->id)->countAllResults());
    }

    public function testAStudentWithNoGradedWorkIsIncomplete(): void
    {
        $this->publish();

        $page = $this->studentPage($this->bob);

        $this->assertNull($page['summary']['overall_percentage']);
        $this->assertSame('Incomplete', $page['summary']['remarks']);
        $this->assertNull($page['summary']['grade_label']);
    }

    public function testAFailingGradeIsRemarkedFailed(): void
    {
        GradeFactory::score($this->offeringId, $this->ada, $this->examItem, 50.0);
        $this->publish();

        $page = $this->studentPage($this->ada);

        $this->assertEquals(50, $page['summary']['overall_percentage']);
        $this->assertSame('Failed', $page['summary']['remarks']);
        $this->assertSame('F', $page['summary']['grade_label']);
    }

    public function testAnInstructorOverrideWins(): void
    {
        GradeFactory::score($this->offeringId, $this->ada, $this->examItem, 50.0);
        $this->publish();
        $this->studentPage($this->ada); // creates the calculated record

        $bands = db_connect()->table('grade_scale_items')->orderBy('minimum_percentage', 'DESC')->get()->getResultArray();
        $aBand = $bands[0];
        db_connect()->table('gradebook_records')->where('student_id', $this->ada->id)->update([
            'is_override' => 1,
            'overridden_percentage' => 95,
            'overridden_grade_scale_item_id' => $aBand['id'],
            'override_reason' => 'Make-up exam',
        ]);

        $page = $this->studentPage($this->ada);

        $this->assertTrue($page['summary']['is_override']);
        $this->assertEquals(95, $page['summary']['overall_percentage']);
        $this->assertSame($aBand['name'], $page['summary']['grade_label']);
    }

    public function testOtherStudentsGradesAreNotMixedIn(): void
    {
        GradeFactory::score($this->offeringId, $this->ada, $this->examItem, 100.0);
        GradeFactory::score($this->offeringId, $this->bob, $this->examItem, 60.0);
        $this->publish();

        $this->assertEquals(100, $this->studentPage($this->ada)['summary']['overall_percentage']);
        $this->assertEquals(60, $this->studentPage($this->bob)['summary']['overall_percentage']);
    }

    /*
    |--------------------------------------------------------------------------
    | Student: assessments
    |--------------------------------------------------------------------------
    */

    private function rowFor(array $page, int $assessmentId): ?array
    {
        foreach ($page['assessments'] as $row) {
            if ($row['id'] === $assessmentId) {
                return $row;
            }
        }

        return null;
    }

    public function testAGradedAssessmentShowsItsCountedScore(): void
    {
        $this->attempt($this->quizAssessment, $this->ada, 7.0);

        $row = $this->rowFor($this->studentPage($this->ada), $this->quizAssessment);

        $this->assertSame('graded', $row['state']);
        $this->assertSame('Graded', $row['label']);
        $this->assertSame('success', $row['tone']);
        $this->assertEquals(7, $row['score']);
        $this->assertTrue($row['has_grade']);
        $this->assertSame('Quiz', $row['assessment_type']);
        $this->assertSame(10.0, $row['total_points']);
    }

    public function testAnUntouchedAssessmentHasNoGrade(): void
    {
        $row = $this->rowFor($this->studentPage($this->bob), $this->quizAssessment);

        $this->assertFalse($row['has_grade']);
        $this->assertNull($row['score']);
        $this->assertStringStartsWith('Due ', $row['due_label']);
    }

    public function testASubmittedButUngradedAssessmentIsNotAGrade(): void
    {
        $this->attempt($this->quizAssessment, $this->ada, 0.0, 'Submitted');

        $row = $this->rowFor($this->studentPage($this->ada), $this->quizAssessment);

        $this->assertSame('submitted', $row['state']);
        $this->assertFalse($row['has_grade']);
    }

    public function testDraftAssessmentsAreHiddenFromStudents(): void
    {
        $draft = $this->assessment('Quiz', 'Draft', 5);

        $this->assertNull($this->rowFor($this->studentPage($this->ada), $draft));
    }

    public function testAnotherSectionsAssessmentsAreNotListed(): void
    {
        $other = CourseFactory::offering('B');
        $otherId = CourseFactory::assessment($other, $this->instructor, CourseFactory::module($other));
        db_connect()->table('assessments')->where('id', $otherId)->update(['status' => 'Published']);

        $this->assertNull($this->rowFor($this->studentPage($this->ada), $otherId));
    }

    public function testTheSummaryCountsGradedAgainstAllVisibleAssessments(): void
    {
        $second = $this->assessment('Assignment', 'Published', 20);
        $this->assessment('Quiz', 'Draft', 5);
        $this->attempt($this->quizAssessment, $this->ada, 8.0);
        $this->attempt($second, $this->ada, 0.0, 'Submitted');

        $summary = $this->studentPage($this->ada)['summary'];

        $this->assertSame(1, $summary['graded_count']);
        $this->assertSame(2, $summary['total_count']);
    }

    public function testAnOverviewAlreadyBuiltCanBePassedIn(): void
    {
        $overview = ['assessments' => []];

        $page = $this->service->buildStudentGrades($this->ada, $this->offering(), $overview);

        $this->assertSame([], $page['assessments']);
        $this->assertSame(0, $page['summary']['total_count']);
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor
    |--------------------------------------------------------------------------
    */

    public function testTheInstructorPageGroupsAssessmentsByType(): void
    {
        $assignment = $this->assessment('Assignment', 'Published', 20, 'Essay');

        $page = $this->instructorPage();

        $this->assertEqualsCanonicalizing(['Quiz', 'Assignment'], array_keys($page['assessmentGroups']));
        $this->assertSame($this->quizAssessment, (int) $page['assessmentGroups']['Quiz'][0]['id']);
        $this->assertSame($assignment, (int) $page['assessmentGroups']['Assignment'][0]['id']);
        $this->assertSame($this->schemeId, (int) $page['gradebook']['scheme']['id']);
    }

    public function testNeedsReviewCountsSubmittedWorkThatIsNotGradedYet(): void
    {
        $this->attempt($this->quizAssessment, $this->ada, 0.0, 'Submitted');
        $this->attempt($this->quizAssessment, $this->bob, 9.0, 'Graded');

        $page = $this->instructorPage();
        $quiz = $page['assessmentGroups']['Quiz'][0];

        $this->assertSame(1, $quiz['needs_review_count']);
        $this->assertSame(1, $quiz['graded_count']);
        $this->assertSame(1, $page['classSummary']['needs_review']);
    }

    public function testArchivedAssessmentsAreLeftOutOfTheClassWideReviewCount(): void
    {
        $archived = $this->assessment('Assignment', 'Archived', 20);
        $this->attempt($archived, $this->ada, 0.0, 'Submitted');

        $page = $this->instructorPage();

        $this->assertSame(0, $page['classSummary']['needs_review']);
    }

    public function testTheClassSummaryAveragesFinalGradesAndCountsWhoPassed(): void
    {
        GradeFactory::score($this->offeringId, $this->ada, $this->examItem, 100.0);
        GradeFactory::score($this->offeringId, $this->bob, $this->examItem, 50.0);
        $this->publish();
        (new App\Services\Courses\Grades\GradingEngineService())->recalculateOffering($this->offeringId);

        $summary = $this->instructorPage()['classSummary'];

        $this->assertEquals(75.0, $summary['average_percentage']);
        $this->assertSame(1, $summary['passing_count']);
        $this->assertSame(2, $summary['total_students']);
    }

    public function testAnOverrideCountsInTheClassAverage(): void
    {
        GradeFactory::score($this->offeringId, $this->ada, $this->examItem, 100.0);
        GradeFactory::score($this->offeringId, $this->bob, $this->examItem, 50.0);
        $this->publish();
        (new App\Services\Courses\Grades\GradingEngineService())->recalculateOffering($this->offeringId);
        db_connect()->table('gradebook_records')->where('student_id', $this->bob->id)->update(['is_override' => 1, 'overridden_percentage' => 90]);

        $this->assertEquals(95.0, $this->instructorPage()['classSummary']['average_percentage']);
    }

    public function testWithoutAnyFinalGradesTheAverageIsBlank(): void
    {
        $summary = $this->instructorPage()['classSummary'];

        $this->assertNull($summary['average_percentage']);
        $this->assertSame(0, $summary['passing_count']);
        $this->assertSame(2, $summary['total_students']);
    }

    public function testAnEmptyRosterHasNoStudentsAndNoAverage(): void
    {
        $offering = CourseFactory::offering('E');
        GradeFactory::systemDefault();

        $service = new class () extends GradeService {
            public function page(User $user, array $offering): array
            {
                return $this->buildInstructorGradebook($user, $offering);
            }
        };

        $summary = $service->page($this->instructor, db_connect()->table('course_offerings')->where('id', $offering)->get()->getRowArray())['classSummary'];

        $this->assertSame(0, $summary['total_students']);
        $this->assertNull($summary['average_percentage']);
    }

    public function testAnInstructorGetsTheGradebookAndAStudentGetsTheirGrades(): void
    {
        $this->publish();

        $instructorHtml = $this->service->show($this->instructor, $this->offeringId)['html'];
        $studentHtml = $this->service->show($this->ada, $this->offeringId)['html'];

        $this->assertIsString($instructorHtml);
        $this->assertIsString($studentHtml);
        $this->assertNotSame($instructorHtml, $studentHtml);
    }
}
