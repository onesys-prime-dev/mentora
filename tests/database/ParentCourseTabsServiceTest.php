<?php

declare(strict_types=1);

use App\Services\Parents\ParentCourseTabs;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * What each tab of a child's course holds for a parent, built from the
 * services the student's own pages use but leaving out everything the parent
 * must not see.
 *
 * @internal
 */
final class ParentCourseTabsServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private User $child;

    private User $teacher;

    private int $offering;

    private ParentCourseTabs $tabs;

    protected function setUp(): void
    {
        parent::setUp();

        $institution    = PeopleFactory::institution('PCT');
        $this->child    = PeopleFactory::person('student', 'Ann', 'Lee', null, $institution);
        $this->teacher  = PeopleFactory::person('instructor', 'Tina', 'Tan', 'tina@school.example', $institution);
        $this->offering = CourseFactory::offering('A');
        $this->tabs     = new ParentCourseTabs();

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->child);
    }

    private function schedule(string $day, string $start, string $end, string $room, string $mode = 'onsite'): void
    {
        db_connect()->table('course_schedules')->insert([
            'offering_id' => $this->offering, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'room' => $room, 'delivery_mode' => $mode,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    /** A course announcement as the composer stores it. */
    private function post(string $subject, string $text, bool $pinned = false, string $when = '2026-10-05 08:00:00', ?int $offering = null): int
    {
        db_connect()->table('announcements')->insert([
            'offering_id' => $offering ?? $this->offering, 'author_id' => $this->teacher->id, 'subject' => $subject,
            'message'     => json_encode(['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => $text]]]]]),
            'is_pinned'   => $pinned ? 1 : 0, 'created_at' => $when, 'updated_at' => $when,
        ]);

        return (int) db_connect()->insertID();
    }

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */

    public function testAnAnnouncementShowsItsSubjectTextAuthorAndDate(): void
    {
        $this->post('Room change', 'We move to room 4.');

        $html = $this->tabs->announcements($this->offering)['html'];

        $this->assertStringContainsString('Room change', $html);
        $this->assertStringContainsString('We move to room 4.', $html);
        $this->assertStringContainsString('Tina Tan', $html);
        $this->assertStringContainsString('Oct 5, 2026', $html);
    }

    public function testPinnedPostsComeFirst(): void
    {
        $this->post('Newer', 'b', false, '2026-10-09 08:00:00');
        $this->post('Pinned and older', 'a', true, '2026-09-01 08:00:00');

        $html = $this->tabs->announcements($this->offering)['html'];

        $this->assertLessThan(strpos($html, 'Newer'), strpos($html, 'Pinned and older'));
        $this->assertStringContainsString('Pinned', $html);
    }

    public function testRepliesReactionsAttachmentsAndFormsNeverAppear(): void
    {
        $id   = $this->post('Quiz tomorrow', 'Bring a pen.');
        $file = CourseFactory::file($this->teacher, 'secret handout');
        db_connect()->table('files')->where('id', $file['id'])->update(['original_filename' => 'private-handout.pdf']);
        db_connect()->table('announcement_attachments')->insert(['announcement_id' => $id, 'file_id' => $file['id'], 'order_index' => 0]);
        db_connect()->table('announcement_replies')->insert(['announcement_id' => $id, 'author_id' => $this->child->id, 'message' => 'Secret reply from Ann', 'created_at' => '2026-10-05 09:00:00']);
        db_connect()->table('announcement_reactions')->insert(['announcement_id' => $id, 'user_id' => $this->child->id, 'emoji' => 'heart', 'created_at' => '2026-10-05 09:00:00']);

        $html = $this->tabs->announcements($this->offering)['html'];

        $this->assertStringContainsString('Bring a pen.', $html);
        $this->assertStringNotContainsString('Secret reply from Ann', $html);
        $this->assertStringNotContainsString('private-handout.pdf', $html);

        foreach (['<form', '<textarea', 'data-react', 'data-announcement-reply', 'data-announcement-composer', 'data-edit-announcement', 'data-toggle-pin'] as $control) {
            $this->assertStringNotContainsString($control, $html, $control);
        }
    }

    public function testOnlyTheTwentyLatestShowWithANote(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->post('Post number ' . $i, 'x', false, sprintf('2026-10-%02d 08:00:00', $i));
        }

        $html = $this->tabs->announcements($this->offering)['html'];

        $this->assertSame(20, substr_count($html, 'data-announcement-id='));
        $this->assertStringContainsString('Post number 25', $html);
        $this->assertStringNotContainsString('Post number 5<', $html);
        $this->assertStringContainsString('Showing the 20 most recent', $html);
    }

    public function testAnAuthorWithTwoRolesDoesNotShowTheirPostTwice(): void
    {
        $this->teacher->addGroup('chairperson');
        $this->post('Only once', 'x');

        $this->assertSame(1, substr_count($this->tabs->announcements($this->offering)['html'], 'data-announcement-id='));
    }

    public function testNoAnnouncementsSaysSo(): void
    {
        $html = $this->tabs->announcements($this->offering)['html'];

        $this->assertStringContainsString('No announcements yet', $html);
        $this->assertStringNotContainsString('most recent', $html);
    }

    public function testAnotherCoursesPostsAreNotIncluded(): void
    {
        $other = CourseFactory::offering('B');
        $this->post('Not for this course', 'x', false, '2026-10-05 08:00:00', $other);

        $this->assertStringNotContainsString('Not for this course', $this->tabs->announcements($this->offering)['html']);
    }

    public function testMarkupInASubjectIsEscaped(): void
    {
        $this->post('<script>alert(1)</script>', 'x');

        $html = $this->tabs->announcements($this->offering)['html'];

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | Assessments
    |--------------------------------------------------------------------------
    */

    private function assessment(string $title, string $type = 'Quiz', string $status = 'Published', ?int $due = null): int
    {
        $module = (int) (db_connect()->table('course_modules')->where('offering_id', $this->offering)->get()->getRowArray()['id'] ?? CourseFactory::module($this->offering));
        $id     = CourseFactory::assessment($this->offering, $this->teacher, $module, null, $type);

        db_connect()->table('assessments')->where('id', $id)->update([
            'title' => $title, 'status' => $status, 'total_points' => 10, 'due_date' => date('Y-m-d H:i:s', $due ?? time() + 5 * 86400),
            'available_from' => date('Y-m-d H:i:s', time() - 60 * 86400), 'available_until' => null,
        ]);

        return $id;
    }

    private function grade(int $assessment, float $score): void
    {
        db_connect()->table('assessment_submissions')->insert([
            'assessment_id' => $assessment, 'student_id' => $this->child->id, 'attempt_number' => 1, 'score' => $score,
            'is_graded' => 1, 'status' => 'Graded', 'graded_by' => $this->teacher->id, 'graded_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function assessments(): string
    {
        return $this->tabs->assessments($this->child, $this->offering, (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'])['html'];
    }

    public function testAssessmentsAreListedByTypeWithDueDateAndStatus(): void
    {
        $this->assessment('Quiz Three', 'Quiz', 'Published', strtotime('2026-12-24 17:00:00'));
        $this->assessment('Final Project', 'Assignment', 'Published', strtotime('2026-12-28 09:00:00'));

        $html = $this->assessments();

        $this->assertStringContainsString('Quiz Three', $html);
        $this->assertStringContainsString('Final Project', $html);
        $this->assertStringContainsString('Quiz', $html);
        $this->assertStringContainsString('Assignment', $html);
        $this->assertStringContainsString('Thu 24 Dec, 5:00 PM', $html);
        $this->assertStringContainsString('Not started', $html);
    }

    public function testADraftAssessmentIsNotShown(): void
    {
        $this->assessment('Hidden draft', 'Quiz', 'Draft');

        $this->assertStringNotContainsString('Hidden draft', $this->assessments());
    }

    public function testAGradedQuizShowsGradedAndNeverItsScore(): void
    {
        $this->grade($this->assessment('Quiz Two'), 7.37);

        $html = $this->assessments();

        $this->assertStringContainsString('Quiz Two', $html);
        $this->assertStringContainsString('Graded', $html);
        $this->assertStringNotContainsString('7.37', $html);
        $this->assertStringNotContainsString('7.4', $html);
    }

    public function testWorkNotHandedInIsPlainlyMissing(): void
    {
        $this->assessment('Forgotten Essay', 'Assignment', 'Published', time() - 3 * 86400);

        $html = $this->assessments();

        $this->assertStringContainsString('Forgotten Essay', $html);
        $this->assertMatchesRegularExpression('/Missed|Overdue/', $html);
    }

    public function testNothingLeadsIntoAQuizOrAnAttempt(): void
    {
        $this->assessment('Quiz Three');

        $html = $this->assessments();

        foreach (['/take', '/view', 'data-assessment', 'href=', '<form', '<button'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, $forbidden);
        }
    }

    public function testNoAssessmentsSaysSo(): void
    {
        $this->assertStringContainsString('No assessments yet', $this->assessments());
    }

    public function testAStudentWhoIsAlsoStaffHasNoAssessmentData(): void
    {
        $this->child->addGroup('chairperson');
        $this->assessment('Would be listed');

        $html = $this->assessments();

        $this->assertStringNotContainsString('Would be listed', $html);
        $this->assertStringContainsString('No assessments yet', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | Grades
    |--------------------------------------------------------------------------
    */

    /**
     * A scheme the child has a standing in: Quizzes 40% (one quiz scored $quiz out of 10) and
     * Exams 60% (a manual "Midterm" scored $exam out of 100). Passing mark 75.
     *
     * @return array{scheme: int, quiz: int}
     */
    private function scheme(float $quiz = 5.0, float $exam = 80.0, bool $published = true): array
    {
        $scheme   = GradeFactory::scheme($this->offering, 75.0);
        $quizzes  = GradeFactory::category($scheme, 'Quizzes', 40);
        $exams    = GradeFactory::category($scheme, 'Exams', 60, null, 2);
        $quizId   = $this->assessment('Gradebook Quiz');
        $quizItem = GradeFactory::item($scheme, $quizzes, 'Quiz', 10, 1, $quizId);
        $midterm  = GradeFactory::item($scheme, $exams, 'Midterm', 100, 2);

        GradeFactory::score($this->offering, $this->child, $quizItem, $quiz);
        GradeFactory::score($this->offering, $this->child, $midterm, $exam);
        $this->grade($quizId, 7.37);

        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => $published ? 'Published' : 'Active', 'needs_recalculation' => 0]);

        return ['scheme' => $scheme, 'quiz' => $quizId];
    }

    private function grades(): string
    {
        return $this->tabs->grades($this->child, db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray())['html'];
    }

    private function finalize(string $remarks = 'Passed'): void
    {
        $this->grades(); // calculates the record

        // Finalizing freezes the calculated percentage, which is what a final grade then shows.
        db_connect()->table('gradebook_records')->where(['course_offering_id' => $this->offering, 'student_id' => $this->child->id])
            ->set('final_percentage', 'calculated_percentage', false)
            ->update(['remarks' => $remarks, 'finalized_at' => date('Y-m-d H:i:s')]);
    }

    public function testTheGradesTabShowsTheStandingAndCategoryPercentages(): void
    {
        $this->scheme(5.0, 80.0);

        $html = $this->grades();

        $this->assertStringContainsString('68%', $html, 'overall: 40% of 50 plus 60% of 80');
        $this->assertStringContainsString('So far', $html);
        $this->assertStringContainsString('At risk', $html);
        $this->assertStringContainsString('Quizzes', $html);
        $this->assertStringContainsString('50%', $html);
        $this->assertStringContainsString('Exams', $html);
        $this->assertStringContainsString('80%', $html);
        $this->assertStringContainsString('40%', $html, 'a category\'s weight');
    }

    public function testNoItemNamesOrScoresAreShown(): void
    {
        $this->scheme();

        $html = $this->grades();

        foreach (['Midterm', 'Gradebook Quiz', '7.37', ' pts', 'points', '80 / 100', 'data-assessment', 'Excused'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, $forbidden);
        }
    }

    public function testADraftSchemeSaysTheGradesAreNotPublished(): void
    {
        $this->scheme(10.0, 100.0, false);

        $html = $this->grades();

        $this->assertStringContainsString('Grades are not published yet', $html);
        $this->assertStringNotContainsString('So far', $html);
        $this->assertStringNotContainsString('100%', $html);
        $this->assertStringNotContainsString('data-my-grade-report', $html);
    }

    public function testACourseWithNoSchemeSaysSoAndNoSchemeIsCreated(): void
    {
        $html = $this->grades();

        $this->assertStringContainsString('Grades are not published yet', $html);
        $this->assertSame(0, db_connect()->table('grading_schemes')->where('course_offering_id', $this->offering)->countAllResults());
    }

    public function testTheReportButtonIsOnlyThereOnceTheGradesAreFinal(): void
    {
        $this->scheme(10.0, 90.0);

        $this->assertStringNotContainsString('data-my-grade-report', $this->grades());

        $this->finalize();

        $html = $this->grades();

        $this->assertStringContainsString('data-my-grade-report', $html);
        $this->assertStringContainsString('Final', $html);
        $this->assertStringContainsString('Passed', $html);
        $this->assertStringContainsString('data-report-path="mine"', $html);
    }

    public function testIncAndWithdrawnReplaceAtRisk(): void
    {
        $this->scheme(5.0, 50.0);
        $this->finalize('INC');

        $html = $this->grades();

        $this->assertStringContainsString('INC', $html);
        $this->assertStringNotContainsString('At risk', $html);
    }

    public function testAStudentWhoIsAlsoStaffHasNoStanding(): void
    {
        $this->scheme(10.0, 100.0);
        $this->child->addGroup('chairperson');

        $html = $this->grades();

        $this->assertStringContainsString('Grades are not published yet', $html);
        $this->assertStringNotContainsString('100%', $html);
    }

    public function testASchemeWithPeriodsShowsEachPeriodAndItsCategories(): void
    {
        $scheme = GradeFactory::scheme($this->offering, 75.0, true);
        $period = GradeFactory::period($scheme, 'Prelims', 100);
        $cat    = GradeFactory::category($scheme, 'Written Work', 100, $period);
        $item   = GradeFactory::item($scheme, $cat, 'Hidden item name', 10, 1);
        GradeFactory::score($this->offering, $this->child, $item, 9);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        $html = $this->grades();

        $this->assertStringContainsString('Prelims', $html);
        $this->assertStringContainsString('Written Work', $html);
        $this->assertStringContainsString('90%', $html);
        $this->assertStringNotContainsString('Hidden item name', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | Overview
    |--------------------------------------------------------------------------
    */

    public function testTheOverviewHasTheCourseFactsOnly(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['description' => 'We learn how computers work.']);
        $this->schedule('mon', '09:00:00', '10:30:00', 'Room 4');
        $this->schedule('wed', '09:00:00', '10:30:00', 'Room 4');

        $html = $this->tabs->overview($this->offering)['html'];

        $this->assertStringContainsString('We learn how computers work.', $html);
        $this->assertStringContainsString('Tina Tan', $html);
        $this->assertStringContainsString('tina@school.example', $html);
        $this->assertStringContainsString('Mon, Wed', $html);
        $this->assertStringContainsString('9:00 AM', $html);
        $this->assertStringContainsString('Room 4', $html);
    }

    public function testTheOverviewHasNothingOfTheStudentsOwnProgress(): void
    {
        $html = $this->tabs->overview($this->offering)['html'];

        $this->assertStringNotContainsStringIgnoringCase('continue where you left off', $html);
        $this->assertStringNotContainsString('/modules', $html);
        $this->assertStringNotContainsStringIgnoringCase('recent activity', $html);
        $this->assertStringNotContainsStringIgnoringCase('enrolled', $html, 'no class size');
    }

    public function testACourseWithNoTeacherOrScheduleSaysSo(): void
    {
        db_connect()->table('instructor_assignments')->where('offering_id', $this->offering)->delete();

        $html = $this->tabs->overview($this->offering)['html'];

        $this->assertStringContainsString('Not assigned yet', $html);
        $this->assertStringContainsString('Not scheduled yet', $html);
    }
}
