<?php

declare(strict_types=1);

use App\Services\Courses\DeanAttentionRules;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * What the dean's home page lists as needing attention, from plain numbers: one rule per kind of item, shown
 * only above zero, in priority order, each opening the screen where it is dealt with.
 *
 * @internal
 */
final class DeanAttentionRulesTest extends CIUnitTestCase
{
    /** @return list<string> */
    private function keys(array $facts): array
    {
        return array_column(DeanAttentionRules::items($facts), 'key');
    }

    /** @return array<string, mixed> */
    private function only(array $facts, string $key): array
    {
        $found = array_values(array_filter(DeanAttentionRules::items($facts), static fn (array $i): bool => $i['key'] === $key));

        return $found[0];
    }

    public function testNothingToReportIsAnEmptyList(): void
    {
        $this->assertSame([], DeanAttentionRules::items([]));
        $this->assertSame([], DeanAttentionRules::items(['overrides' => 0, 'signoff_ready' => 0, 'signoff_blocked' => 0, 'followups_overdue' => 0, 'overdue_grading' => [], 'inactive_courses' => [], 'at_risk' => 0]));
    }

    public function testOverridesWaitingAreOneItemLinkingToTheApprovals(): void
    {
        $item = $this->only(['overrides' => 4], 'overrides');

        $this->assertSame('4 grade overrides to decide', $item['title']);
        $this->assertSame(4, $item['count']);
        $this->assertStringEndsWith('dean/approvals', $item['url']);
        $this->assertSame('1 grade override to decide', $this->only(['overrides' => 1], 'overrides')['title']);
    }

    public function testSectionsReadyAndSectionsHoldingUpSignOffAreTwoItemsToTheSignOffPage(): void
    {
        $facts = ['signoff_ready' => 6, 'signoff_blocked' => 2];

        $this->assertSame(['signoff_ready', 'signoff_blocked'], $this->keys($facts));
        $this->assertSame('6 sections ready to sign off', $this->only($facts, 'signoff_ready')['title']);
        $this->assertSame('2 sections holding up sign-off', $this->only($facts, 'signoff_blocked')['title']);
        $this->assertStringEndsWith('dean/signoff', $this->only($facts, 'signoff_ready')['url']);
        $this->assertSame(['signoff_blocked'], $this->keys(['signoff_blocked' => 1]), 'one without the other');
    }

    public function testOverdueFollowUpsLinkToTheOverdueTabAndAgreeInNumber(): void
    {
        $this->assertSame('2 follow-ups are overdue', $this->only(['followups_overdue' => 2], 'followups_overdue')['title']);
        $this->assertSame('1 follow-up is overdue', $this->only(['followups_overdue' => 1], 'followups_overdue')['title']);
        $this->assertStringEndsWith('dean/follow-ups?tab=overdue', $this->only(['followups_overdue' => 2], 'followups_overdue')['url']);
    }

    public function testOverdueGradingIsOneItemPerSubDepartmentMostFirstAndNeverForZero(): void
    {
        $items = DeanAttentionRules::items(['overdue_grading' => [
            ['id' => 1, 'name' => 'Computer Science', 'count' => 9],
            ['id' => 2, 'name' => 'Law', 'count' => 0],
            ['id' => 3, 'name' => 'Arts', 'count' => 12],
        ]]);

        $this->assertSame(['Arts', 'Computer Science'], array_column($items, 'detail'));
        $this->assertSame('12 pieces of work overdue for grading', $items[0]['title']);
        $this->assertStringEndsWith('dean/monitor-department?sub_department=3', $items[0]['url']);
        $this->assertSame('1 piece of work overdue for grading', DeanAttentionRules::items(['overdue_grading' => [['id' => 1, 'name' => 'A', 'count' => 1]]])[0]['title']);
    }

    public function testQuietCoursesAreOneItemPerSubDepartmentLinkingToItsCourses(): void
    {
        $items = DeanAttentionRules::items(['inactive_courses' => [
            ['id' => 5, 'name' => 'Beta', 'count' => 2],
            ['id' => 6, 'name' => 'Alpha', 'count' => 2],
        ]]);

        $this->assertSame(['Alpha: no recent activity.', 'Beta: no recent activity.'], array_column($items, 'detail'), 'ties go by name');
        $this->assertSame('2 quiet courses', $items[0]['title']);
        $this->assertStringEndsWith('dean/courses?sub_department=6', $items[0]['url']);
        $this->assertSame('1 quiet course', DeanAttentionRules::items(['inactive_courses' => [['id' => 1, 'name' => 'A', 'count' => 1]]])[0]['title']);
    }

    public function testStudentsAtRiskAreOneItemLinkingToTheListOnMonitorDepartment(): void
    {
        $item = $this->only(['at_risk' => 37], 'at_risk');

        $this->assertSame('37 students at risk', $item['title']);
        $this->assertStringEndsWith('dean/monitor-department#risk-heading', $item['url']);
        $this->assertSame('1 student at risk', $this->only(['at_risk' => 1], 'at_risk')['title']);
    }

    public function testTheItemsComeInPriorityOrder(): void
    {
        $facts = [
            'at_risk'           => 5,
            'inactive_courses'  => [['id' => 1, 'name' => 'A', 'count' => 1]],
            'overdue_grading'   => [['id' => 1, 'name' => 'A', 'count' => 3]],
            'followups_overdue' => 1,
            'signoff_blocked'   => 1,
            'signoff_ready'     => 1,
            'overrides'         => 1,
        ];

        $this->assertSame(['overrides', 'signoff_ready', 'signoff_blocked', 'followups_overdue', 'overdue_grading', 'inactive_courses', 'at_risk'], $this->keys($facts), 'what only the dean can decide first, then what is slipping');
    }

    public function testEveryItemHasEverythingTheCardNeeds(): void
    {
        foreach (DeanAttentionRules::items(['overrides' => 1, 'signoff_ready' => 1, 'signoff_blocked' => 1, 'followups_overdue' => 1, 'at_risk' => 1, 'overdue_grading' => [['id' => 1, 'name' => 'A', 'count' => 1]], 'inactive_courses' => [['id' => 1, 'name' => 'A', 'count' => 1]]]) as $item) {
            $this->assertSame(['key', 'tone', 'count', 'title', 'detail', 'url'], array_keys($item));
            $this->assertContains($item['tone'], ['info', 'warning', 'danger', 'neutral']);
            $this->assertNotSame('', $item['title']);
            $this->assertNotSame('', $item['detail']);
            $this->assertStringStartsWith('http', $item['url']);
        }
    }

    public function testTheCountWordsAgreeInNumber(): void
    {
        $this->assertSame('0 sections', DeanAttentionRules::count(0, 'section'));
        $this->assertSame('1 section', DeanAttentionRules::count(1, 'section'));
        $this->assertSame('2 sections', DeanAttentionRules::count(2, 'section'));
    }
}
