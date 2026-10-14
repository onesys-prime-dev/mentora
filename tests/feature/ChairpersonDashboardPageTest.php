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
