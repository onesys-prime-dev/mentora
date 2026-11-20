<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Below lg the student dashboard's lists show five rows; the rest are max-lg:hidden behind a lg:hidden
 * "View all" (no database needed: the partials are rendered with their data). The announcements card asks
 * auth() for the active role, so its cap is checked in StudentDashboardTabsTest.
 *
 * @internal
 */
final class DashboardListCapsTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon']);
    }

    private function render(string $partial, array $data): DOMXPath
    {
        // The partials only ask the user for its groups.
        $user = new class () {
            public function getGroups(): array
            {
                return ['student'];
            }
        };

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . view('pages/dashboard/partials/' . $partial, ['user' => $user, 'data' => $data], ['saveData' => false]) . '</body>');

        return new DOMXPath($doc);
    }

    private function hidden(DOMXPath $x, string $row): array
    {
        return array_map(
            static fn ($el) => str_contains(' ' . $el->getAttribute('class') . ' ', ' max-lg:hidden '),
            iterator_to_array($x->query("//*[@{$row}]"))
        );
    }

    private function tasks(int $n): array
    {
        return array_map(static fn ($i) => ['id' => $i, 'offering_id' => 1, 'slug' => 'c', 'code' => 'IT1', 'title' => "T{$i}", 'due' => 'Today', 'urgency' => 'later'], range(1, $n));
    }

    public function testTasksCapAtFiveWithViewAllToTheCalendar(): void
    {
        $x = $this->render('upcoming-tasks', ['tasks' => $this->tasks(8)]);

        $this->assertSame([false, false, false, false, false, true, true, true], $this->hidden($x, 'data-task-row'));
        $this->assertSame(3, $x->query('//div[@aria-hidden="true"][contains(@class,"max-lg:hidden")]')->length, 'the dividers above hidden rows hide too');

        $all = $x->query('//a[@data-view-all]');
        $this->assertSame(1, $all->length);
        $this->assertSame(site_url('student/calendar'), $all->item(0)->getAttribute('href'));
        $this->assertStringContainsString('lg:hidden', $all->item(0)->getAttribute('class'));
    }

    public function testFiveOrFewerTasksHideNothingAndNeedNoViewAll(): void
    {
        $x = $this->render('upcoming-tasks', ['tasks' => $this->tasks(5)]);

        $this->assertNotContains(true, $this->hidden($x, 'data-task-row'));
        $this->assertSame(0, $x->query('//a[@data-view-all]')->length);
    }

    public function testCoursesCapAtFiveAndKeepTheirViewAll(): void
    {
        $courses = array_map(static fn ($i) => ['offering_id' => $i, 'slug' => 'c', 'course_code' => 'IT1', 'section_code' => 'A', 'course_title' => "C{$i}", 'progress' => 0], range(1, 7));
        $x = $this->render('my-courses', ['courses' => $courses]);

        $this->assertSame([false, false, false, false, false, true, true], $this->hidden($x, 'data-course-row'));
        $this->assertSame(1, $x->query('//a[normalize-space()="View all"][@href="' . site_url('student/courses') . '"]')->length);
    }
}
