<?php

declare(strict_types=1);

use App\Services\Courses\GradeService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The small breakdown a student's course row loads when it is expanded: the periods as a table, the categories
 * behind a toggle, the standing line; and that it never shows (or creates) what is not a published grade.
 *
 * @internal
 */
final class StudentGradesBreakdownTest extends RouteTestCase
{
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $institution   = PeopleFactory::institution('SGB');
        $this->student = PeopleFactory::person('student', 'Ada', 'Lovelace', null, $institution);
    }

    /**
     * An offering with a scheme of the given status; the student is in it unless asked not to be.
     *
     * @return array{0: int, 1: int} offering, scheme
     */
    private function course(bool $periods, string $status = 'Published', bool $enrolled = true): array
    {
        $offering = CourseFactory::offering();

        if ($enrolled) {
            CourseFactory::enroll($offering, $this->student);
        }

        $scheme = GradeFactory::scheme($offering, 75.0, $periods);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => $status]);

        return [$offering, $scheme];
    }

    /** A category with one item the student scored $score of 100 on. */
    private function graded(int $offering, int $scheme, string $name, float $weight, ?int $period, float $score): void
    {
        $category = GradeFactory::category($scheme, $name, $weight, $period);
        $item     = GradeFactory::item($scheme, $category, $name . ' 1', 100);
        GradeFactory::score($offering, $this->student, $item, $score);
    }

    /** @return array<string, mixed> */
    private function fragment(int $offering): array
    {
        $this->loggedInAs($this->student);

        $result = $this->get("student/api/grades/courses/{$offering}");
        $result->assertStatus(200);

        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success']);

        return $json;
    }

    private function assertIsNotFound(string $path): void
    {
        try {
            $status = $this->get($path)->response()->getStatusCode();
        } catch (PageNotFoundException) {
            $status = 404;
        }

        $this->assertSame(404, $status, $path);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /** @return list<string> */
    private function texts(DOMXPath $x, string $query): array
    {
        $out = [];

        foreach ($x->query($query) as $node) {
            $out[] = $this->text($node);
        }

        return $out;
    }

    public function testTheBreakdownShowsPeriodsAndHidesCategoriesBehindTheToggle(): void
    {
        [$offering, $scheme] = $this->course(true);
        $prelim              = GradeFactory::period($scheme, 'Prelim', 30, 1);
        $finals              = GradeFactory::period($scheme, 'Finals', 70, 2);
        $this->graded($offering, $scheme, 'Quizzes', 40, $prelim, 93);
        $this->graded($offering, $scheme, 'Exams', 60, $prelim, 90);
        GradeFactory::category($scheme, 'Final exam', 100, $finals);

        $x = $this->xpath($this->fragment($offering)['html']);

        $this->assertSame(['Prelim 30%', 'Finals 70%'], $this->texts($x, '//table[@data-grades-breakdown]//th'));
        $this->assertSame(['91.2%', 'not yet'], $this->texts($x, '//table[@data-grades-breakdown]//td'));

        $toggle = $x->query('//button[@data-categories-toggle]')->item(0);
        $this->assertNotNull($toggle);
        $this->assertSame('button', $toggle->getAttribute('type'));
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
        $this->assertSame('Show categories', $this->text($toggle));

        $block = $x->query('//*[@data-categories]')->item(0);
        $this->assertNotNull($block);
        $this->assertTrue($block->hasAttribute('hidden'));
        $this->assertStringContainsString('Quizzes (40%) — 93.0%', $this->text($block));
        $this->assertStringContainsString('Exams (60%) — 90.0%', $this->text($block));
        $this->assertStringContainsString('Prelim', $this->text($block));

        $this->assertStringContainsString('Passing is 75%', $this->text($x->query('//body')->item(0)));
    }

    public function testASchemeWithoutPeriodsShowsCategoriesAsColumns(): void
    {
        [$offering, $scheme] = $this->course(false);
        $this->graded($offering, $scheme, 'Quizzes', 40, null, 80);
        GradeFactory::category($scheme, 'Exams', 60);

        $x = $this->xpath($this->fragment($offering)['html']);

        $this->assertSame(['Quizzes 40%', 'Exams 60%'], $this->texts($x, '//table[@data-grades-breakdown]//th'));
        $this->assertSame(['80.0%', 'not yet'], $this->texts($x, '//table[@data-grades-breakdown]//td'));
        $this->assertSame(0, $x->query('//button[@data-categories-toggle]')->length);
        $this->assertSame(0, $x->query('//*[@data-categories]')->length);
    }

    public function testTheStandingLineNamesIncompleteAndLinksToTheCourseTab(): void
    {
        [$offering, $scheme] = $this->course(false);
        $this->graded($offering, $scheme, 'Quizzes', 100, null, 80);
        $this->fragment($offering); // calculates the record

        db_connect()->table('gradebook_records')->where('course_offering_id', $offering)->where('student_id', $this->student->id)->update([
            'remarks' => 'INC', 'inc_set_at' => date('Y-m-d H:i:s'), 'inc_reason' => 'Medical leave', 'inc_until' => '2026-12-20',
        ]);

        $x    = $this->xpath($this->fragment($offering)['html']);
        $body = $this->text($x->query('//body')->item(0));

        $this->assertStringContainsString('INC · finish by Dec 20, 2026', $body);
        $this->assertStringContainsString('Medical leave', $body);

        $title = (string) db_connect()->query('SELECT c.course_title FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE o.id = ?', [$offering])->getRow()->course_title;
        $link  = $x->query('//a[contains(., "Open course grades")]')->item(0);

        $this->assertNotNull($link);
        $this->assertSame(site_url("student/courses/{$offering}/" . url_title($title, '-', true) . '/grades'), $link->getAttribute('href'));
    }

    public function testAnotherCoursesBreakdownIs404(): void
    {
        [$other] = $this->course(false, 'Published', false);

        $this->loggedInAs($this->student);

        $this->assertIsNotFound("student/api/grades/courses/{$other}");
        $this->assertIsNotFound('student/api/grades/courses/999999');
    }

    public function testAnUnpublishedOrMissingSchemeIs404AndCreatesNothing(): void
    {
        [$draft] = $this->course(false, 'Draft');

        $none = CourseFactory::offering('B');
        CourseFactory::enroll($none, $this->student);

        $this->loggedInAs($this->student);

        $this->assertIsNotFound("student/api/grades/courses/{$draft}");
        $this->assertIsNotFound("student/api/grades/courses/{$none}");

        $this->assertSame(0, db_connect()->table('grading_schemes')->where('course_offering_id', $none)->countAllResults());
        $this->assertSame(0, db_connect()->table('gradebook_records')->whereIn('course_offering_id', [$draft, $none])->countAllResults());
    }

    public function testTheNumbersMatchTheCourseTab(): void
    {
        [$offering, $scheme] = $this->course(true);
        $prelim              = GradeFactory::period($scheme, 'Prelim', 50, 1);
        $midterm             = GradeFactory::period($scheme, 'Midterm', 50, 2);
        $this->graded($offering, $scheme, 'Quizzes', 100, $prelim, 87.5);
        GradeFactory::category($scheme, 'Exams', 100, $midterm);

        $html     = $this->fragment($offering)['html'];
        $courseId = (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRow()->course_id;
        $page     = (new GradeService())->buildStudentGrades($this->student, ['id' => $offering, 'course_id' => $courseId]);

        $x = $this->xpath($html);

        $expected = array_map(
            static fn (array $p): string => $p['percentage'] === null ? 'not yet' : number_format((float) $p['percentage'], 1) . '%',
            $page['periodBreakdown'],
        );

        $this->assertSame($expected, $this->texts($x, '//table[@data-grades-breakdown]//td'));
        $this->assertSame('87.5%', $expected[0]);
        $this->assertStringContainsString($page['summary']['tag'], $this->text($x->query('//body')->item(0)));
    }

    public function testTheStandingLooksTheSameAsOnTheRow(): void
    {
        helper(['grade', 'icon']);

        foreach (['On track', 'At risk', 'Passed', 'Failed'] as $tag) {
            $html = view('pages/grades/partials/student/breakdown', [
                'summary'    => ['tag' => $tag, 'inc_until' => null, 'inc_reason' => null, 'passing_percentage' => 75.0, 'is_adjusted' => false],
                'periods'    => [],
                'categories' => [],
                'courseUrl'  => site_url('student/courses/1/x/grades'),
            ], ['saveData' => false]);

            // The row's colour and icon for the standing (grade_tag_look), not a table of the breakdown's own.
            $badge = view('components/status-badge', ['label' => $tag] + grade_tag_look($tag), ['saveData' => false]);

            // Compared without the debug toolbar's view markers and with whitespace folded.
            $plain = static fn (string $markup): string => trim((string) preg_replace(['/<!-- DEBUG-VIEW[^>]*-->/', '/\s+/'], ['', ' '], $markup));

            $this->assertStringContainsString($plain($badge), $plain($html), $tag);
        }
    }
}
