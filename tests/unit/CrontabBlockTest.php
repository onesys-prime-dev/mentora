<?php

declare(strict_types=1);

use App\Services\Monitoring\CrontabBlock;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The deploy installs the scheduled jobs between two marker lines, and only there (audit decisions D5, 6.4).
 *
 * @internal
 */
final class CrontabBlockTest extends CIUnitTestCase
{
    private function block(): string
    {
        return CrontabBlock::render(CrontabBlock::template(), '/home/site/htdocs/app', 'php8.5');
    }

    public function testTheBlockHasTheSevenJobsWithThisSiteAndPhp(): void
    {
        $block = $this->block();

        $this->assertStringStartsWith(CrontabBlock::BEGIN . "\n", $block);
        $this->assertStringEndsWith(CrontabBlock::END . "\n", $block);

        foreach (['backup:run', 'subscriptions:sync', 'institutions:purge-expired', 'parents:notify', 'sis:sync', 'sis:grades', 'exports:run'] as $command) {
            $this->assertStringContainsString("cd '/home/site/htdocs/app' && php8.5 spark {$command}", $block);
        }

        $this->assertStringNotContainsString('{site}', $block);
    }

    public function testMergingAddsTheBlockToACrontabWithout(): void
    {
        $merged = CrontabBlock::merge("MAILTO=ops@example.com\n0 4 * * * /usr/bin/other-site-job\n", $this->block());

        $this->assertStringStartsWith("MAILTO=ops@example.com\n0 4 * * * /usr/bin/other-site-job\n", $merged);
        $this->assertStringContainsString($this->block(), $merged);
    }

    public function testMergingReplacesOnlyTheBlock(): void
    {
        $old      = CrontabBlock::BEGIN . "\n*/5 * * * * cd /x && php spark backup:run\n" . CrontabBlock::END . "\n";
        $existing = "0 1 * * * /usr/bin/before\n" . $old . "0 5 * * * /usr/bin/after\n";

        $merged = CrontabBlock::merge($existing, $this->block());

        $this->assertSame("0 1 * * * /usr/bin/before\n" . $this->block() . "0 5 * * * /usr/bin/after\n", $merged);
    }

    public function testMergingTwiceChangesNothing(): void
    {
        $once = CrontabBlock::merge("0 1 * * * /usr/bin/before\n", $this->block());

        $this->assertSame($once, CrontabBlock::merge($once, $this->block()));
    }

    public function testABlockWithoutItsEndLineIsRefusedRatherThanGuessed(): void
    {
        // Merging would add a second block, and the next deploy would delete what lies between the two.
        $broken = "0 1 * * * /usr/bin/before\n" . CrontabBlock::BEGIN . "\n*/5 * * * * cd /x && php spark backup:run\n0 5 * * * /usr/bin/after\n";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has "# BEGIN mentora jobs" without its "# END mentora jobs" line');

        CrontabBlock::merge($broken, $this->block());
    }

    public function testMissingNamesTheAbsentCommands(): void
    {
        $this->assertSame([], CrontabBlock::missing($this->block()));
        $this->assertSame(['exports:run'], CrontabBlock::missing(str_replace('spark exports:run', 'spark something-else', $this->block())));
    }
}
