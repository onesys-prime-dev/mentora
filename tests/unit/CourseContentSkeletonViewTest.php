<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * A course page arrives with #course-content already showing the shape of
 * the tab being opened, and with that tab marked, so the first paint is never
 * an empty card. JavaScript then replaces only what is inside it.
 *
 * @internal
 */
final class CourseContentSkeletonViewTest extends CIUnitTestCase
{
    private function source(): string
    {
        return (string) file_get_contents(APPPATH . 'Views/pages/courses/view.php');
    }

    public function testTheContentRegionIsServerRenderedAndAnnouncedAsBusy(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('id="course-content"', $source);
        $this->assertStringContainsString('aria-busy="true"', $source);
        $this->assertStringContainsString('data-server-skeleton', $source);
        $this->assertStringContainsString("pages/courses/skeletons/", $source);
    }

    public function testTheOpenedTabIsMarkedInTheServersHtml(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("\$isActiveTab = \$feature === (\$activeFeature ?? 'overview')", $source);
        $this->assertStringContainsString("aria-selected=\"<?= \$isActiveTab ? 'true' : 'false' ?>\"", $source);
    }

    public function testTheOverviewSkeletonFollowsTheRole(): void
    {
        $instructor = view('pages/courses/skeletons/overview', ['role' => 'instructor'], ['saveData' => false]);
        $student = view('pages/courses/skeletons/overview', ['role' => 'student'], ['saveData' => false]);

        $this->assertStringContainsString('lg:grid-cols-4', $instructor);
        $this->assertStringContainsString('lg:grid-cols-3', $student);
        $this->assertStringContainsString('aria-hidden="true"', $instructor);
    }

    public function testTheListSkeletonDrawsTheSameThreeRowsTheScriptsDo(): void
    {
        $html = view('pages/courses/skeletons/list', [], ['saveData' => false]);

        $this->assertSame(3, substr_count($html, 'rounded-2xl border border-gray-100 p-6'));
        $this->assertStringContainsString('animate-pulse', $html);
    }
}
