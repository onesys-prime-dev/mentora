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

        $this->assertSame(array_column($student, 'key'), array_column($instructor, 'key'));
        $this->assertSame(['courses', 'calendar', 'schedule', 'grades', 'announcements', 'inbox'], array_column($student, 'key'));

        $this->assertSame(site_url('student/courses'), $student[0]['url']);
        $this->assertSame(site_url('instructor/calendar'), $instructor[1]['url']);
        $this->assertSame(site_url('instructor/schedule'), $instructor[2]['url']);
    }

    public function testPagesThatAreNotBuiltYetHaveNoUrl(): void
    {
        $apps = array_column(global_apps('student'), 'url', 'key');

        $this->assertNull($apps['grades']);
        $this->assertNull($apps['announcements']);
        $this->assertNull($apps['inbox']);
        $this->assertNotNull($apps['courses']);
    }

    public function testAParentOnlyGetsTilesThatLeadSomewhere(): void
    {
        $apps = global_apps('parent');

        $this->assertSame(['dashboard'], array_column($apps, 'key'));
        $this->assertSame(site_url('parent/dashboard'), $apps[0]['url']);

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
