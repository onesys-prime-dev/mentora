<?php

declare(strict_types=1);

use App\Services\Courses\DeanDashboardService;
use App\Services\Courses\FollowUpService;
use App\Services\Courses\Grades\GradeSignoffService;
use App\Services\Courses\UngradedWork;
use App\Services\Institutions\AcademicDefaults;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * What the dean's home page is made of, from a fixture with known numbers, in department H:
 *
 *   Sub A  IT101 (running; quiet since 30 days; 2 pieces of work handed in 10 days ago and 1 yesterday, all ungraded,
 *                 plus 1 old one already graded; 2 students who have done nothing; grades finalized)
 *          CS101 (running; busy; nothing to grade)
 *   Sub B  LW100 (running; quiet; 1 piece of work 10 days old; a grade-override request waiting for the dean)
 *   An earlier term's IT099 (completed, with old ungraded work) and department N's LAW900 (old ungraded work).
 *   A follow-up the dean sent that is a day late, and a chairperson who changed IT101.
 *
 * @internal
 */
final class DeanDashboardServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private DeanDashboardService $service;
    private int $institution;
    private int $department;
    private int $subA;
    private int $subB;
    private int $term;
    private int $it101;
    private int $cs101;
    private int $lw100;
    private User $dean;
    private User $chair;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        session()->destroy();

        $home              = DeanFactory::department('H');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->subA        = DeanFactory::subDepartment($this->department, 'A');
        $this->subB        = DeanFactory::subDepartment($this->department, 'B');
        $programA          = DeanFactory::program($this->subA, 'A');
        $programB          = DeanFactory::program($this->subB, 'B');
        $this->term        = DeanFactory::term($this->institution, 'Second', '2027-01-10');
        $earlier           = DeanFactory::term($this->institution, 'First', '2026-08-01');
        $this->dean        = DeanFactory::dean($this->institution, $this->department);
        $this->chair       = DeanFactory::chair($this->subA, 'Ann');
        $this->teacher     = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->service     = new DeanDashboardService();

        $this->it101 = DeanFactory::offering($programA, $this->term, 'IT101');
        $this->cs101 = DeanFactory::offering($programA, $this->term, 'CS101');
        $this->lw100 = DeanFactory::offering($programB, $this->term, 'LW100');
        $it099       = DeanFactory::offering($programA, $earlier, 'IT099', 'A', 'completed');

        foreach ([$this->it101, $this->cs101, $this->lw100, $it099] as $offering) {
            CourseFactory::assignInstructor($offering, $this->teacher);
        }

        $old = date('Y-m-d H:i:s', strtotime('-30 days'));

        // Modules: IT101 and LW100 have been quiet for 30 days, CS101 was touched now.
        $itModule = CourseFactory::module($this->it101);
        $lwModule = CourseFactory::module($this->lw100);
        $csModule = CourseFactory::module($this->cs101);
        db_connect()->table('course_modules')->where('id', $csModule)->update(['updated_at' => date('Y-m-d H:i:s')]);
        $oldModule = CourseFactory::module($it099);
        db_connect()->table('course_modules')->whereIn('id', [$itModule, $lwModule, $oldModule])->update(['updated_at' => $old]);

        // Two students on IT101 who have done nothing of what is published: at risk.
        CourseFactory::items(CourseFactory::unit($itModule), [['title' => 'One'], ['title' => 'Two']]);

        foreach ([1, 2] as $n) {
            $student = PeopleFactory::person('student', 'Stu', 'Dent' . $n, null, $this->institution);
            CourseFactory::enroll($this->it101, $student);
        }

        $stu = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert(['user_id' => $stu->id, 'first_name' => 'Hand', 'last_name' => 'In']);

        $this->handIn($itModule, $this->it101, $stu, [10, 10, 1], 1);
        $this->handIn($lwModule, $this->lw100, $stu, [10]);
        $this->handIn($oldModule, $it099, $stu, [10]);

        // Another department's old ungraded work: nobody's but theirs.
        $other = DeanFactory::department('N', $this->institution);
        $next  = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($other['department'], 'N'), 'N'), $this->term, 'LAW900');
        CourseFactory::assignInstructor($next, $this->teacher);
        $this->handIn(CourseFactory::module($next), $next, $stu, [10]);

        // IT101's grades are final; a request is open on LW100.
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->it101, 'finalized_by' => $this->teacher->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
        db_connect()->table('grade_override_requests')->insert([
            'offering_id' => $this->lw100, 'student_id' => $stu->id, 'requested_percentage' => 90, 'calculated_percentage' => 80, 'reason' => 'Excused',
            'requested_by' => $this->teacher->id, 'requester_role' => 'instructor', 'status' => 'awaiting_dean', 'open_marker' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // A follow-up a day late.
        $sent = (new FollowUpService())->send($this->dean, ['offering_id' => $this->it101, 'recipient_id' => $this->teacher->id, 'title' => 'Late grading', 'message' => 'Please grade.']);
        db_connect()->table('follow_ups')->where('id', $sent['id'])->update(['due_date' => date('Y-m-d', strtotime('-1 day'))]);

        // A change a chairperson made in IT101.
        Services::resetSingle('auditLogger');
        service('auditLogger')->log('course.chairperson_action', $this->chair, [
            'target_type' => 'course_offering', 'target_id' => $this->it101, 'acting_role' => 'chairperson',
            'new'         => ['method' => 'POST', 'path' => "/chairperson/api/courses/{$this->it101}/announcements"],
        ]);
    }

    /**
     * Pieces of work handed in some days ago and not graded, one assessment each, plus optionally already-graded ones.
     *
     * @param list<int> $daysAgo
     */
    private function handIn(int $module, int $offering, User $student, array $daysAgo, int $gradedOld = 0): void
    {
        $assessment = CourseFactory::assessment($offering, $this->teacher, $module);
        $n          = 0;

        foreach ($daysAgo as $days) {
            $at = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            AssessmentFactory::attempt($assessment, $student, 'Submitted', 0.0, ['submitted_at' => $at, 'attempt_number' => ++$n]);
        }

        for ($i = 0; $i < $gradedOld; $i++) {
            $at = date('Y-m-d H:i:s', strtotime('-20 days'));
            AssessmentFactory::attempt($assessment, $student, 'Graded', 5.0, ['submitted_at' => $at, 'graded_at' => $at, 'attempt_number' => ++$n]);
        }
    }

    /** @return array<string, array<string, mixed>> the attention items by key (one per key, or the first of several) */
    private function attention(?array $overview = null): array
    {
        $overview ??= $this->service->overview($this->dean);

        return array_column($overview['attention'], null, 'key');
    }

    public function testTheSnapshotCountsWhatIsRunningNow(): void
    {
        $snapshot = $this->service->overview($this->dean)['snapshot'];

        $this->assertSame(2, $snapshot['sub_departments']);
        $this->assertSame(2, $snapshot['programs']);
        $this->assertSame(3, $snapshot['courses'], 'IT099 is completed');
        $this->assertSame(1, $snapshot['instructors']);
        $this->assertSame(2, $snapshot['students'], 'the two on IT101');
        $this->assertSame(['id' => $this->department, 'name' => 'College H'], $this->service->overview($this->dean)['department']);
    }

    public function testOverridesWaitingForTheDeanAreCounted(): void
    {
        $this->assertSame(1, $this->attention()['overrides']['count']);
        $this->assertStringEndsWith('dean/approvals', $this->attention()['overrides']['url']);
    }

    public function testSectionsReadyAndHoldingUpSignOffAreCountedAndAgreeWithTheSignOffBoard(): void
    {
        $facts = $this->attention();

        $this->assertSame(1, $facts['signoff_ready']['count'], 'IT101: final, nothing open');
        $this->assertSame(2, $facts['signoff_blocked']['count'], 'CS101 is not final, LW100 has an open request');

        $counts = (new GradeSignoffService())->counts($this->dean);
        $board  = (new GradeSignoffService())->board($this->dean, null);
        $byState = ['ready' => 0, 'blocked' => 0, 'signed_off' => 0];

        foreach ($board['programs'] as $program) {
            foreach ($program['counts'] as $state => $n) {
                $byState[$state] += $n;
            }
        }

        $this->assertSame($byState, array_diff_key($counts, ['term' => 1]), 'the quick count and the board are one answer');
        $this->assertSame($this->term, $counts['term']);
    }

    public function testSignOffCountsFollowASignedOffSectionAndAReopenedOne(): void
    {
        $service = new GradeSignoffService();

        db_connect()->table('grade_signoffs')->insert(['offering_id' => $this->it101, 'signed_by' => $this->dean->id, 'signed_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $this->assertSame(['term' => $this->term, 'ready' => 0, 'blocked' => 2, 'signed_off' => 1], $service->counts($this->dean));

        db_connect()->table('grade_signoffs')->where('offering_id', $this->it101)->update(['open_marker' => null]);

        $this->assertSame(1, $service->counts($this->dean)['ready'], 'reopened, it is ready again');
        $this->assertSame(['term' => null, 'ready' => 0, 'blocked' => 0, 'signed_off' => 0], $service->counts(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution)));
    }

    public function testOverdueFollowUpsAreCounted(): void
    {
        $this->assertSame(1, $this->attention()['followups_overdue']['count']);
    }

    public function testOverdueGradingIsCountedPerSubDepartmentForRunningSectionsOnly(): void
    {
        $facts = $this->service->facts($this->dean, $this->running(), new DateTimeImmutable());

        $this->assertEqualsCanonicalizing(
            [['id' => $this->subA, 'name' => 'Sub A', 'count' => 2], ['id' => $this->subB, 'name' => 'Sub B', 'count' => 1]],
            $facts['overdue_grading'],
            'IT101 has 2 ten days old (the one from yesterday is not late, the graded one is done); IT099 is not running; LAW900 is another department\'s'
        );
    }

    public function testQuietCoursesAreCountedPerSubDepartmentForRunningSectionsOnly(): void
    {
        $facts = $this->service->facts($this->dean, $this->running(), new DateTimeImmutable());

        $this->assertEqualsCanonicalizing(
            [['id' => $this->subA, 'name' => 'Sub A', 'count' => 1], ['id' => $this->subB, 'name' => 'Sub B', 'count' => 1]],
            $facts['inactive_courses'],
            'IT101 and LW100 are quiet; CS101 was just touched'
        );
    }

    public function testStudentsAtRiskAreCounted(): void
    {
        $this->assertSame(2, $this->service->facts($this->dean, $this->running(), new DateTimeImmutable())['at_risk']);
    }

    public function testTheInstitutionsThresholdsDecideWhatIsOverdueAndQuiet(): void
    {
        $at = new DateTimeImmutable();

        (new AcademicDefaults())->save($this->institution, ['overdue_grading_days' => '20', 'inactive_days' => '60'], PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution));

        $facts = $this->service->facts($this->dean, $this->running(), $at);

        $this->assertSame([], array_filter($facts['overdue_grading'], static fn (array $r): bool => $r['count'] > 0), 'ten days is not late at twenty');
        $this->assertSame([], array_filter($facts['inactive_courses'], static fn (array $r): bool => $r['count'] > 0), 'thirty days is not quiet at sixty');

        (new AcademicDefaults())->save($this->institution, ['overdue_grading_days' => '5', 'inactive_days' => '14'], PeopleFactory::person('institutional_admin', 'Bea', 'Admin', null, $this->institution));

        $facts = $this->service->facts($this->dean, $this->running(), $at);

        $this->assertSame(3, array_sum(array_column($facts['overdue_grading'], 'count')), 'at five days, the one from yesterday still is not late');
    }

    public function testTheAttentionListIsInPriorityOrderWithEveryKind(): void
    {
        $keys = array_column($this->service->overview($this->dean)['attention'], 'key');

        $this->assertSame(['overrides', 'signoff_ready', 'signoff_blocked', 'followups_overdue', 'overdue_grading', 'overdue_grading', 'inactive_courses', 'inactive_courses', 'at_risk'], $keys);
    }

    public function testWhatChairpersonsChangedIsListedWithHowManyAreLeftToReview(): void
    {
        $activity = $this->service->overview($this->dean)['chairperson_activity'];

        $this->assertSame(1, $activity['to_review']);
        $this->assertCount(1, $activity['entries']);
        $this->assertSame('Created an announcement', $activity['entries'][0]['description']);
        $this->assertSame('IT101', $activity['entries'][0]['course_code']);

        $id = $activity['entries'][0]['id'];

        (new \App\Services\Courses\ChairpersonReviewService())->markReviewed($this->dean, [$id]);

        $after = $this->service->overview($this->dean)['chairperson_activity'];

        $this->assertSame(0, $after['to_review']);
        $this->assertCount(1, $after['entries'], 'a reviewed one still shows as recent activity');
    }

    public function testTheDeansAnnouncementsAreTheLatestThreeStillShowing(): void
    {
        $announce = new \App\Services\Courses\DepartmentAnnouncementService();

        foreach (['One', 'Two', 'Three', 'Four'] as $title) {
            $announce->post($this->dean, ['scope' => 'department', 'audience' => 'everyone', 'title' => $title, 'message' => 'Hello.']);
        }

        $this->assertSame(['Four', 'Three', 'Two'], array_column($this->service->overview($this->dean)['announcements'], 'title'));
    }

    public function testADepartmentWithNothingGoingOnHasAnEmptyAttentionListAndZeros(): void
    {
        $quiet = DeanFactory::department('Q', $this->institution);
        $dean  = DeanFactory::dean($this->institution, $quiet['department'], 'Quin');

        $overview = $this->service->overview($dean);

        $this->assertSame([], $overview['attention']);
        $this->assertSame(['sub_departments' => 0, 'programs' => 0, 'courses' => 0, 'instructors' => 0, 'students' => 0], $overview['snapshot']);
        $this->assertSame(['to_review' => 0, 'entries' => []], $overview['chairperson_activity']);
        $this->assertSame([], $overview['announcements']);
    }

    public function testADeanWithoutADepartmentHasNoOverviewAndAnEndedDeanshipTakesEffectAtOnce(): void
    {
        $this->assertNull($this->service->overview(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution)));

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertNull($this->service->overview($this->dean));
    }

    public function testUngradedWorkIsCountedPerOfferingAndOnlyBeforeAMoment(): void
    {
        $all     = UngradedWork::countByOffering([$this->it101, $this->cs101, $this->lw100]);
        $overdue = UngradedWork::countByOffering([$this->it101, $this->cs101, $this->lw100], date('Y-m-d H:i:s', strtotime('-5 days')));

        $this->assertSame([$this->it101 => 3, $this->lw100 => 1], $all);
        $this->assertSame([$this->it101 => 2, $this->lw100 => 1], $overdue);
        $this->assertSame([], UngradedWork::countByOffering([]));
    }

    /** @return list<array<string, mixed>> the department's running sections */
    private function running(): array
    {
        $ids = (new \App\Services\Courses\DeanAccessService())->programIds($this->dean);

        return array_values(array_filter(
            (new \App\Services\Courses\MonitorProgramService())->offeringRowsForPrograms($ids, new \App\Services\Courses\MonitorProgramFilters()),
            static fn (array $r): bool => $r['offering_status'] === 'active'
        ));
    }
}
