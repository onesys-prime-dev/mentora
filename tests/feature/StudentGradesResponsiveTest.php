<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Grades on phones (no database): the summary tiles and the parent's categories answer to their container, and the
 * breakdown and report tables scroll inside their own box.
 *
 * @internal
 */
final class StudentGradesResponsiveTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon', 'grade']);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($dom);
    }

    public function testSummaryUsesContainerColumns(): void
    {
        $html = view('pages/grades/partials/student/summary', [
            'summary'  => ['units' => 21.0, 'gwa' => 1.5, 'passed' => 4, 'failed' => 0, 'inc' => 0, 'not_counted' => []],
            'hasScale' => true,
        ], ['saveData' => false]);

        $x = $this->xpath($html);

        $this->assertSame(1, $x->query('//div[contains(@class,"@container")]/div[@data-grades-summary and contains(@class,"@xl:grid-cols-4")]')->length);
        $this->assertStringNotContainsString('lg:grid-cols', $html);
    }

    public function testBreakdownTableScrollsInsideItsBox(): void
    {
        $html = view('pages/grades/partials/student/breakdown', [
            'summary'    => ['tag' => 'Passed', 'passing_percentage' => 75, 'inc_until' => null, 'is_adjusted' => false],
            'periods'    => [],
            'categories' => [['name' => 'Quizzes', 'weight' => 40, 'percentage' => 88.0], ['name' => 'Exams', 'weight' => 60, 'percentage' => null]],
            'courseUrl'  => '/student/courses/1/x/grades',
        ], ['saveData' => false]);

        $this->assertSame(1, $this->xpath($html)->query('//div[@data-scroll-x and @data-sticky-first]//table[@data-grades-breakdown]')->length);
    }

    public function testStudentReportTablesScroll(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Views/pages/courses/features/grades/reports/student-report.php');

        $this->assertSame(2, substr_count($src, '<div data-scroll-x'));
    }
}
