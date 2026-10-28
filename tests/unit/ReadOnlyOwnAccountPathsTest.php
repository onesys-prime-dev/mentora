<?php

declare(strict_types=1);

use App\Filters\ReadOnlyFilter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The own-account paths a read-only institution still lets through (decision 8.2) are exactly the reminder and
 * parent routes, so a route added under them later is not opened by accident.
 *
 * @internal
 */
final class ReadOnlyOwnAccountPathsTest extends CIUnitTestCase
{
    private function ownAccount(string $path): bool
    {
        foreach (ReadOnlyFilter::OWN_ACCOUNT as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<string, array{string}> */
    public static function provideOwnAccountPaths(): iterable
    {
        foreach (['student', 'instructor', 'chairperson'] as $role) {
            yield "{$role} add"    => ["{$role}/api/calendar/reminders"];
            yield "{$role} done"   => ["{$role}/api/calendar/reminders/5/done"];
            yield "{$role} delete" => ["{$role}/api/calendar/reminders/5"];
        }

        yield 'parent emails'   => ['parent/profile/notifications'];
        yield 'parent password' => ['parent/profile/password-link'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideOwnAccountPaths')]
    public function testTheRoutesAreOwnAccount(string $path): void
    {
        $this->assertTrue($this->ownAccount($path));
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherPaths(): iterable
    {
        yield 'nested under a reminder' => ['student/api/calendar/reminders/5/anything'];
        yield 'nested under done'       => ['student/api/calendar/reminders/5/done/more'];
        yield 'a word for an id'        => ['student/api/calendar/reminders/all'];
        yield 'a dean'                  => ['dean/api/calendar/reminders'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideOtherPaths')]
    public function testAnythingElseIsNot(string $path): void
    {
        $this->assertFalse($this->ownAccount($path));
    }
}
