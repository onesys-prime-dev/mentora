<?php

declare(strict_types=1);

use App\Services\Courses\Grades\OverrideRequestChain as Chain;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Who a grade-override request waits for next, for every combination of who
 * asked, who chairs the sub-department and who is dean. The rule: nobody acts
 * on their own request, and a missing level is skipped upward.
 *
 * @internal
 */
final class OverrideRequestChainTest extends CIUnitTestCase
{
    private const INSTRUCTOR = 5;
    private const CHAIR      = 10;
    private const DEAN       = 20;

    public function testAnInstructorsRequestWaitsForTheChairpersonFirst(): void
    {
        $this->assertSame(Chain::AWAITING_CHAIR, Chain::first(self::INSTRUCTOR, 'instructor', [self::CHAIR], self::DEAN));
        $this->assertSame(Chain::AWAITING_CHAIR, Chain::first(self::INSTRUCTOR, 'instructor', [self::CHAIR, 11], self::DEAN), 'several chairpersons');
        $this->assertSame(Chain::AWAITING_CHAIR, Chain::first(self::INSTRUCTOR, 'instructor', [self::CHAIR], null), 'the chairperson step does not depend on a dean');
    }

    public function testWithNoChairpersonTheRequestSkipsToTheDean(): void
    {
        $this->assertSame(Chain::AWAITING_DEAN, Chain::first(self::INSTRUCTOR, 'instructor', [], self::DEAN));
    }

    public function testAnInstructorWhoIsTheOnlyChairpersonCannotEndorseTheirOwnRequest(): void
    {
        $this->assertSame(Chain::AWAITING_DEAN, Chain::first(self::INSTRUCTOR, 'instructor', [self::INSTRUCTOR], self::DEAN));
        $this->assertSame(Chain::AWAITING_CHAIR, Chain::first(self::INSTRUCTOR, 'instructor', [self::INSTRUCTOR, self::CHAIR], self::DEAN), 'another chairperson can');
    }

    public function testAChairpersonsOwnRequestGoesStraightToTheDean(): void
    {
        $this->assertSame(Chain::AWAITING_DEAN, Chain::first(self::CHAIR, 'chairperson', [self::CHAIR], self::DEAN));
        $this->assertSame(Chain::AWAITING_DEAN, Chain::first(self::CHAIR, 'chairperson', [self::CHAIR, 11], self::DEAN), 'even when a colleague chairs too');
    }

    public function testWithNoDeanTheInstitutionsAdministratorDecides(): void
    {
        $this->assertSame(Chain::AWAITING_INSTITUTION, Chain::first(self::INSTRUCTOR, 'instructor', [], null));
        $this->assertSame(Chain::AWAITING_INSTITUTION, Chain::first(self::CHAIR, 'chairperson', [self::CHAIR], null));
        $this->assertSame(Chain::AWAITING_INSTITUTION, Chain::afterChair(self::INSTRUCTOR, null));
    }

    public function testADeanDoesNotDecideTheirOwnRequest(): void
    {
        // A dean who also teaches asks: the chairperson endorses, but the dean step is the administrator's.
        $this->assertSame(Chain::AWAITING_CHAIR, Chain::first(self::DEAN, 'instructor', [self::CHAIR], self::DEAN));
        $this->assertSame(Chain::AWAITING_INSTITUTION, Chain::afterChair(self::DEAN, self::DEAN));
        $this->assertSame(Chain::AWAITING_INSTITUTION, Chain::first(self::DEAN, 'instructor', [], self::DEAN));
    }

    public function testAfterTheChairpersonItGoesToTheDean(): void
    {
        $this->assertSame(Chain::AWAITING_DEAN, Chain::afterChair(self::INSTRUCTOR, self::DEAN));
    }

    public function testOnlyTheOpenStatusesAreOpen(): void
    {
        foreach ([Chain::AWAITING_CHAIR, Chain::AWAITING_DEAN, Chain::AWAITING_INSTITUTION, Chain::RETURNED] as $status) {
            $this->assertTrue(Chain::isOpen($status), $status);
        }

        foreach ([Chain::APPROVED, Chain::REJECTED, Chain::WITHDRAWN, 'nonsense'] as $status) {
            $this->assertFalse(Chain::isOpen($status), $status);
        }
    }

    public function testEveryOpenStatusHasWordsForWhoIsNext(): void
    {
        $this->assertSame('the chairperson', Chain::waitingFor(Chain::AWAITING_CHAIR));
        $this->assertSame('the dean', Chain::waitingFor(Chain::AWAITING_DEAN));
        $this->assertSame("the institution's administrator", Chain::waitingFor(Chain::AWAITING_INSTITUTION));
        $this->assertStringContainsString('sent back', Chain::waitingFor(Chain::RETURNED));
        $this->assertSame('', Chain::waitingFor(Chain::APPROVED));
    }
}
