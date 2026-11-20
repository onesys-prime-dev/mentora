<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The courses page's grids follow their container, not the viewport (no database needed). The page extends
 * the app layout, so its source is read; the cards are rendered.
 *
 * @internal
 */
final class CourseGridContainerTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon', 'grade']);
    }

    public function testBothGridsAreContainerQueryGrids(): void
    {
        $page = file_get_contents(APPPATH . 'Views/pages/courses/index.php');

        $this->assertSame(2, substr_count($page, 'grid grid-cols-1 gap-5 @md:grid-cols-2 @3xl:grid-cols-3 @5xl:grid-cols-4'));
        $this->assertSame(2, substr_count($page, '<div class="@container w-full min-w-0">'));
        $this->assertStringContainsString('text-fluid-h1', $page);

        // Leading space: "@3xl:grid-cols-3" contains "xl:grid-cols-3".
        foreach ([' sm:grid-cols-2', ' xl:grid-cols-3', ' 2xl:grid-cols-4', ' text-2xl'] as $old) {
            $this->assertStringNotContainsString($old, $page);
        }
    }

    public function testCourseCardCoverIsFluidAndTitleStaysClamped(): void
    {
        $html = view('pages/courses/partials/course-card', [
            'course' => ['offering_id' => 1, 'slug' => 'c', 'course_code' => 'IT1', 'section_code' => 'A', 'course_title' => str_repeat('Supercalifragilistic', 8), 'progress' => 40],
            'userRole' => 'student',
            'isInstructor' => false,
        ], ['saveData' => false]);

        $this->assertStringContainsString('h-28 w-full object-cover xs:h-32', $html);
        $this->assertStringContainsString('line-clamp-2', $html);
        $this->assertStringContainsString('break-words', $html);
    }

    public function testParentCardsAndGridsFollowTheirContainer(): void
    {
        $course = [
            'offering_id' => 1, 'course_code' => 'IT1', 'section_code' => 'A', 'course_title' => 'Networks', 'instructor_name' => '', 'term_name' => 'T',
            'graded' => false, 'tag' => 'On track', 'enrollment_status' => 'enrolled', 'percentage' => null, 'band' => null, 'is_final' => false,
        ];
        $card = view('pages/parent/partials/course-card', ['course' => $course, 'past' => false, 'childId' => 2], ['saveData' => false]);
        $this->assertStringContainsString('min-w-0', $card);
        $this->assertStringContainsString('break-words', $card);

        $past = view('pages/parent/partials/past-terms', ['past' => [$course], 'childId' => 2], ['saveData' => false]);
        $this->assertStringContainsString('@container', $past);
        $this->assertStringContainsString('@md:grid-cols-2', $past);
        $this->assertStringNotContainsString(' md:grid-cols-2', $past);
    }
}
