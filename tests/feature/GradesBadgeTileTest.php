<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\Mock\MockCache;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The Grades tile's count on real pages: in the header's Apps menu and on the dashboard's Quick Access, worked
 * out once per page and not again within the minute; a student's count clears once their Grades page has shown
 * what changed, and viewing as them keeps it.
 *
 * @internal
 */
final class GradesBadgeTileTest extends RouteTestCase
{
    /** @var list<string> cache keys saved, in order */
    private array $saved = [];

    private int $institution;

    private int $program;

    private int $department;

    private int $term;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $saved = &$this->saved;
        Services::injectMock('cache', new class ($saved) extends MockCache {
            /** @param list<string> $saved */
            public function __construct(private array &$saved)
            {
            }

            public function save(string $key, $value, int $ttl = 60): bool
            {
                $this->saved[] = $key;

                return parent::save($key, $value, $ttl);
            }
        });

        ['institution' => $this->institution, 'college' => $college] = DeanFactory::college('GT');
        $this->department     = DeanFactory::department($college, 'GT');
        $this->program = DeanFactory::program($this->department, 'GT');
        $this->term    = DeanFactory::term($this->institution);
        db_connect()->table('academic_terms')->where('id', $this->term)->update(['status' => 'active']);
    }

    private function waiting(int $offering, User $teacher, int $count): void
    {
        $quiz = CourseFactory::assessment($offering, $teacher, CourseFactory::module($offering), null, 'Quiz');
        db_connect()->table('assessments')->where('id', $quiz)->update(['status' => 'Published']);

        for ($i = 0; $i < $count; $i++) {
            $student = PeopleFactory::person('student', 'S', 'Tudent', null, $this->institution);
            CourseFactory::enroll($offering, $student);
            AssessmentFactory::attempt($quiz, $student, 'Submitted');
        }
    }

    private function page(User $as, string $uri): DOMXPath
    {
        $this->loggedInAs($as);
        $result = $this->get($uri);
        $result->assertStatus(200);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML((string) $result->getBody());
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /** @return list<array{text: string, label: string}> the badges inside $scope */
    private function badges(DOMXPath $x, string $scope): array
    {
        $out = [];

        foreach ($x->query($scope . "//*[@data-app-badge]") as $node) {
            $out[] = ['text' => trim($node->textContent), 'label' => $node->getAttribute('aria-label')];
        }

        return $out;
    }

    public function testTheInstructorSeesTheCountInTheMenuAndOnQuickAccessWorkedOutOnce(): void
    {
        $teacher  = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $offering = DeanFactory::offering($this->program, $this->term, 'GT101');
        CourseFactory::assignInstructor($offering, $teacher);
        $this->waiting($offering, $teacher, 2);

        $x     = $this->page($teacher, 'instructor/dashboard');
        $badge = ['text' => '2', 'label' => '2 waiting to grade'];

        $this->assertSame([$badge], $this->badges($x, "//*[@id='app-menu-panel']//*[@data-app-tile='grades']"));
        $this->assertSame([$badge, $badge], $this->badges($x, ''), 'the menu and Quick Access, nothing else');
        $this->assertSame(['grades-badge-instructor-' . $teacher->id], $this->saved, 'worked out once for the page');

        // The next page within the minute shows the same count without working it out.
        \App\Services\Grades\GradesBadge::flush();
        $this->assertSame([$badge], $this->badges($this->page($teacher, 'instructor/courses'), "//*[@id='app-menu-panel']"));
        $this->assertCount(1, $this->saved);
    }

    public function testTheChairpersonsQuickAccessStripCarriesTheCountToo(): void
    {
        $chair    = DeanFactory::chair($this->department);
        $offering = DeanFactory::offering($this->program, $this->term, 'GT111');
        CourseFactory::assignInstructor($offering, $chair);
        $this->waiting($offering, $chair, 1);

        $badge = ['text' => '1', 'label' => '1 waiting to grade'];

        $this->assertSame([$badge, $badge], $this->badges($this->page($chair, 'chairperson/dashboard'), ''), 'the menu and the strip');
    }

    public function testTheDeanSeesSectionsReadyToSignOff(): void
    {
        $dean = DeanFactory::dean($this->institution, (int) db_connect()->table('departments')->where('id', db_connect()->table('programs')->where('id', $this->program)->get()->getRow()->department_id)->get()->getRow()->college_id);

        foreach (['GT201', 'GT202'] as $code) {
            db_connect()->table('grade_finalizations')->insert(['course_offering_id' => DeanFactory::offering($this->program, $this->term, $code), 'finalized_by' => $dean->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
        }

        $this->assertSame(
            [['text' => '2', 'label' => '2 sections ready to sign off']],
            $this->badges($this->page($dean, 'dean/dashboard'), "//*[@id='app-menu-panel']//*[@data-app-tile='grades']")
        );
    }

    public function testNothingToDoDrawsNoBadge(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);

        $this->assertSame([], $this->badges($this->page($teacher, 'instructor/dashboard'), ''));
    }

    public function testAStudentsCountClearsOnceTheirGradesPageHasShownIt(): void
    {
        $student  = PeopleFactory::person('student', 'Ada', 'Lovelace', null, $this->institution);
        $offering = DeanFactory::offering($this->program, $this->term, 'GT301');
        CourseFactory::enroll($offering, $student);
        $scheme = GradeFactory::scheme($offering, 75.0, false, GradeFactory::scale());
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => $scheme,
            'calculated_percentage' => 82, 'is_override' => 0, 'remarks' => 'Passed', 'last_calculated_at' => date('Y-m-d H:i:s'),
        ]);
        db_connect()->table('grades_seen')->insert([
            'viewer_user_id' => $student->id, 'student_user_id' => $student->id, 'offering_id' => $offering,
            'signature' => str_repeat('0', 64), 'seen_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        $tile  = "//*[@id='app-menu-panel']//*[@data-app-tile='grades']";
        $badge = [['text' => '1', 'label' => '1 course changed']];

        $this->assertSame($badge, $this->badges($this->page($student, 'student/dashboard'), $tile));

        // Viewing as the student shows the same and remembers nothing, so the count stays.
        session()->set('impersonator_id', 1);
        session()->set('impersonation', ['target_id' => (int) $student->id, 'reason' => 'Checking the badge', 'started_at' => time(), 'expires_at' => time() + 600]);
        $this->withSession();
        \App\Services\Grades\GradesBadge::flush();

        $this->assertSame($badge, $this->badges($this->page($student, 'student/grades'), $tile));
        \App\Services\Grades\GradesBadge::flush();
        $this->assertSame($badge, $this->badges($this->page($student, 'student/dashboard'), $tile));

        session()->remove(['impersonator_id', 'impersonation']);
        $this->withSession();

        // Their own look: the page still badges what changed, then the next page has nothing left to count.
        \App\Services\Grades\GradesBadge::flush();
        $this->assertSame($badge, $this->badges($this->page($student, 'student/grades'), $tile));
        \App\Services\Grades\GradesBadge::flush();
        $this->assertSame([], $this->badges($this->page($student, 'student/dashboard'), $tile));
    }
}
