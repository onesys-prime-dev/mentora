<?php

declare(strict_types=1);

use App\Services\Parents\ParentNotifier;
use App\Services\Parents\ParentPortal;
use App\Services\Parents\ParentPreferences;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * Telling a child's parents (Q7, Q17): who is told, once, what, and by what
 * route; and that no grade ever travels in the words.
 *
 * @internal
 */
final class ParentNotifierTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private FakeEmail $mailer;

    private int $institution;

    private User $pam;

    private User $olga;

    private User $ann;

    private User $teacher;

    private int $offering;

    private int $scheme;

    private ParentNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->mailer      = FakeEmail::install();
        $this->institution = PeopleFactory::institution('PNT');
        $this->pam         = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@example.test', $this->institution);
        $this->olga        = PeopleFactory::person('parent', 'Olga', 'Parent', 'olga@example.test', $this->institution);
        $this->ann         = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);
        $this->notifier    = new ParentNotifier();

        $this->link($this->pam, $this->ann);
        $this->link($this->olga, $this->ann);

        [$this->offering, $this->scheme] = $this->course('A');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function link(User $parent, User $student): void
    {
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{int, int} an active offering Ann is enrolled in, and its published scheme */
    private function course(string $section): array
    {
        $offering = CourseFactory::offering($section);
        CourseFactory::assignInstructor($offering, $this->teacher);
        CourseFactory::enroll($offering, $this->ann);

        $scheme = GradeFactory::scheme($offering, 75.0);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        return [$offering, $scheme];
    }

    private function record(string $remarks, ?string $finalizedAt = null, ?int $offering = null, ?User $student = null): void
    {
        $offering ??= $this->offering;
        $student  ??= $this->ann;
        $keys       = ['course_offering_id' => $offering, 'student_id' => $student->id];
        $row        = ['grading_scheme_id' => (int) db_connect()->table('grading_schemes')->where('course_offering_id', $offering)->get()->getRowArray()['id'], 'remarks' => $remarks, 'finalized_at' => $finalizedAt, 'final_percentage' => $finalizedAt ? 88.0 : null, 'calculated_percentage' => 88.0, 'updated_at' => date('Y-m-d H:i:s')];

        db_connect()->table('gradebook_records')->where($keys)->countAllResults() > 0
            ? db_connect()->table('gradebook_records')->where($keys)->update($row)
            : db_connect()->table('gradebook_records')->insert($keys + $row + ['created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return list<array<string, mixed>> */
    private function notes(string $type, ?User $user = null): array
    {
        $builder = db_connect()->table('notifications')->where('type', $type);

        if ($user !== null) {
            $builder->where('user_id', $user->id);
        }

        return $builder->orderBy('id')->get()->getResultArray();
    }

    private function code(): string
    {
        $row = db_connect()->table('course_offerings o')->select('c.course_code, o.section_code')->join('courses c', 'c.id = o.course_id')->where('o.id', $this->offering)->get()->getRowArray();

        return $row['course_code'] . '-' . $row['section_code'];
    }

    private function recent(int $days = 1): string
    {
        return date('Y-m-d H:i:s', time() - $days * 86400);
    }

    /*
    |--------------------------------------------------------------------------
    | Who is a child's parent, for telling
    |--------------------------------------------------------------------------
    */

    public function testBothParentsOfAChildAreFound(): void
    {
        $this->assertEqualsCanonicalizing([(int) $this->pam->id, (int) $this->olga->id], array_column($this->notifier->parentsOf((int) $this->ann->id), 'parent_id'));
    }

    public function testNobodyWhoShouldNotBeToldIsFound(): void
    {
        $gone = PeopleFactory::person('parent', 'Gone', 'Parent', null, $this->institution);
        $this->link($gone, $this->ann);
        db_connect()->table('users')->where('id', $gone->id)->update(['active' => 0]);

        $deleted = PeopleFactory::person('parent', 'Deleted', 'Parent', null, $this->institution);
        $this->link($deleted, $this->ann);
        db_connect()->table('users')->where('id', $deleted->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $notAParent = PeopleFactory::person('instructor', 'Not', 'Parent', null, $this->institution);
        $this->link($notAParent, $this->ann);

        $left = PeopleFactory::person('parent', 'Left', 'Parent', null, $this->institution);
        $this->link($left, $this->ann);
        db_connect()->table('institution_users')->where('user_id', $left->id)->delete();

        $this->assertEqualsCanonicalizing([(int) $this->pam->id, (int) $this->olga->id], array_column($this->notifier->parentsOf((int) $this->ann->id), 'parent_id'));
    }

    public function testNobodyIsFoundWhileThePortalIsSwitchedOff(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);

        $this->assertSame([], $this->notifier->parentsOf((int) $this->ann->id));
    }

    public function testAStudentWhoLeftTheInstitutionHasNoParentsToTell(): void
    {
        db_connect()->table('institution_users')->where('user_id', $this->ann->id)->delete();

        $this->assertSame([], $this->notifier->parentsOf((int) $this->ann->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Final grade
    |--------------------------------------------------------------------------
    */

    public function testAFinalGradeTellsBothParentsOnceWithTheCopy(): void
    {
        $this->record('Passed', $this->recent());

        $this->assertSame(2, $this->notifier->finalGrades());

        $notes = $this->notes('parent.final', $this->pam);

        $this->assertCount(1, $notes);
        $this->assertSame('Final grade available for Ann', $notes[0]['title']);
        $this->assertSame('Ann\'s final grade for ' . $this->code() . ' is ready. Sign in to view it.', $notes[0]['body']);
        $this->assertStringContainsString('parent/children/' . $this->ann->id . '/courses/' . $this->offering . '/', $notes[0]['link']);
        $this->assertStringEndsWith('/grades', $notes[0]['link']);
        $this->assertCount(1, $this->notes('parent.final', $this->olga));
    }

    public function testTheEmailSwitchDecidesTheEmailButNotTheAppMessage(): void
    {
        (new ParentPreferences())->save((int) $this->pam->id, [(int) $this->ann->id => false]);
        $this->record('Failed', $this->recent());

        $this->notifier->finalGrades();

        $pam   = $this->notes('parent.final', $this->pam)[0];
        $olga  = $this->notes('parent.final', $this->olga)[0];
        $sent  = array_column($this->mailer->sent, 'to');

        $this->assertSame(0, (int) $pam['urgent']);
        $this->assertNull($pam['emailed_at']);
        $this->assertSame(1, (int) $olga['urgent']);
        $this->assertNotNull($olga['emailed_at']);
        $this->assertSame(['olga@example.test'], $sent);
    }

    public function testIncAndWithdrawnAreNotFinalGrades(): void
    {
        $this->record('INC', $this->recent());

        $this->assertSame(0, $this->notifier->finalGrades());

        $this->record('Withdrawn', $this->recent());

        $this->assertSame(0, $this->notifier->finalGrades());
    }

    public function testOldEndedAndDroppedFinalsSendNothing(): void
    {
        $this->record('Passed', $this->recent(20));
        $this->assertSame(0, $this->notifier->finalGrades(), 'finalized more than 14 days ago');

        $this->record('Passed', $this->recent());
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'completed']);
        $this->assertSame(0, $this->notifier->finalGrades(), 'the course has ended');

        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'active']);
        db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $this->ann->id])->update(['status' => 'dropped']);
        $this->assertSame(0, $this->notifier->finalGrades(), 'the student dropped it');
    }

    public function testNobodyIsToldTwice(): void
    {
        $this->record('Passed', $this->recent());

        $this->assertSame(2, $this->notifier->finalGrades());
        $this->assertSame(0, $this->notifier->finalGrades());
        $this->assertSame(0, $this->notifier->finalGrades($this->offering));
        $this->assertCount(2, $this->notes('parent.final'));
    }

    public function testFinalizingAgainAfterAReopenTellsAgain(): void
    {
        $this->record('Passed', $this->recent(2));
        $this->notifier->finalGrades();

        $this->record('Passed', $this->recent(1));

        $this->assertSame(2, $this->notifier->finalGrades());
        $this->assertCount(4, $this->notes('parent.final'));
    }

    public function testOneOfferingCanBeNamed(): void
    {
        [$second] = $this->course('B');
        $this->record('Passed', $this->recent());
        $this->record('Passed', $this->recent(), $second);

        $this->assertSame(2, $this->notifier->finalGrades($second));
        $this->assertCount(2, $this->notes('parent.final'));
    }

    public function testAParentOfAnotherChildHearsNothing(): void
    {
        $zed   = PeopleFactory::person('student', 'Zed', 'Other', null, $this->institution);
        $other = PeopleFactory::person('parent', 'Una', 'Other', null, $this->institution);
        $this->link($other, $zed);
        $this->record('Passed', $this->recent());

        $this->notifier->finalGrades();

        $this->assertSame([], $this->notes('parent.final', $other));
    }

    /*
    |--------------------------------------------------------------------------
    | INC, Withdrawn, At risk
    |--------------------------------------------------------------------------
    */

    public function testIncTellsBothParentsOnceWithTheCopy(): void
    {
        $this->record('INC');

        $this->assertSame(2, $this->notifier->standings());
        $this->assertSame(0, $this->notifier->standings(), 'told once');

        $note = $this->notes('parent.inc', $this->pam)[0];

        $this->assertSame('Ann is marked incomplete in ' . $this->code(), $note['title']);
        $this->assertSame('The instructor marked Ann\'s work in ' . $this->code() . ' as incomplete (INC). Sign in for details.', $note['body']);
        $this->assertStringEndsWith('/grades', $note['link']);
        $this->assertCount(1, $this->notes('parent.inc', $this->olga));
    }

    public function testWithdrawnIsToldEvenThoughTheEnrolmentIsNoLongerEnrolled(): void
    {
        db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $this->ann->id])->update(['status' => 'withdrawn']);
        $this->record('Withdrawn');

        $this->assertSame(2, $this->notifier->standings());

        $note = $this->notes('parent.withdrawn', $this->pam)[0];

        $this->assertSame('Ann is withdrawn from ' . $this->code(), $note['title']);
        $this->assertSame('Ann is shown as withdrawn from ' . $this->code() . '. Contact the school if this is a surprise.', $note['body']);
        $this->assertStringEndsWith('/overview', $note['link']);
    }

    public function testAnAtRiskGradeTellsTheParentsOnce(): void
    {
        $this->record('Failed');

        $this->assertSame(2, $this->notifier->standings());
        $this->assertSame(0, $this->notifier->standings());

        $note = $this->notes('parent.at_risk', $this->pam)[0];

        $this->assertSame('Ann may be falling behind in ' . $this->code(), $note['title']);
        $this->assertSame('Ann\'s grade so far in ' . $this->code() . ' is below the passing mark. Sign in to see how it is made up.', $note['body']);
        $this->assertStringEndsWith('/grades', $note['link']);
    }

    public function testStandingsThatAreNotNewsSendNothing(): void
    {
        $this->record('Passed');
        $this->assertSame(0, $this->notifier->standings(), 'on track');

        $this->record('Failed', $this->recent());
        $this->assertSame(0, $this->notifier->standings(), 'a finalized Failed is a final grade, not "at risk"');

        $this->record('Failed');
        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Draft']);
        $this->assertSame(0, $this->notifier->standings(), 'the scheme is not published');

        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Published']);
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'completed']);
        $this->assertSame(0, $this->notifier->standings(), 'the course has ended');

        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'active']);
        db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $this->ann->id])->update(['status' => 'dropped']);
        $this->assertSame(0, $this->notifier->standings(), 'dropped, and not marked withdrawn');
    }

    public function testStandingsAreEmailedOnlyWhenTheSwitchIsOn(): void
    {
        (new ParentPreferences())->save((int) $this->pam->id, [(int) $this->ann->id => false]);
        $this->record('INC');

        $this->notifier->standings();

        $this->assertSame(['olga@example.test'], array_column($this->mailer->sent, 'to'));
        $this->assertNull($this->notes('parent.inc', $this->pam)[0]['emailed_at']);
    }

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */

    private function announce(string $subject = 'Room change', ?string $when = null, ?int $offering = null): int
    {
        db_connect()->table('announcements')->insert([
            'offering_id' => $offering ?? $this->offering, 'author_id' => $this->teacher->id, 'subject' => $subject,
            'message' => json_encode(['version' => 1, 'blocks' => []]), 'is_pinned' => 0,
            'created_at' => $when ?? date('Y-m-d H:i:s'), 'updated_at' => $when ?? date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    public function testAnAnnouncementTellsEachParentOnceInTheAppOnly(): void
    {
        $id = $this->announce();

        $this->assertSame(2, $this->notifier->announcements());
        $this->assertSame(0, $this->notifier->announcements(), 'told once');

        $note = $this->notes('parent.announcement', $this->pam)[0];

        $this->assertSame('New announcement in ' . $this->code(), $note['title']);
        $this->assertSame('The instructor posted "Room change" in ' . $this->code() . '.', $note['body']);
        $this->assertStringEndsWith('/announcements?announcement=' . $id, $note['link']);
        $this->assertSame(0, (int) $note['urgent']);
        $this->assertSame([], $this->mailer->sent, 'an announcement is never emailed');
    }

    public function testOldOrElsewhereAnnouncementsSendNothing(): void
    {
        $this->announce('Old news', $this->recent(5));
        $this->assertSame(0, $this->notifier->announcements(), 'older than three days');

        [$other] = $this->course('B');
        db_connect()->table('enrollments')->where(['offering_id' => $other, 'student_user_id' => $this->ann->id])->delete();
        $this->announce('Not our course', null, $other);
        $this->assertSame(0, $this->notifier->announcements(), 'the child is not in that course');

        $this->announce('Ended', null);
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'completed']);
        $this->assertSame(0, $this->notifier->announcements(), 'the course has ended');
    }

    public function testAParentWithTwoChildrenInTheCourseHearsOnceAboutTheFirst(): void
    {
        $ben = PeopleFactory::person('student', 'Ben', 'Lee', null, $this->institution);
        $this->link($this->pam, $ben);
        CourseFactory::enroll($this->offering, $ben);
        $this->announce();

        $this->notifier->announcements();

        $mine = $this->notes('parent.announcement', $this->pam);

        $this->assertCount(1, $mine);
        $this->assertStringContainsString('parent/children/' . min($this->ann->id, $ben->id) . '/courses/', $mine[0]['link']);
    }

    /*
    |--------------------------------------------------------------------------
    | Work not handed in (one summary a day)
    |--------------------------------------------------------------------------
    */

    /** A published assessment of Ann's course, due $hoursAgo hours before now (negative: in the future). */
    private function due(string $title, float $hoursAgo = 24, string $status = 'Published', ?int $offering = null): int
    {
        $offering ??= $this->offering;
        $module     = (int) (db_connect()->table('course_modules')->where('offering_id', $offering)->get()->getRowArray()['id'] ?? CourseFactory::module($offering));
        $id         = CourseFactory::assessment($offering, $this->teacher, $module);

        db_connect()->table('assessments')->where('id', $id)->update([
            'title' => $title, 'status' => $status, 'total_points' => 10, 'due_date' => date('Y-m-d H:i:s', (int) (time() - $hoursAgo * 3600)),
            'available_from' => date('Y-m-d H:i:s', time() - 60 * 86400), 'available_until' => null,
        ]);

        return $id;
    }

    public function testOverdueWorkIsOneSummaryPerParentWithTheCopy(): void
    {
        $this->due('Essay');
        $this->due('Quiz');

        $this->assertSame(2, $this->notifier->missingWork());

        $note = $this->notes('parent.missing', $this->pam)[0];

        $this->assertSame('Ann has work not handed in', $note['title']);
        $this->assertSame('Ann has 2 new items not handed in. Sign in to see which.', $note['body']);
        $this->assertStringEndsWith('/assessments', $note['link']);
        $this->assertSame(1, (int) $note['urgent']);
        $this->assertEqualsCanonicalizing(['pam@example.test', 'olga@example.test'], array_column($this->mailer->sent, 'to'));
        $this->assertSame(2, db_connect()->table('parent_alerted_items')->where('student_user_id', $this->ann->id)->countAllResults(), 'each item is recorded once, for the child');
    }

    public function testOneItemIsSingular(): void
    {
        $this->due('Essay');

        $this->notifier->missingWork();

        $this->assertSame('Ann has 1 new item not handed in. Sign in to see which.', $this->notes('parent.missing', $this->pam)[0]['body']);
    }

    public function testTheSameDayTellsNobodyAgainAndALateItemWaitsForTomorrow(): void
    {
        $now = new DateTimeImmutable();

        $this->due('Essay');
        $this->assertSame(2, $this->notifier->missingWork($now));
        $this->assertSame(0, $this->notifier->missingWork($now));

        $this->due('Quiz', 1);
        $this->assertSame(0, $this->notifier->missingWork($now), 'today\'s summary has gone');
        $this->assertSame(1, db_connect()->table('parent_alerted_items')->where('student_user_id', $this->ann->id)->countAllResults(), 'the late item is still to be told');

        $tomorrow = $now->modify('+1 day');

        $this->assertSame(2, $this->notifier->missingWork($tomorrow));
        $this->assertSame('Ann has 1 new item not handed in. Sign in to see which.', $this->notes('parent.missing', $this->pam)[1]['body']);
        $this->assertSame(0, $this->notifier->missingWork($tomorrow->modify('+1 hour')));
    }

    public function testWorkThatIsNotMissingSendsNothing(): void
    {
        $handedIn = $this->due('Handed in');
        db_connect()->table('assessment_submissions')->insert(['assessment_id' => $handedIn, 'student_id' => $this->ann->id, 'attempt_number' => 1, 'score' => 0, 'is_graded' => 0, 'status' => 'Submitted']);

        $excused = $this->due('Excused');
        $item    = GradeFactory::item($this->scheme, null, 'Excused', 10, 1, $excused);
        GradeFactory::score($this->offering, $this->ann, $item, null, true);

        $this->due('A draft', 24, 'Draft');
        $this->due('Not due yet', -24);
        $this->due('Too old', 24 * 9);

        $closed = $this->due('Closed to submissions');
        db_connect()->table('assessments')->where('id', $closed)->update(['available_until' => date('Y-m-d H:i:s', time() - 3600)]);

        $this->assertSame(0, $this->notifier->missingWork());
        $this->assertSame(0, db_connect()->table('parent_alerted_items')->countAllResults());
    }

    public function testAnUnfinishedAttemptIsStillNotHandedIn(): void
    {
        $id = $this->due('Half done');
        db_connect()->table('assessment_submissions')->insert(['assessment_id' => $id, 'student_id' => $this->ann->id, 'attempt_number' => 1, 'score' => 0, 'is_graded' => 0, 'status' => 'In Progress']);

        $this->assertSame(2, $this->notifier->missingWork());
    }

    public function testAnEndedCourseOrADroppedStudentIsNotWatched(): void
    {
        $this->due('Essay');
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'completed']);
        $this->assertSame(0, $this->notifier->missingWork());

        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'active']);
        db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $this->ann->id])->update(['status' => 'dropped']);
        $this->assertSame(0, $this->notifier->missingWork());
    }

    public function testSeveralCoursesAreOneSummaryThatOpensTheDashboard(): void
    {
        [$second] = $this->course('B');
        $this->due('Essay');
        $this->due('Lab', 24, 'Published', $second);

        $this->assertSame(2, $this->notifier->missingWork());

        $note = $this->notes('parent.missing', $this->pam)[0];

        $this->assertSame('Ann has 2 new items not handed in. Sign in to see which.', $note['body']);
        $this->assertStringEndsWith('parent/children/' . $this->ann->id, $note['link']);
    }

    public function testASwitchedOffEmailStillGetsTheAppMessage(): void
    {
        (new ParentPreferences())->save((int) $this->pam->id, [(int) $this->ann->id => false]);
        $this->due('Essay');

        $this->notifier->missingWork();

        $this->assertNull($this->notes('parent.missing', $this->pam)[0]['emailed_at']);
        $this->assertNotNull($this->notes('parent.missing', $this->olga)[0]['emailed_at']);
    }

    public function testNothingHappensWhenNobodyCanBeTold(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->due('Essay');

        $this->assertSame(0, $this->notifier->missingWork());
        $this->assertSame(0, db_connect()->table('parent_alerted_items')->countAllResults(), 'nobody was told, so the item is still to be told');
    }

    public function testNoGradeTravelsInTheWords(): void
    {
        $this->record('Passed', $this->recent());
        $this->notifier->finalGrades();

        foreach ($this->notes('parent.final') as $note) {
            $this->assertDoesNotMatchRegularExpression('/\d\s*%|%\s*\d|points|score/i', $note['title'] . ' ' . $note['body']);
        }

        foreach ($this->mailer->sent as $mail) {
            $this->assertDoesNotMatchRegularExpression('/\d\s*%|%\s*\d|points|score/i', $mail['subject'] . ' ' . $mail['message']);
        }
    }
}
