<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * What needs the dean's attention, as a list, from plain numbers (DeanDashboardService collects them).
 * Each rule is one item, shown only when its count is above zero, and each links to the screen where it is
 * dealt with. They are in priority order: first what only the dean can decide (overrides, sign-off), then
 * the follow-ups they sent, then what is slipping in the department, by sub-department.
 *
 * Facts:
 *   overrides             grade-override requests waiting for the dean
 *   signoff_ready         sections whose grades are ready to be signed off
 *   signoff_blocked       sections whose sign-off is held up
 *   followups_overdue     follow-ups the dean sent that are past their due day
 *   overdue_grading       list of {id, name, count}: work waiting too long to be graded, per sub-department
 *   inactive_courses      list of {id, name, count}: courses with no recent activity, per sub-department
 *   at_risk               students at risk across the department
 */
final class DeanAttentionRules
{
    /**
     * @param array{overrides?: int, signoff_ready?: int, signoff_blocked?: int, followups_overdue?: int, overdue_grading?: list<array{id: int, name: string, count: int}>, inactive_courses?: list<array{id: int, name: string, count: int}>, at_risk?: int} $facts
     *
     * @return list<array{key: string, tone: string, count: int, title: string, detail: string, url: string}>
     */
    public static function items(array $facts): array
    {
        helper('url');

        $items = [];

        $overrides = (int) ($facts['overrides'] ?? 0);

        if ($overrides > 0) {
            $items[] = self::item('overrides', 'warning', $overrides, self::count($overrides, 'grade override') . ' to decide', 'Requests to change a final grade are waiting for you.', site_url('dean/approvals'));
        }

        $ready = (int) ($facts['signoff_ready'] ?? 0);

        if ($ready > 0) {
            $items[] = self::item('signoff_ready', 'info', $ready, self::count($ready, 'section') . ' ready to sign off', 'Their grades are final and nothing is open.', site_url('dean/signoff'));
        }

        $blocked = (int) ($facts['signoff_blocked'] ?? 0);

        if ($blocked > 0) {
            $items[] = self::item('signoff_blocked', 'warning', $blocked, self::count($blocked, 'section') . ' holding up sign-off', 'Grades not finalized yet, or a request still open.', site_url('dean/signoff'));
        }

        $followups = (int) ($facts['followups_overdue'] ?? 0);

        if ($followups > 0) {
            $items[] = self::item('followups_overdue', 'danger', $followups, self::count($followups, 'follow-up') . ($followups === 1 ? ' is' : ' are') . ' overdue', 'Past the day you asked for an answer.', site_url('dean/follow-ups') . '?tab=overdue');
        }

        foreach (self::largestFirst($facts['overdue_grading'] ?? []) as $row) {
            $items[] = self::item('overdue_grading', 'warning', $row['count'], self::count($row['count'], 'piece') . ' of work overdue for grading', $row['name'], site_url('dean/monitor-department') . '?' . http_build_query(['sub_department' => $row['id']]));
        }

        foreach (self::largestFirst($facts['inactive_courses'] ?? []) as $row) {
            $items[] = self::item('inactive_courses', 'neutral', $row['count'], self::count($row['count'], 'quiet course'), $row['name'] . ': no recent activity.', site_url('dean/courses') . '?' . http_build_query(['sub_department' => $row['id']]));
        }

        $risk = (int) ($facts['at_risk'] ?? 0);

        if ($risk > 0) {
            $items[] = self::item('at_risk', 'danger', $risk, self::count($risk, 'student') . ' at risk', 'Behind or silent across the department.', site_url('dean/monitor-department') . '#risk-heading');
        }

        return $items;
    }

    /** "1 section", "2 sections". */
    public static function count(int $n, string $noun): string
    {
        return $n . ' ' . $noun . ($n === 1 ? '' : 's');
    }

    /**
     * Sub-departments with something to report, the most first, ties by name; none with a count of zero.
     *
     * @param list<array{id: int, name: string, count: int}> $rows
     *
     * @return list<array{id: int, name: string, count: int}>
     */
    private static function largestFirst(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['count'] > 0));

        usort($rows, static fn (array $a, array $b): int => [$b['count'], strtolower($a['name'])] <=> [$a['count'], strtolower($b['name'])]);

        return $rows;
    }

    /** @return array{key: string, tone: string, count: int, title: string, detail: string, url: string} */
    private static function item(string $key, string $tone, int $count, string $title, string $detail, string $url): array
    {
        return ['key' => $key, 'tone' => $tone, 'count' => $count, 'title' => $title, 'detail' => $detail, 'url' => $url];
    }
}
