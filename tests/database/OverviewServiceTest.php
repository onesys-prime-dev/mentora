<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\OverviewService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The course Overview tab: who may open it, where a student resumes, how
 * progress and the deadline tiles are worked out, and what an instructor is
 * told needs them.
 *
 * @internal
 */
final class OverviewServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private OverviewService $service;

    private int $offering;

    private int $course;

    private int $module;

    private int $unit;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OverviewService();

        // A real install always has the system-default grading scheme; reading grades copies it per course.
        GradeFactory::systemDefault();
        $this->offering = CourseFactory::offering();
        $this->course = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'];
        $this->module = CourseFactory::module($this->offering);
        $this->unit = CourseFactory::unit($this->module);

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
        $db = db_connect();
        $db->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last]);
        $db->table('auth_identities')->insert(['user_id' => $user->id, 'type' => 'email_password', 'secret' => strtolower($first) . $user->id . '@example.test', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        return $user;
    }

    /** Calls a protected method. */
    private function call(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod($this->service, $method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    /** @return list<int> item ids */
    private function items(array $specs, ?int $unit = null): array
    {
        return CourseFactory::items($unit ?? $this->unit, $specs);
    }

    private function assessments(User $as): array
    {
        return (new AssessmentOverviewService())->build($as, $this->offering, $this->course);
    }

    private function quiz(string $title, string $due, string $status = 'Published'): int
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, $this->module);
        db_connect()->table('assessments')->where('id', $id)->update(['title' => $title, 'due_date' => $due, 'status' => $status, 'available_from' => date('Y-m-d H:i:s', strtotime('-30 days'))]);

        return $id;
    }

    private function url(): string
    {
        return 'https://example.test/student/courses/1/x/modules';
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    public function testMembersOpenTheOverview(): void
    {
        foreach ([$this->student, $this->instructor] as $user) {
            $html = $this->service->show($user, $this->offering)['html'];

            $this->assertNotSame('', $html);
        }
    }

    public function testPeopleOutsideTheCourseCannotOpenIt(): void
    {
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $dropped, 'dropped');

        $people = [
            'outsider' => CourseFactory::user('student'),
            'dropped' => $dropped,
            'other instructor' => CourseFactory::user('instructor'),
        ];

        foreach ($people as $who => $user) {
            try {
                $this->service->show($user, $this->offering);
                $this->fail("{$who} could open the overview");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnUnknownOfferingIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->show($this->student, 999999);
    }

    public function testTheHeaderNamesTheFirstAssignedInstructorOnACoTaughtCourse(): void
    {
        $second = $this->person('instructor', 'Ben', 'Second');
        CourseFactory::assignInstructor($this->offering, $second);

        $info = (new \App\Models\Courses\CourseOfferingModel())->getCourseOverviewData($this->offering);

        $this->assertSame('Tess Teacher', $info['instructor_name']);
        $this->assertSame((int) $this->offering, (int) $info['offering_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Resuming
    |--------------------------------------------------------------------------
    */

    public function testNothingPublishedMeansAnEmptyState(): void
    {
        $this->items([['status' => 'draft']]);

        $this->assertSame(['state' => 'empty'], $this->call('resumePoint', (int) $this->student->id, $this->offering, $this->url()));
    }

    public function testAStudentWhoHasNotStartedIsSentToTheFirstItem(): void
    {
        [$first] = $this->items([['title' => 'Intro video', 'type' => 'video'], ['title' => 'Reading']]);

        $point = $this->call('resumePoint', (int) $this->student->id, $this->offering, $this->url());

        $this->assertSame('next', $point['state']);
        $this->assertSame('Intro video', $point['title']);
        $this->assertSame('Video', $point['kind']);
        $this->assertFalse($point['started']);
        $this->assertSame(0, $point['completed']);
        $this->assertSame(2, $point['total']);
        $this->assertSame($this->url() . "?item={$first}", $point['href']);
    }

    public function testCompletedItemsAreSkippedAndDraftsNeverOffered(): void
    {
        [$a, $b, $c] = $this->items([['title' => 'One'], ['title' => 'Hidden', 'status' => 'draft'], ['title' => 'Three']]);
        CourseFactory::progress($a, $this->student);

        $point = $this->call('resumePoint', (int) $this->student->id, $this->offering, $this->url());

        $this->assertSame('Three', $point['title']);
        $this->assertTrue($point['started']);
        $this->assertSame(1, $point['completed']);
        $this->assertSame(2, $point['total'], 'the draft is not counted');
    }

    public function testAnItemInProgressWinsAndTheMostRecentOneLeads(): void
    {
        [$a, $b, $c] = $this->items([['title' => 'One'], ['title' => 'Two'], ['title' => 'Three']]);
        CourseFactory::progress($a, $this->student, 'in_progress', 30);
        CourseFactory::progress($c, $this->student, 'in_progress', 60);
        db_connect()->table('learning_completions')->where('learning_item_id', $a)->update(['updated_at' => '2026-01-01 10:00:00']);
        db_connect()->table('learning_completions')->where('learning_item_id', $c)->update(['updated_at' => '2026-02-01 10:00:00']);

        $point = $this->call('resumePoint', (int) $this->student->id, $this->offering, $this->url());

        $this->assertSame('resume', $point['state']);
        $this->assertSame('Three', $point['title']);
        $this->assertSame(60, $point['progress']);
        $this->assertTrue($point['started']);
    }

    public function testFinishingEverythingIsDone(): void
    {
        $ids = $this->items([['title' => 'One'], ['title' => 'Two']]);
        foreach ($ids as $id) {
            CourseFactory::progress($id, $this->student);
        }

        $this->assertSame(['state' => 'done', 'completed' => 2, 'total' => 2], $this->call('resumePoint', (int) $this->student->id, $this->offering, $this->url()));
    }

    public function testResumeFollowsCourseOrderAcrossModulesAndOtherPeoplesWorkDoesNotCount(): void
    {
        $second = CourseFactory::module($this->offering, 'published', 2);
        $secondUnit = CourseFactory::unit($second);
        $this->items([['title' => 'First module item']]);
        $this->items([['title' => 'Second module item']], $secondUnit);
        $classmate = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $classmate);
        CourseFactory::progress($this->items([['title' => 'x']], CourseFactory::unit(CourseFactory::module($this->offering, 'draft', 3)))[0], $classmate);

        $point = $this->call('resumePoint', (int) $this->student->id, $this->offering, $this->url());

        $this->assertSame('First module item', $point['title']);
        $this->assertSame('First module', explode(' item', 'First module item')[0]);
    }

    public static function itemKinds(): array
    {
        return [['pdf', 'PDF'], ['link', 'Link'], ['reading', 'Reading'], ['video', 'Video']];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('itemKinds')]
    public function testItemKindsAreLabelled(string $type, string $label): void
    {
        $this->assertSame($label, $this->call('itemKind', $type));
    }

    /*
    |--------------------------------------------------------------------------
    | Lesson progress
    |--------------------------------------------------------------------------
    */

    public function testLessonProgressCountsPublishedLessonsAndOwnCompletions(): void
    {
        [$a, $b, $c, $d] = $this->items([
            ['type' => 'reading'], ['type' => 'pdf'], ['type' => 'quiz'], ['type' => 'reading', 'status' => 'draft'],
        ]);
        CourseFactory::progress($a, $this->student);
        CourseFactory::progress($b, $this->student, 'in_progress', 40);
        CourseFactory::progress($c, $this->student);
        CourseFactory::progress($d, $this->student);

        $other = CourseFactory::user('student');
        CourseFactory::progress($b, $other);

        $this->assertSame(['total' => 2, 'completed' => 1], $this->call('lessonProgress', (int) $this->student->id, $this->offering));
        $this->assertSame(['total' => 2, 'completed' => 1], $this->call('lessonProgress', (int) $other->id, $this->offering));
    }

    public function testLessonsInAnotherSectionDoNotCount(): void
    {
        $other = CourseFactory::offering('B');
        $this->items([['type' => 'reading']], CourseFactory::unit(CourseFactory::module($other)));

        $this->assertSame(['total' => 0, 'completed' => 0], $this->call('lessonProgress', (int) $this->student->id, $this->offering));
    }

    /*
    |--------------------------------------------------------------------------
    | Student page data
    |--------------------------------------------------------------------------
    */

    public function testProgressCountsLessonsAndTasksTogether(): void
    {
        [$a, $b] = $this->items([['type' => 'reading'], ['type' => 'reading']]);
        CourseFactory::progress($a, $this->student);
        $done = $this->quiz('Done quiz', date('Y-m-d H:i:s', strtotime('+3 days')));
        $this->quiz('Open quiz', date('Y-m-d H:i:s', strtotime('+4 days')));
        AssessmentFactory::attempt($done, $this->student, 'Submitted');

        $data = $this->call('forStudent', $this->student, db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray(), $this->assessments($this->student), $this->url());

        $this->assertSame([
            'percent' => 50, 'lessons_completed' => 1, 'lessons_total' => 2, 'tasks_completed' => 1, 'tasks_total' => 2,
        ], $data['progress']);
        $this->assertSame('next', $data['resume']['state']);
    }

    public function testAnEmptyCourseIsZeroPercentNotAnError(): void
    {
        $data = $this->call('forStudent', $this->student, db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray(), $this->assessments($this->student), $this->url());

        $this->assertSame(0, $data['progress']['percent']);
        $this->assertSame('empty', $data['resume']['state']);
    }

    public function testTheStudentMetricsAreDueThisWeekOverdueAndCurrentGrade(): void
    {
        $metrics = [
            ['label' => 'Due This Week', 'value' => '1 task'],
            ['label' => 'Overdue', 'value' => '0'],
            ['label' => 'Completed', 'value' => '1'],
        ];

        $graded = $this->call('studentMetrics', $metrics, ['overall_percentage' => 87.456, 'remarks' => 'Passed', 'graded_count' => 2, 'total_count' => 3]);

        $this->assertSame(['Due This Week', 'Overdue', 'Current Grade'], array_column($graded, 'label'));
        $this->assertSame('87.5%', $graded[2]['value']);
        $this->assertSame('Passed · 2 of 3 graded', $graded[2]['detail']);
        $this->assertSame('neutral', $graded[2]['tone']);

        $failing = $this->call('studentMetrics', $metrics, ['overall_percentage' => 40.0, 'remarks' => 'Failed', 'graded_count' => 1, 'total_count' => 1]);
        $this->assertSame('danger', $failing[2]['tone']);

        $none = $this->call('studentMetrics', [], ['overall_percentage' => null, 'remarks' => '', 'graded_count' => 0, 'total_count' => 0]);
        $this->assertSame(['Current Grade'], array_column($none, 'label'));
        $this->assertSame('—', $none[0]['value']);
        $this->assertSame('Nothing graded yet', $none[0]['detail']);
    }

    public function testDeadlineTilesShowTheDateToneAndAreCapped(): void
    {
        $soon = strtotime('+2 days');
        $later = strtotime('+20 days');
        $row = static fn (int $id, ?int $due, string $key) => ['id' => $id, 'title' => "T{$id}", 'due_ts' => $due, 'student' => ['key' => $key], 'nextDetail' => "d{$id}"];

        $tiles = $this->call('studentDeadlines', [
            $row(1, strtotime('-2 days'), 'overdue'), $row(2, $soon, 'upcoming'), $row(3, $later, 'upcoming'), $row(4, null, 'upcoming'),
            $row(5, $later, 'x'), $row(6, $later, 'x'),
        ]);

        $this->assertCount(5, $tiles);
        $this->assertSame(['danger', 'warning', 'neutral', 'neutral', 'neutral'], array_column($tiles, 'tone'));
        $this->assertSame(date('M', $soon), $tiles[1]['month']);
        $this->assertSame(date('j', $soon), $tiles[1]['day']);
        $this->assertSame('—', $tiles[3]['day']);
        $this->assertSame('', $tiles[3]['month']);
        $this->assertSame('d2', $tiles[1]['detail']);
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor page data
    |--------------------------------------------------------------------------
    */

    public function testTheInstructorsDeadlinesAreUpcomingPublishedWorkSoonestFirstWithSubmissionCounts(): void
    {
        $this->quiz('Later', date('Y-m-d H:i:s', strtotime('+12 days')));
        $sooner = $this->quiz('Sooner', date('Y-m-d H:i:s', strtotime('+2 days')));
        $this->quiz('Past', date('Y-m-d H:i:s', strtotime('-2 days')));
        $this->quiz('Draft', date('Y-m-d H:i:s', strtotime('+3 days')), 'Draft');
        AssessmentFactory::attempt($sooner, $this->student, 'Submitted');

        $tiles = $this->call('instructorDeadlines', $this->assessments($this->instructor)['assessments']);

        $this->assertSame(['Sooner', 'Later'], array_column($tiles, 'title'));
        $this->assertSame('warning', $tiles[0]['tone']);
        $this->assertSame('neutral', $tiles[1]['tone']);
        $this->assertStringStartsWith('1 of 1 submitted · due ', $tiles[0]['detail']);
        $this->assertStringStartsWith('0 of 1 submitted · due ', $tiles[1]['detail']);
    }

    public function testInstructorDeadlinesAreCappedAtFive(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->quiz("Q{$i}", date('Y-m-d H:i:s', strtotime("+{$i} days")));
        }

        $this->assertCount(5, $this->call('instructorDeadlines', $this->assessments($this->instructor)['assessments']));
    }

    public function testAttentionListsWorkWaitingOnTheInstructor(): void
    {
        $quiz = $this->quiz('Essay quiz', date('Y-m-d H:i:s', strtotime('+5 days')));
        $question = AssessmentFactory::question($quiz, $this->instructor, 'Essay');
        $submission = AssessmentFactory::attempt($quiz, $this->student, 'Submitted');
        AssessmentFactory::response($submission, $question, ['response_text' => 'answer']);
        $this->quiz('Unpublished', date('Y-m-d H:i:s', strtotime('+5 days')), 'Draft');
        db_connect()->table('course_modules')->insert(['offering_id' => $this->offering, 'title' => 'Draft module', 'order_index' => 9, 'status' => 'draft']);

        $this->items([['type' => 'reading']]);

        $attention = $this->call('attention', $this->offering, $this->assessments($this->instructor));

        $this->assertSame(['Essay quiz'], array_column($attention['grading'], 'title'));
        $this->assertSame('grade', $attention['grading'][0]['action']);
        $this->assertStringStartsWith('1 to grade', $attention['grading'][0]['detail']);
        $this->assertSame(1, $attention['grading_total']);

        $this->assertSame(['Sam Student'], array_column($attention['behind'], 'title'), 'never active, with published content');
        $this->assertSame('No activity yet', $attention['behind'][0]['detail']);
        $this->assertSame('danger', $attention['behind'][0]['tone']);
        $this->assertSame('none', $attention['behind'][0]['action']);
        $this->assertSame(1, $attention['behind_total']);

        $this->assertEqualsCanonicalizing(['Unpublished', 'Draft module'], array_column($attention['drafts'], 'title'));
        $this->assertSame(2, $attention['drafts_total']);
        $this->assertEqualsCanonicalizing(['edit', 'modules'], array_column($attention['drafts'], 'action'));
    }

    public function testEachAttentionListIsCappedButItsTotalIsNot(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            db_connect()->table('course_modules')->insert(['offering_id' => $this->offering, 'title' => "Draft {$i}", 'order_index' => 10 + $i, 'status' => 'draft']);
        }

        $attention = $this->call('attention', $this->offering, $this->assessments($this->instructor));

        $this->assertCount(4, $attention['drafts']);
        $this->assertSame(6, $attention['drafts_total']);
    }

    public function testBehindAndInactiveStudentsAreWordedApart(): void
    {
        $this->items([['type' => 'reading'], ['type' => 'reading'], ['type' => 'reading']]);
        $slow = $this->person('student', 'Slow', 'Poke');
        $quiet = $this->person('student', 'Quiet', 'One');
        CourseFactory::enroll($this->offering, $slow);
        CourseFactory::enroll($this->offering, $quiet);

        [$a] = db_connect()->table('learning_items')->select('id')->get()->getResultArray();
        CourseFactory::progress((int) $a['id'], $slow, 'completed');
        CourseFactory::progress((int) $a['id'], $quiet, 'completed');
        db_connect()->table('learning_completions')->where('student_user_id', $quiet->id)->update(['completed_at' => date('Y-m-d H:i:s', strtotime('-20 days'))]);

        $attention = $this->call('attention', $this->offering, ['readyForGrading' => [], 'readyCount' => 0, 'drafts' => [], 'draftCount' => 0]);

        $details = array_column($attention['behind'], 'detail', 'title');

        $this->assertSame('Inactive for 20 days', $details['Quiet One']);
        $this->assertSame('Behind — 33% complete', $details['Slow Poke']);
    }

    public function testClassProgressColoursModulesByCompletion(): void
    {
        $roster = 10;
        db_connect()->table('course_modules')->where('id', $this->module)->update(['title' => 'Only module']);
        $this->items(array_fill(0, 10, ['type' => 'reading']));
        $ids = array_column(db_connect()->table('learning_items')->select('id')->get()->getResultArray(), 'id');

        $this->assertSame(['roster' => 0, 'modules' => []], $this->call('classProgress', $this->offering, 0));

        $at = function (int $completions) use ($ids, $roster): array {
            db_connect()->table('learning_completions')->where('id >', 0)->delete();
            // $completions completions out of roster × items = 100 possible.
            $n = 0;
            for ($u = 0; $u < $roster && $n < $completions; $u++) {
                $user = CourseFactory::user('student');
                foreach ($ids as $id) {
                    if ($n >= $completions) {
                        break;
                    }
                    CourseFactory::progress((int) $id, $user);
                    $n++;
                }
            }

            return $this->call('classProgress', $this->offering, $roster)['modules'][0];
        };

        $this->assertSame(['title' => 'Only module', 'completion' => 39, 'tone' => 'danger'], $at(39));
        $this->assertSame(['title' => 'Only module', 'completion' => 40, 'tone' => 'warning'], $at(40));
        $this->assertSame(['title' => 'Only module', 'completion' => 69, 'tone' => 'warning'], $at(69));
        $this->assertSame(['title' => 'Only module', 'completion' => 70, 'tone' => 'success'], $at(70));
    }

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    */

    private function block(string $day, string $start, string $end, string $room, string $mode = 'onsite'): void
    {
        db_connect()->table('course_schedules')->insert(['offering_id' => $this->offering, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'room' => $room, 'delivery_mode' => $mode]);
    }

    public function testDaysSharingATimeAndRoomAreFoldedIntoOneBlock(): void
    {
        $this->block('mon', '09:00:00', '10:30:00', 'R1');
        $this->block('wed', '09:00:00', '10:30:00', 'R1');
        $this->block('fri', '09:00:00', '10:30:00', 'R1');
        $this->block('thu', '13:00:00', '14:00:00', 'Lab', 'online');
        $this->block('tue', '13:00:00', '14:00:00', 'Other', 'hybrid');

        $blocks = $this->call('scheduleBlocks', $this->offering);

        $this->assertCount(3, $blocks);
        $this->assertSame(['days' => 'Mon, Wed, Fri', 'time' => '9:00 AM – 10:30 AM', 'room' => 'R1', 'mode' => 'On-site'], $blocks[0]);
        $byRoom = array_column($blocks, null, 'room');
        $this->assertSame('Online', $byRoom['Lab']['mode']);
        $this->assertSame('Hybrid', $byRoom['Other']['mode']);
    }

    public function testNoScheduleMeansNoBlocks(): void
    {
        $this->assertSame([], $this->call('scheduleBlocks', $this->offering));
    }
}
