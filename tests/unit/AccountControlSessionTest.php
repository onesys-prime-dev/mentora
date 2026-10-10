<?php

declare(strict_types=1);

use App\Services\Users\AccountControl;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Whether a session has been ended by a sign-out made after it began: the
 * rule, at its boundaries. Pure logic, no database.
 *
 * @internal
 */
final class AccountControlSessionTest extends CIUnitTestCase
{
    private const SIGN_OUT = '2026-10-09 12:00:00';

    public function testNothingEndedMeansNothingIsEnded(): void
    {
        $this->assertFalse(AccountControl::sessionEnded(null, strtotime('2026-10-01 00:00:00')));
        $this->assertFalse(AccountControl::sessionEnded(null, null));
    }

    public function testASessionThatBeganBeforeTheSignOutIsEnded(): void
    {
        $this->assertTrue(AccountControl::sessionEnded(self::SIGN_OUT, strtotime('2026-10-09 11:59:59')));
        $this->assertTrue(AccountControl::sessionEnded(self::SIGN_OUT, strtotime('2026-09-01 00:00:00')));
    }

    public function testASessionThatBeganAfterTheSignOutLivesOn(): void
    {
        $this->assertFalse(AccountControl::sessionEnded(self::SIGN_OUT, strtotime('2026-10-09 12:00:01')));
        $this->assertFalse(AccountControl::sessionEnded(self::SIGN_OUT, strtotime('2026-10-10 00:00:00')));
    }

    public function testASessionThatBeganInTheSameSecondIsEnded(): void
    {
        $this->assertTrue(AccountControl::sessionEnded(self::SIGN_OUT, strtotime(self::SIGN_OUT)), 'the sign-out is taken to have come after');
    }

    public function testASessionWithNoRecordedStartCannotBeShownToBeNewerSoItIsEnded(): void
    {
        $this->assertTrue(AccountControl::sessionEnded(self::SIGN_OUT, null));
    }
}
