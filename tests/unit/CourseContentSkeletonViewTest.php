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

    public function testEveryTabHasItsOwnSkeletonPartial(): void
    {
        foreach (['overview', 'announcements', 'modules', 'resources', 'discussions', 'assessments', 'grades'] as $tab) {
            foreach (['instructor', 'student'] as $role) {
                $html = view('pages/courses/skeletons/' . $tab, ['role' => $role], ['saveData' => false]);

                $this->assertStringContainsString('animate-pulse', $html, $tab);
                $this->assertStringContainsString('data-server-skeleton-shape', $html, $tab);
                $this->assertStringContainsString('aria-hidden="true"', $html, $tab);
            }
        }
    }

    public function testTheInstructorsSkeletonsCarryTheirExtraControls(): void
    {
        $instructor = view('pages/courses/skeletons/modules', ['role' => 'instructor'], ['saveData' => false]);
        $student = view('pages/courses/skeletons/modules', ['role' => 'student'], ['saveData' => false]);

        $this->assertStringContainsString('h-10 w-36 rounded-full', $instructor);
        $this->assertStringNotContainsString('h-10 w-36 rounded-full', $student);
    }

    public function testTheViewPicksTheSkeletonOfTheActiveTab(): void
    {
        $this->assertStringContainsString('? $activeFeature : ', $this->source());
        $this->assertStringContainsString('array_key_exists($activeFeature', $this->source());
    }
}
