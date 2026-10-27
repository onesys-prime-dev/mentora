<?php

declare(strict_types=1);

use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The chairperson dashboard's redesigned layout (branch
 * fix/chair-dashboard-redesign): a bespoke flat-card template instead of
 * the shared 12-column grid — this just proves the page actually renders,
 * with and without program data, since DashboardServiceChairpersonTest
 * already covers the data itself.
 *
 * @internal
 */
final class ChairpersonDashboardPageTest extends RouteTestCase
{
    public function testThePageRendersForAChairpersonWithNoProgramYet(): void
    {
        $this->loggedInAs(CourseFactory::user('chairperson'));

        $result = $this->get('chairperson/dashboard');

        $result->assertStatus(200);
        $result->assertSee('Needs Attention');
        $result->assertSee('Program snapshot');
        $result->assertSee('Monitor Program');
    }

    public function testEveryTileOfTheQuickAccessStripShowsItsIcon(): void
    {
        $this->loggedInAs(CourseFactory::user('chairperson'));

        $body = html_entity_decode($this->get('chairperson/dashboard')->response()->getBody());

        // Every tile of the strip, with the colour its icon was given.
        preg_match_all('#<span class="flex"><svg[^>]*class="([^"]*)"#', $body, $icons);

        $this->assertGreaterThanOrEqual(4, count($icons[1]), 'one icon for each tile of the strip');

        foreach ($icons[1] as $class) {
            $this->assertStringContainsString('text-[#0040A6]', $class, 'an icon registered in white would vanish on the white tile');
            $this->assertStringNotContainsString('text-white', $class);
        }
    }

    public function testNoRowOfNeedsAttentionIsDrawnInWhite(): void
    {
        $chair = CourseFactory::user('chairperson');
        $this->loggedInAs($chair);

        $item = ['offering_id' => 1, 'slug' => 'x', 'assessment_id' => 1, 'student_id' => 1, 'title' => 'T', 'subtitle' => 'S', 'badge' => 'B'];

        $body = view('pages/dashboard/partials/chairperson/needs-attention', [
            'user' => $chair,
            'data' => ['items' => [
                $item + ['kind' => 'grading'],
                $item + ['kind' => 'at_risk'],
                $item + ['kind' => 'inactive'],
            ]],
        ]);

        preg_match_all('#<svg[^>]*class="([^"]*)"#', $body, $icons);

        $this->assertGreaterThanOrEqual(4, count($icons[1]), 'the heading and each of the three rows has an icon');

        foreach ($icons[1] as $class) {
            $this->assertStringNotContainsString('text-white', $class, 'an icon drawn in white on a light tint: ' . $class);
        }
    }

    public function testThePageRendersForAChairpersonWithAProgram(): void
    {
        $chair = CourseFactory::user('chairperson');
        $offering = CourseFactory::offering();

        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id'   => $this->subDepartmentOf($offering),
            'chairperson_user_id' => $chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->loggedInAs($chair);

        $result = $this->get('chairperson/dashboard');

        $result->assertStatus(200);
        $result->assertSee('Needs Attention');
    }

    private function subDepartmentOf(int $offeringId): int
    {
        return (int) db_connect()->table('course_offerings co')
            ->select('programs.sub_department_id')
            ->join('courses', 'courses.id = co.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray()['sub_department_id'];
    }
}
