<?php

declare(strict_types=1);

use App\Services\Courses\FollowUpRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may ask whom in a follow-up request, and when an open one is overdue. Pure rules; the
 * day is an input.
 *
 * @internal
 */
final class FollowUpRulesTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function pairs(): iterable
    {
        yield 'dean asks chairperson' => ['dean', 'chairperson', true];
        yield 'dean asks instructor' => ['dean', 'instructor', true];
        yield 'chairperson asks instructor' => ['chairperson', 'instructor', true];
        yield 'chairperson does not ask the dean' => ['chairperson', 'dean', false];
        yield 'chairperson does not ask a chairperson' => ['chairperson', 'chairperson', false];
        yield 'dean does not ask a dean' => ['dean', 'dean', false];
        yield 'dean does not ask a student' => ['dean', 'student', false];
        yield 'instructor asks nobody' => ['instructor', 'instructor', false];
        yield 'student asks nobody' => ['student', 'instructor', false];
    }

    #[DataProvider('pairs')]
    public function testWhoMayAskWhom(string $sender, string $recipient, bool $allowed): void
    {
        $this->assertSame($allowed, FollowUpRules::mayAsk($sender, $recipient));
    }

    public function testADueDateIsTheDayAskedForAndWithoutOneItIsAWeekAfterSending(): void
    {
        $this->assertSame('2026-11-20', FollowUpRules::dueOn('2026-11-20', '2026-11-01 09:00:00'));
        $this->assertSame('2026-11-20', FollowUpRules::dueOn('2026-11-20 00:00:00', '2026-11-01 09:00:00'));
        $this->assertSame('2026-11-08', FollowUpRules::dueOn(null, '2026-11-01 09:00:00'));
        $this->assertSame('2026-11-08', FollowUpRules::dueOn('', '2026-11-01 23:59:59'));
    }

    public function testOverdueMeansOpenAndPastItsDueDay(): void
    {
        $this->assertFalse(FollowUpRules::isOverdue('open', '2026-11-20', '2026-11-01 09:00:00', '2026-11-20'), 'due today is not late yet');
        $this->assertTrue(FollowUpRules::isOverdue('open', '2026-11-20', '2026-11-01 09:00:00', '2026-11-21'));
        $this->assertFalse(FollowUpRules::isOverdue('open', null, '2026-11-01 09:00:00', '2026-11-08'));
        $this->assertTrue(FollowUpRules::isOverdue('open', null, '2026-11-01 09:00:00', '2026-11-09'));
        $this->assertFalse(FollowUpRules::isOverdue('done', '2020-01-01', '2020-01-01 00:00:00', '2026-11-21'), 'done is never overdue');
    }
}
