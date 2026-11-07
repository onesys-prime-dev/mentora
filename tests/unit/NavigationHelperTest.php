<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The global app list behind the header menu and the dashboard's Quick Access.
 *
 * @internal
 */
final class NavigationHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['navigation', 'icon']);
    }

    public function testEveryRoleGetsTheSameAppsUnderItsOwnPrefix(): void
    {
        $student = global_apps('student');
        $instructor = global_apps('instructor');

        // The instructor has the student's apps, and the Requests a dean or chairperson sent them.
        $this->assertSame([...array_column($student, 'key'), 'follow-ups'], array_column($instructor, 'key'));
        $this->assertSame(['courses', 'calendar', 'schedule', 'grades', 'announcements'], array_column($student, 'key'));

        $this->assertSame(site_url('student/courses'), $student[0]['url']);
        $this->assertSame(site_url('instructor/calendar'), $instructor[1]['url']);
        $this->assertSame(site_url('instructor/schedule'), $instructor[2]['url']);
    }

    public function testNoInboxTileAnyMoreAndNoMessagesTileWhileTheFlagIsOff(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'dean', 'parent', 'institutional_admin', 'admin'] as $role) {
            $keys = array_column(global_apps($role), 'key');

            $this->assertNotContains('inbox', $keys, $role);
            $this->assertNotContains('messages', $keys, $role);
        }
    }

    public function testFollowUpsAreLinkedForTheDeanTheChairpersonAndTheInstructorOnly(): void
    {
        foreach (['dean', 'chairperson', 'instructor'] as $role) {
            $this->assertSame(site_url($role . '/follow-ups'), array_column(global_apps($role), 'url', 'key')['follow-ups'], $role);
        }

        $this->assertArrayNotHasKey('follow-ups', array_column(global_apps('student'), 'url', 'key'));
        $this->assertSame('Requests', array_column(global_apps('instructor'), 'label', 'key')['follow-ups']);
    }

    public function testAnnouncementsLeadToTheOneListForTheRolesThatHaveIt(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'dean'] as $role) {
            $this->assertSame(site_url($role . '/announcements'), array_column(global_apps($role), 'url', 'key')['announcements'], $role);
        }
    }

    public function testEveryStudentTileOpensAPage(): void
    {
        // Grades is built and Inbox gave way to Messages, so a student has no inert tile left.
        $apps = array_column(global_apps('student'), 'url', 'key');

        $this->assertNotContains(null, $apps);
        $this->assertNotNull($apps['grades']);
        $this->assertNotNull($apps['courses']);
    }

    public function testTheTileOpensTheGradesPage(): void
    {
        $this->assertStringEndsWith('instructor/grades', (string) array_column(global_apps('instructor'), 'url', 'key')['grades']);
    }

    public function testTheTileOpensTheGradesPageForChairpersons(): void
    {
        $url = array_column(global_apps('chairperson'), 'url', 'key')['grades'];

        $this->assertSame(site_url('chairperson/grades'), $url);

        $collection = \Config\Services::routes(false);
        $collection->loadRoutes();

        $this->assertArrayHasKey('chairperson/grades', $collection->getRoutes('GET'));
    }

    public function testTheTileOpensTheGradesPageForDeans(): void
    {
        $tile = array_column(global_apps('dean'), null, 'key')['grades'];

        $this->assertSame(['key' => 'grades', 'label' => 'Grades', 'icon' => 'grades', 'url' => site_url('dean/grades')], $tile);

        // Beside Courses: the dean's lists of the college's sections sit together.
        $keys = array_column(global_apps('dean'), 'key');
        $this->assertSame(array_search('courses', $keys, true) + 1, array_search('grades', $keys, true));

        $collection = \Config\Services::routes(false);
        $collection->loadRoutes();

        $this->assertArrayHasKey('dean/grades', $collection->getRoutes('GET'));
    }

    public function testTheTileOpensTheGradesPageForStudents(): void
    {
        $this->assertSame(site_url('student/grades'), array_column(global_apps('student'), 'url', 'key')['grades']);
    }

    public function testTheParentsGradesTileOpensTheParentsGradesPage(): void
    {
        $tile = array_column(global_apps('parent'), null, 'key')['grades'];

        $this->assertSame(['key' => 'grades', 'label' => 'Grades', 'icon' => 'grades', 'url' => site_url('parent/grades')], $tile);
    }

    public function testAParentOnlyGetsTilesThatLeadSomewhere(): void
    {
        $apps = global_apps('parent');

        $this->assertSame(['dashboard', 'calendar', 'grades', 'notifications', 'profile'], array_column($apps, 'key'));
        $this->assertSame(site_url('parent/dashboard'), $apps[0]['url']);
        $this->assertSame(site_url('parent/calendar'), $apps[1]['url']);
        $this->assertSame(site_url('parent/grades'), $apps[2]['url']);
        $this->assertSame(site_url('notifications'), $apps[3]['url']);
        $this->assertSame(site_url('parent/profile'), $apps[4]['url']);

        $collection = \Config\Services::routes(false);
        $collection->loadRoutes();

        foreach ($apps as $app) {
            $path = trim(str_replace(rtrim(site_url(), '/'), '', $app['url']), '/');

            $this->assertArrayHasKey($path, $collection->getRoutes('GET'), $app['key']);
        }
    }

    public function testMonitorProgramIsOnlyLinkedForAChairperson(): void
    {
        foreach (['student', 'instructor'] as $role) {
            $this->assertArrayNotHasKey('monitor-program', array_column(global_apps($role), 'url', 'key'), $role . ' has no Monitor Program tile');
        }

        $url = array_column(global_apps('chairperson'), 'url', 'key')['monitor-program'];

        $this->assertSame(site_url('chairperson/monitor-program'), $url);

        $collection = \Config\Services::routes(false);
        $collection->loadRoutes();
        $path = trim(str_replace(rtrim(site_url(), '/'), '', $url), '/');

        $this->assertArrayHasKey($path, $collection->getRoutes('GET'));
    }

    public function testEveryAppHasALabelAndARealIcon(): void
    {
        $registry = icon_registry();

        foreach (global_apps('student') as $app) {
            $this->assertNotSame('', $app['label'], $app['key']);
            $this->assertArrayHasKey($app['icon'], $registry, $app['key']);
        }
    }

    public function testEveryLinkedAppPointsAtARealRoute(): void
    {
        $collection = \Config\Services::routes(false);
        $collection->loadRoutes();
        $routes = $collection->getRoutes('GET');

        foreach (['student', 'instructor'] as $role) {
            foreach (global_apps($role) as $app) {

                if ($app['url'] === null) {
                    continue;
                }

                $path = trim(str_replace(rtrim(site_url(), '/'), '', $app['url']), '/');

                $this->assertArrayHasKey($path, $routes, "{$role} {$app['key']} -> {$path}");
            }
        }
    }
}
