<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The instructor's gradebook on phones (no database): the roster and At risk grids scroll inside a pinned
 * [data-scroll-x] region with the name column sticky, the roster's score inputs are decimal and finger-sized, a
 * roster without students is the empty state and no grid, the toolbar's menus have a phone sheet, the scheme
 * editor's weights are decimal, and the printable reports scroll their tables on a phone screen.
 *
 * @internal
 */
final class GradebookResponsiveTest extends CIUnitTestCase
{
    private const DIR = 'pages/courses/features/grades/';

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

    private function has(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /** @param list<array<string, mixed>> $students */
    private function roster(array $students): string
    {
        return view(self::DIR . 'roster', [
            'students'         => $students,
            'items'            => [['id' => 11, 'name' => 'Attendance', 'max_points' => 10], ['id' => 12, 'name' => 'Participation', 'max_points' => 20]],
            'breakdown_groups' => [['id' => 1, 'name' => 'Quizzes']],
            'uses_periods'     => false,
        ], ['saveData' => false]);
    }

    private function student(): array
    {
        return [
            'student_id' => 4, 'student_name' => 'Cruz, Angela', 'student_number' => '2026-0004',
            'scores' => [11 => 8.0, 12 => null], 'excused' => [11 => false, 12 => false], 'breakdown' => [1 => 80.0],
            'percentage' => 80.0, 'calculated_percentage' => 80.0, 'is_override' => false, 'override_reason' => null,
            'remarks' => 'Passed', 'tag' => 'On track', 'inc_reason' => null, 'inc_until' => null,
        ];
    }

    public function testTheRosterGridScrollsInsideAPinnedRegion(): void
    {
        $x = $this->xpath($this->roster([$this->student()]));

        $region = '//div[@data-scroll-x and @data-sticky-first and @tabindex="0" and @role="region" and @aria-label="Class roster"]';
        $this->assertSame(1, $x->query($region . '//table')->length);

        // The header row is sticky, its first cell pinned on both axes; the row's name cell is pinned and opaque.
        $this->assertSame(0, $x->query($region . '//thead//th[not(' . $this->has('sticky') . ') or not(' . $this->has('top-0') . ') or not(' . $this->has('whitespace-nowrap') . ')]')->length);
        $this->assertSame(1, $x->query($region . '//thead//tr/th[1][' . $this->has('left-0') . ' and ' . $this->has('bg-gray-50') . ']')->length);
        $this->assertSame(1, $x->query($region . '//tr[@data-roster-row]/td[1][' . $this->has('sticky') . ' and ' . $this->has('left-0') . ' and ' . $this->has('bg-white') . ']')->length);

        // Scores: decimal keyboard, 16px on a phone.
        $inputs = $x->query($region . '//input[@data-manual-score-input]');
        $this->assertSame(2, $inputs->length);
        foreach ($inputs as $input) {
            $this->assertSame('decimal', $input->getAttribute('inputmode'));
            $this->assertStringContainsString('text-base sm:text-sm', $input->getAttribute('class'));
        }
    }

    public function testARosterWithoutStudentsIsTheEmptyStateAndNoGrid(): void
    {
        $html = $this->roster([]);

        $this->assertStringContainsString('No students yet', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('data-scroll-x', $html);
    }

    public function testTheAtRiskTableScrollsInsideAPinnedRegion(): void
    {
        $html = view('pages/grades/partials/instructor/at-risk', [
            'area'   => 'instructor',
            'reason' => null,
            'atRisk' => [['offering_id' => 1, 'student_id' => 4, 'name' => 'Angela Cruz', 'section' => 'IT101-A', 'slug' => 'it101', 'reasons' => [['kind' => 'grade', 'text' => 'Failing so far']]]],
        ], ['saveData' => false]);

        $this->assertSame(1, $this->xpath($html)->query('//div[@data-scroll-x and @data-sticky-first and @tabindex="0" and @role="region" and @aria-label="Students at risk"]//table')->length);
    }

    public function testTheToolbarReportMenuHasAPhoneSheet(): void
    {
        $x = $this->xpath(view(self::DIR . 'partials/gradebook-toolbar', ['gradebook' => ['scheme' => ['status' => 'Published', 'name' => 'Default']]], ['saveData' => false]));

        // Below sm the ⋯-style opener moves the menu's items into a sheet (shared/row-actions.js); from sm the dropdown.
        $this->assertSame(1, $x->query('//*[@data-row]/button[@data-toggle-report-menu and ' . $this->has('max-sm:hidden') . ']')->length);
        $this->assertSame(1, $x->query('//*[@data-row]/button[@data-row-actions-open and @aria-controls="report-menu-sheet" and @data-row-label="Reports" and ' . $this->has('sm:hidden') . ']')->length);
        $this->assertSame(1, $x->query('//*[@data-row]/div[@data-report-menu and @data-role="actions"]')->length);
        $this->assertSame(1, $x->query('//div[@id="report-menu-sheet" and @data-dialog-variant="sheet"]//*[@data-row-actions-slot]')->length);
        // The sheet sits outside the sticky toolbar, so the bar's stacking never clips it.
        $this->assertSame(0, $x->query('//*[@data-gradebook-toolbar]//*[@id="report-menu-sheet"]')->length);
    }

    public function testTheSchemeEditorWeightsAreDecimalAndFingerSized(): void
    {
        $html = view(self::DIR . 'scheme-editor', [
            'scheme'                => ['status' => 'Draft', 'name' => 'Default', 'passing_percentage' => 75, 'grade_scale_id' => null],
            'uses_periods'          => false,
            'total_weight'          => 100,
            'categories'            => [['id' => 5, 'name' => 'Quizzes', 'weight_percentage' => 100, 'period_id' => null, 'items' => [
                ['id' => 9, 'is_manual' => true, 'assessment_type' => null, 'name' => 'Attendance', 'display_name' => 'Attendance', 'max_points' => 10, 'category_id' => 5, 'is_out_of_sync' => false],
            ]]],
            'periods'               => [],
            'unassigned_categories' => [],
            'scales'                => [],
            'scale_items'           => [],
            'removed'               => [],
            'uncategorized'         => [],
        ], ['saveData' => false]);

        $x = $this->xpath($html);

        foreach (['data-grade-category-weight', 'data-grade-item-max', 'data-scheme-passing'] as $hook) {
            $input = $x->query("//input[@{$hook}]")->item(0);
            $this->assertNotNull($input, $hook);
            $this->assertSame('decimal', $input->getAttribute('inputmode'), $hook);
            $this->assertStringContainsString('pointer-coarse:min-h-11', $input->getAttribute('class'), $hook);
            $this->assertStringContainsString('text-base sm:text-sm', $input->getAttribute('class'), $hook);
        }

        // The name takes its own row below sm, so name, weight and remove wrap instead of squeezing.
        $this->assertStringContainsString('max-sm:basis-full', $x->query('//input[@data-grade-category-name]')->item(0)->getAttribute('class'));
        $this->assertSame(0, $x->query('//button[(@data-delete-grade-category or @data-remove-grade-item or @data-move-grade-item or @data-move-grade-category) and not(' . $this->has('pointer-coarse:min-h-11') . ')]')->length);
        $this->assertStringContainsString('p-5 sm:p-8 lg:p-10', $html);
    }

    public function testThePrintableReportsScrollTheirTablesOnAPhone(): void
    {
        foreach (['class-summary' => 3, 'student-report' => 2] as $report => $scrollers) {
            $src = (string) file_get_contents(APPPATH . 'Views/' . self::DIR . "reports/{$report}.php");

            $this->assertGreaterThanOrEqual($scrollers, substr_count($src, '<div data-scroll-x style="overflow-x:auto'), $report);
            $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $src, $report);
        }
    }
}
