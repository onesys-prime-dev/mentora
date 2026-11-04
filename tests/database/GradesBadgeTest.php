<?php

declare(strict_types=1);

use App\Services\Grades\GradesBadge;
use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\Mock\MockCache;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * The count on the Grades tile (Apps menu and Quick Access): what needs each person, from the same services as
 * the Grades page, cached 60 seconds per user and role and worked out at most once per request. Reading it
 * writes nothing.
 *
 * @internal
 */
final class GradesBadgeTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    /** @var list<array{key: string, ttl: int}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        // A fresh cache for every case that remembers what was saved and for how long.
        $saved = &$this->saved;
        Services::injectMock('cache', new class ($saved) extends MockCache {
            /** @param list<array{key: string, ttl: int}> $saved */
            public function __construct(private array &$saved)
            {
            }

            public function save(string $key, $value, int $ttl = 60): bool
            {
                $this->saved[] = ['key' => $key, 'ttl' => $ttl];

                return parent::save($key, $value, $ttl);
            }
        });

        GradesBadge::flush();
        helper(['navigation', 'icon']);
    }

    protected function tearDown(): void
    {
        GradesBadge::flush();
        Services::resetSingle('settings');
        session()->remove(['impersonator_id', 'impersonation']);

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function activate(int $offering): int
    {
        $term = (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRow()->term_id;
        db_connect()->table('academic_terms')->where('id', $term)->update(['status' => 'active']);

        return $term;
    }

    /** $count students with a submitted quiz attempt waiting to be graded in the offering. */
    private function waiting(int $offering, User $teacher, int $count): void
    {
        $quiz = CourseFactory::assessment($offering, $teacher, CourseFactory::module($offering), null, 'Quiz');
        db_connect()->table('assessments')->where('id', $quiz)->update(['status' => 'Published']);

        for ($i = 0; $i < $count; $i++) {
            $student = CourseFactory::user('student');
            CourseFactory::enroll($offering, $student);
            AssessmentFactory::attempt($quiz, $student, 'Submitted');
        }
    }

    /** A course of the student's in the term (or a new one), with a scheme of that status and a stored record. */
    private function studentCourse(User $student, ?int &$term, string $status = 'Published'): int
    {
        $offering = CourseFactory::offering();
        CourseFactory::enroll($offering, $student);

        if ($term === null) {
            $term = $this->activate($offering);
        } else {
            db_connect()->table('course_offerings')->where('id', $offering)->update(['term_id' => $term]);
        }

        $scheme = GradeFactory::scheme($offering, 75.0, false, GradeFactory::scale());
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => $status]);
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => $scheme,
            'calculated_percentage' => 82, 'is_override' => 0, 'remarks' => 'Passed', 'last_calculated_at' => date('Y-m-d H:i:s'),
        ]);

        return $offering;
    }

    /** What the viewer saw last of the course: a signature that no longer matches, so it reads as changed. */
    private function seenBefore(User $viewer, User $student, int $offering): void
    {
        db_connect()->table('grades_seen')->insert([
            'viewer_user_id' => $viewer->id, 'student_user_id' => $student->id, 'offering_id' => $offering,
            'signature' => str_repeat('0', 64), 'seen_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);
    }

    private function finalize(int $offering, User $by): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $offering, 'finalized_by' => $by->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
    }

    private function signOff(int $offering, User $by): void
    {
        db_connect()->table('grade_signoffs')->insert(['offering_id' => $offering, 'signed_by' => $by->id, 'signed_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
    }

    /** @return list<string> the SQL run by $work */
    private function queriesOf(callable $work): array
    {
        $queries  = [];
        $listener = static function ($query) use (&$queries): void {
            $queries[] = (string) $query->getQuery();
        };

        Events::on('DBQuery', $listener);
        $work();
        Events::removeListener('DBQuery', $listener);

        return $queries;
    }

    /** @param list<string> $queries */
    private function writes(array $queries): array
    {
        return array_values(array_filter($queries, static fn (string $sql): bool => (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)));
    }

    private function viewAs(User $user): void
    {
        session()->set('impersonator_id', 1);
        session()->set('impersonation', ['target_id' => (int) $user->id, 'reason' => 'Checking the badge', 'started_at' => time(), 'expires_at' => time() + 600]);
    }

    /*
    |--------------------------------------------------------------------------
    | One count per role
    |--------------------------------------------------------------------------
    */

    public function testTheInstructorBadgeIsWorkWaitingToGradeInTheCurrentTerm(): void
    {
        $teacher = CourseFactory::user('instructor');

        $current = CourseFactory::offering();
        CourseFactory::assignInstructor($current, $teacher);
        $this->activate($current);
        $this->waiting($current, $teacher, 3);

        // Another term's section (not the default one) does not count.
        $older = CourseFactory::offering();
        CourseFactory::assignInstructor($older, $teacher);
        $this->waiting($older, $teacher, 2);

        $this->assertSame(3, GradesBadge::for($teacher, 'instructor'));
        $this->assertSame('3 waiting to grade', GradesBadge::label('instructor', 3));
    }

    public function testTheChairpersonBadgeIsWorkWaitingInTheSectionsTheyTeachOfWhatTheyChair(): void
    {
        ['institution' => $institution, 'department' => $department] = DeanFactory::department('GB');
        $sub     = DeanFactory::subDepartment($department, 'GB');
        $program = DeanFactory::program($sub, 'GB');
        $term    = DeanFactory::term($institution);
        db_connect()->table('academic_terms')->where('id', $term)->update(['status' => 'active']);
        $chair = DeanFactory::chair($sub);

        $mine = DeanFactory::offering($program, $term, 'CB101');
        CourseFactory::assignInstructor($mine, $chair);
        $this->waiting($mine, $chair, 2);

        // A section of the program they do not teach, and one they teach outside what they chair.
        $theirs = DeanFactory::offering($program, $term, 'CB102');
        $this->waiting($theirs, $chair, 4);
        $outside = CourseFactory::offering();
        CourseFactory::assignInstructor($outside, $chair);
        db_connect()->table('course_offerings')->where('id', $outside)->update(['term_id' => $term]);
        $this->waiting($outside, $chair, 5);

        $this->assertSame(2, GradesBadge::for($chair, 'chairperson'));
    }

    public function testTheStudentBadgeCountsPublishedCoursesChangedSinceTheirLastLook(): void
    {
        $student = CourseFactory::user('student');
        $term    = null;

        $changed = $this->studentCourse($student, $term);
        $this->seenBefore($student, $student, $changed);

        // A first look is no change; a course whose grade is not published is not counted.
        $this->studentCourse($student, $term);
        $draft = $this->studentCourse($student, $term, 'Draft');
        $this->seenBefore($student, $student, $draft);

        $this->assertSame(1, GradesBadge::for($student, 'student'));
        $this->assertSame('1 course changed', GradesBadge::label('student', 1));
        $this->assertSame('2 courses changed', GradesBadge::label('student', 2));
    }

    public function testTheParentBadgeSumsTheirChildrenWithTheirOwnMemory(): void
    {
        $institution = PeopleFactory::institution('GBP');
        $parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $institution);
        $ann         = PeopleFactory::person('student', 'Ann', 'Lee', null, $institution);
        $ben         = PeopleFactory::person('student', 'Ben', 'Lee', null, $institution);

        foreach ([$ann, $ben] as $child) {
            db_connect()->table('parent_student_links')->insert(['institution_id' => $institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $termAnn = null;
        $termBen = null;
        $this->seenBefore($parent, $ann, $this->studentCourse($ann, $termAnn));
        $this->seenBefore($parent, $ben, $this->studentCourse($ben, $termBen));

        // What Ann saw herself is not the parent's memory.
        $this->seenBefore($ann, $ann, $this->studentCourse($ann, $termAnn));

        $this->assertSame(2, GradesBadge::for($parent, 'parent'));

        // With the parent portal off there is no page, so nothing to count.
        GradesBadge::flush();
        cache()->clean();
        (new ParentPortal())->setInstitutionOn($institution, false);
        Services::resetSingle('settings');

        $this->assertSame(0, GradesBadge::for($parent, 'parent'));
    }

    public function testTheDeanBadgeIsSectionsFinalizedAndNotSignedOffInTheDefaultTerm(): void
    {
        ['institution' => $institution, 'department' => $department] = DeanFactory::department('GD');
        $program = DeanFactory::program(DeanFactory::subDepartment($department, 'GD'), 'GD');
        $term    = DeanFactory::term($institution);
        db_connect()->table('academic_terms')->where('id', $term)->update(['status' => 'active']);
        $dean = DeanFactory::dean($institution, $department);

        $ready = DeanFactory::offering($program, $term, 'GD101');
        $this->finalize($ready, $dean);

        $signed = DeanFactory::offering($program, $term, 'GD102');
        $this->finalize($signed, $dean);
        $this->signOff($signed, $dean);

        DeanFactory::offering($program, $term, 'GD103'); // not finalized

        $archived = DeanFactory::offering($program, $term, 'GD104', 'A', 'archived');
        $this->finalize($archived, $dean);

        $older = DeanFactory::offering($program, DeanFactory::term($institution, 'Older', '2025-08-01'), 'GD105');
        $this->finalize($older, $dean);

        $this->assertSame(1, GradesBadge::for($dean, 'dean'));
        $this->assertSame('1 section ready to sign off', GradesBadge::label('dean', 1));
        $this->assertSame('4 sections ready to sign off', GradesBadge::label('dean', 4));
    }

    public function testOtherRolesHaveNoBadge(): void
    {
        $this->assertSame(0, GradesBadge::for(CourseFactory::user('admin'), 'admin'));
        $this->assertSame(0, GradesBadge::for(CourseFactory::user('instructor'), 'institutional_admin'));
    }

    /*
    |--------------------------------------------------------------------------
    | Drawing
    |--------------------------------------------------------------------------
    */

    public function testZeroDrawsNoBadge(): void
    {
        $teacher = CourseFactory::user('instructor');
        $this->assertSame(0, GradesBadge::for($teacher, 'instructor'));

        $tile = ['key' => 'grades', 'label' => 'Grades', 'icon' => 'grades', 'url' => site_url('instructor/grades')];

        foreach (['menu', 'dashboard'] as $variant) {
            $this->assertStringNotContainsString('data-app-badge', view('components/app-tile', ['app' => $tile, 'variant' => $variant], ['saveData' => false]), $variant);
            $this->assertStringNotContainsString('data-app-badge', view('components/app-tile', ['app' => $tile + ['badge' => 0, 'badge_label' => ''], 'variant' => $variant], ['saveData' => false]), $variant);
        }
    }

    public function testTheBadgeIsAPillWithTextAndALabelOnBothVariants(): void
    {
        $tile = ['key' => 'grades', 'label' => 'Grades', 'icon' => 'grades', 'url' => site_url('instructor/grades'), 'badge' => 3, 'badge_label' => '3 waiting to grade'];

        foreach (['menu', 'dashboard'] as $variant) {
            $html = view('components/app-tile', ['app' => $tile, 'variant' => $variant], ['saveData' => false]);

            $this->assertMatchesRegularExpression('/<span[^>]*data-app-badge[^>]*>\s*3\s*<\/span>/', $html, $variant);
            $this->assertStringContainsString('aria-label="3 waiting to grade"', $html, $variant);
        }
    }

    public function test99Plus(): void
    {
        $this->assertSame('99', GradesBadge::text(99));
        $this->assertSame('99+', GradesBadge::text(100));
        $this->assertSame('99+', GradesBadge::text(250));

        $tile = ['key' => 'grades', 'label' => 'Grades', 'icon' => 'grades', 'url' => site_url('dean/grades'), 'badge' => 120, 'badge_label' => GradesBadge::label('dean', 120)];
        $html = view('components/app-tile', ['app' => $tile, 'variant' => 'menu'], ['saveData' => false]);

        $this->assertMatchesRegularExpression('/data-app-badge[^>]*>\s*99\+\s*<\/span>/', $html);
        $this->assertStringContainsString('aria-label="120 sections ready to sign off"', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | Cost
    |--------------------------------------------------------------------------
    */

    public function testASecondViewWithinAMinuteRunsNoBadgeQuery(): void
    {
        // Someone with nothing to do: the zero is cached too.
        $teacher = CourseFactory::user('instructor');

        $first = $this->queriesOf(fn () => GradesBadge::for($teacher, 'instructor'));
        $this->assertNotSame([], $first, 'the first view works it out');
        $this->assertSame([['key' => 'grades-badge-instructor-' . $teacher->id, 'ttl' => 60]], $this->saved, 'cached 60 seconds per role and user');

        // The next page, within the minute: a new request, the same cache.
        GradesBadge::flush();
        $this->assertSame([], $this->queriesOf(fn () => GradesBadge::for($teacher, 'instructor')));

        // Another role of the same person is its own count.
        $this->assertNotSame([], $this->queriesOf(fn () => GradesBadge::for($teacher, 'student')));
    }

    public function testTheCostRunsOncePerRequestEvenWithoutACache(): void
    {
        $teacher = CourseFactory::user('instructor');

        GradesBadge::for($teacher, 'instructor');
        cache()->clean(); // the header has asked; Quick Access asks on the same page

        $this->assertSame([], $this->queriesOf(fn () => GradesBadge::for($teacher, 'instructor')));
    }

    public function testTwoRequestsInOneProcessDoNotShareTheMemo(): void
    {
        $teacher = CourseFactory::user('instructor');

        GradesBadge::for($teacher, 'instructor');
        cache()->clean();

        // The same request: remembered. Another request (a worker's next one): counted again.
        $this->assertSame([], $this->queriesOf(fn () => GradesBadge::for($teacher, 'instructor')));

        Services::resetSingle('request');

        $this->assertNotSame([], $this->queriesOf(fn () => GradesBadge::for($teacher, 'instructor')));
    }

    public function testForgetMakesTheNextViewCountAgain(): void
    {
        $student = CourseFactory::user('student');
        $term    = null;
        $course  = $this->studentCourse($student, $term);
        $this->seenBefore($student, $student, $course);

        $this->assertSame(1, GradesBadge::for($student, 'student'));

        db_connect()->table('grades_seen')->delete(['viewer_user_id' => $student->id]);
        GradesBadge::forget((int) $student->id, 'student');

        $this->assertSame(0, GradesBadge::for($student, 'student'));
    }

    /*
    |--------------------------------------------------------------------------
    | Reading writes nothing
    |--------------------------------------------------------------------------
    */

    public function testTheStudentBadgeNeverWritesSeen(): void
    {
        $student = CourseFactory::user('student');
        $term    = null;
        $this->seenBefore($student, $student, $this->studentCourse($student, $term));
        $this->studentCourse($student, $term); // never seen: the page would remember it, the badge must not

        $queries = $this->queriesOf(fn () => GradesBadge::for($student, 'student'));

        $this->assertSame([], $this->writes($queries));
        $this->assertSame(1, db_connect()->table('grades_seen')->countAllResults());
        $this->assertSame(1, GradesBadge::for($student, 'student'), 'still changed: only the page clears it');
    }

    public function testViewingAsSomeoneWritesNothing(): void
    {
        $institution = PeopleFactory::institution('GBV');
        $parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $institution);
        $child       = PeopleFactory::person('student', 'Ann', 'Lee', null, $institution);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);

        $term = null;
        $this->seenBefore($child, $child, $this->studentCourse($child, $term));
        $this->seenBefore($parent, $child, $this->studentCourse($child, $term));

        foreach ([[$child, 'student'], [$parent, 'parent']] as [$person, $role]) {
            $this->viewAs($person);

            $queries = $this->queriesOf(fn () => GradesBadge::for($person, $role));

            $this->assertSame([], $this->writes($queries), $role);
            $this->assertSame(1, GradesBadge::for($person, $role), $role);
        }

        $this->assertSame(2, db_connect()->table('grades_seen')->countAllResults());
    }
}
