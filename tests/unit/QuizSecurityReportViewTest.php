<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * What the teacher sees with an attempt on the grading page: nothing when nothing was recorded, otherwise a short
 * count and the events in order, worded as what the page saw (that the student left, never where they went).
 *
 * @internal
 */
final class QuizSecurityReportViewTest extends CIUnitTestCase
{
    private const VIEW = 'pages/courses/features/assessments/partials/security-report';

    /** @param list<array<string, mixed>> $events */
    private function render(array $events): string
    {
        $summary = ['left_page' => 0, 'away_seconds' => 0, 'left_fullscreen' => 0, 'blocked_actions' => 0];

        foreach ($events as $e) {
            if ($e['kind'] === 'left_page') {
                $summary['left_page']++;
                $summary['away_seconds'] += (int) $e['duration_seconds'];
            } elseif ($e['kind'] === 'left_fullscreen') {
                $summary['left_fullscreen']++;
            } else {
                $summary['blocked_actions']++;
            }
        }

        return preg_replace('/<!--.*?-->/s', '', view(self::VIEW, ['security' => ['events' => $events, 'summary' => $summary]], ['saveData' => false]));
    }

    public function testNothingIsShownWhenNothingWasRecorded(): void
    {
        $this->assertSame('', trim($this->render([])));
    }

    public function testNothingIsShownWithoutAReport(): void
    {
        $this->assertSame('', trim(preg_replace('/<!--.*?-->/s', '', view(self::VIEW, [], ['saveData' => false]))));
    }

    public function testItCountsTimesLeftAndTimeAway(): void
    {
        $html = $this->render([
            ['kind' => 'left_page', 'detail' => null, 'duration_seconds' => 30, 'occurred_at' => '2026-11-05 10:00:00'],
            ['kind' => 'left_page', 'detail' => null, 'duration_seconds' => 95, 'occurred_at' => '2026-11-05 10:05:00'],
        ]);

        $this->assertStringContainsString('data-security-report', $html);
        $this->assertStringContainsString('Left the page 2 times', $html);
        $this->assertStringContainsString('2 min 5 s away', $html);
    }

    public function testItListsEachEventInOrderWithoutClaimingWhereTheStudentWent(): void
    {
        $html = $this->render([
            ['kind' => 'left_page', 'detail' => null, 'duration_seconds' => 12, 'occurred_at' => '2026-11-05 10:00:00'],
            ['kind' => 'blocked_action', 'detail' => 'paste', 'duration_seconds' => null, 'occurred_at' => '2026-11-05 10:01:00'],
            ['kind' => 'left_fullscreen', 'detail' => null, 'duration_seconds' => null, 'occurred_at' => '2026-11-05 10:02:00'],
        ]);
        $this->assertSame(3, substr_count($html, 'data-security-event'));
        $html = substr($html, strpos($html, '<li data-security-event>'));
        $this->assertSame(3, substr_count($html, 'data-security-event'));
        $this->assertLessThan(strpos($html, 'Tried to paste'), strpos($html, 'Left the page for 12 s'));
        $this->assertLessThan(strpos($html, 'Left fullscreen'), strpos($html, 'Tried to paste'));
        $this->assertStringNotContainsString('went to', $html);
    }

    public function testADetailFromTheDatabaseIsEscaped(): void
    {
        $html = $this->render([
            ['kind' => 'blocked_action', 'detail' => '<script>x</script>', 'duration_seconds' => null, 'occurred_at' => '2026-11-05 10:01:00'],
        ]);

        $this->assertStringNotContainsString('<script>x', $html);
    }
}
