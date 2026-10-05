<?php

declare(strict_types=1);

use App\Services\Courses\ActivityFeedService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The Overview's Recent Activity feed: what each role is told, the 30-day
 * window, how bulk events collapse into one line, and the six-line cap.
 *
 * @internal
 */
final class ActivityFeedServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ActivityFeedService $feed;

    private int $offering;

    private int $module;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = new ActivityFeedService();
        $this->offering = CourseFactory::offering();
        $this->module = CourseFactory::module($this->offering);

        $this->instructor = $this->person('instructor', 'Tess', 'Teacher');
        $this->student = $this->person('student', 'Sam', 'Student');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->student);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function person(string $group, string $first, string $last): User
    {
        $user = CourseFactory::user($group);
        db_connect()->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last]);

        return $user;
    }

    private function ago(string $interval): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$interval}"));
    }

    /** An assessment row the way AssessmentOverviewService builds them. */
    private function row(int $id, string $title, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'title' => $title, 'total_points' => 20, 'status' => 'Published', 'assessment_type' => 'Quiz',
            'available_from' => $this->ago('40 days'), 'created_at' => $this->ago('50 days'), 'due_ts' => null,
        ];
    }

    private function assessment(string $title = 'Quiz'): int
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, $this->module);
        db_connect()->table('assessments')->where('id', $id)->update(['title' => $title]);

        return $id;
    }

    private function graded(int $assessment, User $student, float $score, string $gradedAt, ?string $feedback = null): int
    {
        $submission = AssessmentFactory::attempt($assessment, $student, 'Graded', $score, ['graded_at' => $gradedAt]);

        if ($feedback !== null) {
            db_connect()->table('assessment_feedbacks')->insert(['submission_id' => $submission, 'author_id' => $this->instructor->id, 'message' => $feedback, 'created_at' => date('Y-m-d H:i:s')]);
        }

        return $submission;
    }

    private function module(string $title, string $created, ?string $updated = null, string $status = 'published', ?int $offering = null): void
    {
        db_connect()->table('course_modules')->insert([
            'offering_id' => $offering ?? $this->offering, 'title' => $title, 'order_index' => 50, 'status' => $status,
            'created_at' => $created, 'updated_at' => $updated ?? $created,
        ]);
    }

    private function thread(string $title, User $author, string $at, ?int $item = null): void
    {
        db_connect()->table('discussion_threads')->insert([
            'offering_id' => $this->offering, 'learning_item_id' => $item, 'author_id' => $author->id, 'title' => $title, 'body' => '',
            'created_at' => $at, 'last_activity_at' => $at,
        ]);
    }

    private function forStudent(array $rows = []): array
    {
        return $this->feed->forStudent((int) $this->student->id, $this->offering, $rows);
    }

    /** @return list<string> */
    private function titles(array $events): array
    {
        return array_column($events, 'title');
    }

    /*
    |--------------------------------------------------------------------------
    | Student: grades
    |--------------------------------------------------------------------------
    */

    public function testAGradePostedToTheStudentIsListedWithItsScoreAndFeedback(): void
    {
        $a = $this->assessment('Midterm');
        $this->graded($a, $this->student, 17.5, $this->ago('1 day'), 'Great   work\non the  proofs');

        $events = $this->forStudent([$this->row($a, 'Midterm')]);

        $this->assertSame(['Grade posted'], $this->titles($events));
        $this->assertSame('grade', $events[0]['kind']);
        $this->assertSame('Midterm: 17.5/20 — "Great work\non the proofs"', str_replace("\n", '\n', $events[0]['detail']));
        $this->assertSame('Yesterday', $events[0]['when']);
    }

    public function testALongFeedbackIsCutWithAnEllipsis(): void
    {
        $a = $this->assessment('Midterm');
        $this->graded($a, $this->student, 10, $this->ago('1 day'), str_repeat('word ', 30));

        $detail = $this->forStudent([$this->row($a, 'Midterm')])[0]['detail'];

        $this->assertStringContainsString('…"', $detail);
        $this->assertLessThan(120, mb_strlen($detail));
    }

    public function testOnlyThisStudentsRecentGradedWorkOnVisibleAssessmentsCounts(): void
    {
        $a = $this->assessment('Mine');
        $hidden = $this->assessment('Not visible to the student');
        $classmate = $this->person('student', 'Cam', 'Mate');

        $this->graded($a, $classmate, 5, $this->ago('1 day'));
        $this->graded($hidden, $this->student, 5, $this->ago('1 day'));
        AssessmentFactory::attempt($a, $this->student, 'Submitted');

        $this->assertSame([], $this->forStudent([$this->row($a, 'Mine')]), 'nobody else\'s grade, none for hidden work, nothing ungraded');

        $this->graded($a, $this->student, 5, $this->ago('31 days'));
        $this->assertSame([], $this->forStudent([$this->row($a, 'Mine')]), 'older than 30 days');

        $this->graded($a, $this->student, 5, $this->ago('29 days'));
        $this->assertSame(['Grade posted'], $this->titles($this->forStudent([$this->row($a, 'Mine')])));
    }

    /*
    |--------------------------------------------------------------------------
    | Student: assessments that opened
    |--------------------------------------------------------------------------
    */

    public function testAnAssessmentThatOpenedRecentlyIsAnnouncedWithItsDueDate(): void
    {
        $events = $this->forStudent([$this->row(1, 'Quiz 1', ['due_ts' => strtotime('+3 days'), 'available_from' => $this->ago('2 days')])]);

        $this->assertSame(['New quiz'], $this->titles($events));
        $this->assertStringStartsWith('Quiz 1 · due ', $events[0]['detail']);
    }

    public function testAnAssessmentWithoutADueDateHasNoDueText(): void
    {
        $events = $this->forStudent([$this->row(1, 'Assignment 1', ['assessment_type' => 'Assignment', 'available_from' => $this->ago('2 days')])]);

        $this->assertSame('New assignment', $events[0]['title']);
        $this->assertSame('Assignment 1', $events[0]['detail']);
    }

    public function testOnlyPublishedAssessmentsInTheWindowAndNotInTheFutureOpen(): void
    {
        $events = $this->forStudent([
            $this->row(1, 'Closed', ['status' => 'Closed', 'available_from' => $this->ago('2 days')]),
            $this->row(2, 'Old', ['available_from' => $this->ago('45 days')]),
            $this->row(3, 'Future', ['available_from' => date('Y-m-d H:i:s', strtotime('+2 days'))]),
            $this->row(4, 'No open date', ['available_from' => null, 'created_at' => $this->ago('3 days')]),
        ]);

        $this->assertSame('No open date', $events[0]['detail']);
        $this->assertCount(1, $events);
    }

    /*
    |--------------------------------------------------------------------------
    | Student: modules and discussions
    |--------------------------------------------------------------------------
    */

    public function testModulesAreNewOrUpdatedAndDraftsOrOtherSectionsNeverShow(): void
    {
        db_connect()->table('course_modules')->where('id', $this->module)->update(['status' => 'draft']);
        $this->module('Brand new', $this->ago('3 days'));
        $this->module('Edited later', $this->ago('20 days'), $this->ago('2 days'));
        $this->module('Secret', $this->ago('1 day'), null, 'draft');
        $this->module('Elsewhere', $this->ago('1 day'), null, 'published', CourseFactory::offering('B'));
        $this->module('Ancient', $this->ago('60 days'));

        $events = $this->forStudent();
        $byDetail = array_column($events, 'title', 'detail');

        $this->assertEquals(['Brand new' => 'New module', 'Edited later' => 'Module updated'], $byDetail);
    }

    public function testAStudentHearsAboutOthersDiscussionsButNotTheirOwnOrDraftItems(): void
    {
        $classmate = $this->person('student', 'Cam', 'Mate');
        $unit = CourseFactory::unit($this->module);
        [$draftItem, $shownItem] = CourseFactory::items($unit, [['status' => 'draft'], ['status' => 'published']]);

        $this->thread('Classmates question', $classmate, $this->ago('1 day'));
        $this->thread('Teachers post', $this->instructor, $this->ago('2 days'));
        $this->thread('My own', $this->student, $this->ago('1 day'));
        $this->thread('On draft item', $classmate, $this->ago('1 day'), $draftItem);
        $this->thread('On shown item', $classmate, $this->ago('3 days'), $shownItem);
        $this->thread('Too old', $classmate, $this->ago('40 days'));

        $events = array_filter($this->forStudent(), static fn (array $e) => $e['kind'] === 'discussion');

        $this->assertEqualsCanonicalizing(
            ['Classmates question · Cam Mate', 'Teachers post · Tess Teacher', 'On shown item · Cam Mate'],
            array_column($events, 'detail')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Collapsing, ordering and the cap
    |--------------------------------------------------------------------------
    */

    public function testBulkEventsInTheSameHourBecomeOneLine(): void
    {
        $hour = $this->ago('3 days');
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $title) {
            $this->module($title, $hour);
        }

        $events = $this->forStudent();

        $this->assertCount(1, $events);
        $this->assertSame('5 new modules', $events[0]['title']);
        $this->assertStringEndsWith(', +2 more', $events[0]['detail']);
        $this->assertCount(4, explode(',', $events[0]['detail']), 'three names and the "+2 more"');
    }

    public function testEventsInDifferentHoursOrOfNonBulkKindsStayApart(): void
    {
        $a = $this->assessment('Q');
        $this->module('Early', date('Y-m-d H:i:s', strtotime('-3 days 10:05')));
        $this->module('Later', date('Y-m-d H:i:s', strtotime('-3 days 13:05')));
        $this->graded($a, $this->student, 1, $this->ago('1 day'));
        $this->graded($a, $this->student, 2, $this->ago('1 day'));

        $events = $this->forStudent([$this->row($a, 'Q', ['available_from' => $this->ago('40 days'), 'created_at' => $this->ago('40 days')])]);

        $this->assertSame(['Grade posted', 'Grade posted', 'New module', 'New module'], array_column($events, 'title'), 'grades are always listed one by one');
    }

    public function testACollapsedLineSaysNewOrUpdatedAccordingToTheModules(): void
    {
        $hour = $this->ago('10 days');
        $this->module('Edit one', $this->ago('30 days'), $hour);
        $this->module('Edit two', $this->ago('30 days'), $hour);

        $this->assertSame('2 modules updated', $this->forStudent()[0]['title']);
    }

    public function testTheFeedIsNewestFirstAndCappedAtSix(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->thread("Topic {$i}", $this->instructor, date('Y-m-d H:i:s', strtotime("-{$i} days")));
        }

        $events = $this->forStudent();

        $this->assertCount(ActivityFeedService::LIMIT, $events);
        $this->assertSame(
            array_map(static fn (int $i) => "Topic {$i} · Tess Teacher", range(1, 6)),
            array_column($events, 'detail')
        );
        $stamps = array_column($events, 'ts');
        $sorted = $stamps;
        rsort($sorted);
        $this->assertSame($sorted, $stamps);
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor
    |--------------------------------------------------------------------------
    */

    public function testAnInstructorSeesWorkHandedInByTheSection(): void
    {
        $a = $this->assessment('Essay');
        $late = $this->person('student', 'Late', 'Larry');
        CourseFactory::enroll($this->offering, $late);
        $dropped = $this->person('student', 'Dee', 'Dropped');
        CourseFactory::enroll($this->offering, $dropped, 'dropped');

        AssessmentFactory::attempt($a, $this->student, 'Submitted', 0.0, ['submitted_at' => $this->ago('2 hours')]);
        AssessmentFactory::attempt($a, $late, 'Submitted', 0.0, ['submitted_at' => $this->ago('1 hour'), 'is_late_submission' => 1]);
        AssessmentFactory::attempt($a, $dropped, 'Submitted', 0.0, ['submitted_at' => $this->ago('1 hour')]);
        $starter = $this->person('student', 'Started', 'Only');
        CourseFactory::enroll($this->offering, $starter);
        AssessmentFactory::attempt($a, $starter, 'In Progress');
        AssessmentFactory::attempt($a, $this->student, 'Submitted', 0.0, ['submitted_at' => $this->ago('40 days')]);

        $events = array_filter($this->feed->forInstructor($this->offering), static fn (array $e) => $e['kind'] === 'submission');
        $events = array_values($events);

        $this->assertSame(['Late Larry submitted work', 'Sam Student submitted work'], array_column($events, 'title'));
        $this->assertSame(['Essay · late', 'Essay'], array_column($events, 'detail'));
    }

    public function testStudentsWhoJoinedAreListedAndABulkEnrolmentIsOneLine(): void
    {
        $one = $this->person('student', 'Solo', 'Joiner');
        CourseFactory::enroll($this->offering, $one);
        db_connect()->table('enrollments')->where('student_user_id', $one->id)->update(['enrolled_at' => $this->ago('10 days')]);

        $events = $this->feed->forInstructor($this->offering);
        $this->assertContains('Solo Joiner joined the section', $this->titles($events));

        $hour = $this->ago('2 days');
        foreach (['Ann', 'Bob', 'Cy', 'Di'] as $name) {
            $s = $this->person('student', $name, 'Bulk');
            CourseFactory::enroll($this->offering, $s);
            db_connect()->table('enrollments')->where('student_user_id', $s->id)->update(['enrolled_at' => $hour]);
        }
        db_connect()->table('enrollments')->where('student_user_id', $this->student->id)->update(['enrolled_at' => $this->ago('90 days')]);

        $events = $this->feed->forInstructor($this->offering);

        $this->assertContains('4 students joined the section', $this->titles($events));
        $this->assertContains('Solo Joiner joined the section', $this->titles($events));
    }

    public function testAnInstructorOnlyHearsOfDiscussionsStudentsStarted(): void
    {
        $unit = CourseFactory::unit($this->module);
        [$item] = CourseFactory::items($unit, [['status' => 'published']]);

        $this->thread('Student question', $this->student, $this->ago('1 day'));
        $this->thread('Own announcement', $this->instructor, $this->ago('1 day'));
        $this->thread('Item discussion', $this->student, $this->ago('1 day'), $item);

        $events = array_filter($this->feed->forInstructor($this->offering), static fn (array $e) => $e['kind'] === 'discussion');

        $this->assertSame(['Student question · Sam Student'], array_column($events, 'detail'));
    }

    public function testNothingHappeningIsAnEmptyFeedForBothRoles(): void
    {
        $this->assertSame([], $this->feed->forStudent((int) $this->student->id, $this->offering, []));
        $this->assertSame([], array_values(array_filter($this->feed->forInstructor($this->offering), static fn (array $e) => $e['kind'] !== 'joined')));
    }
}
