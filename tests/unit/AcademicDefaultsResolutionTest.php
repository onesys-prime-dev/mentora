<?php

declare(strict_types=1);

use App\Services\Institutions\AcademicDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Which value is in force: the institution's own, else the platform's.
 * Pure: the rows are handed in.
 *
 * @internal
 */
final class AcademicDefaultsResolutionTest extends TestCase
{
    public function testWithNothingSetEverythingIsThePlatformsValue(): void
    {
        $r = AcademicDefaults::resolve(null, ['passing_percentage' => '70.00', 'grade_scale_id' => '3']);

        $this->assertSame(70.0, $r['passing']);
        $this->assertSame(3, $r['scaleId']);
        $this->assertSame(14, $r['inactiveDays']);
        $this->assertSame(50, $r['behindBelow']);
        $this->assertSame(7, $r['overdueGradingDays']);

        foreach (['passingFromInstitution', 'scaleFromInstitution', 'inactiveFromInstitution', 'behindFromInstitution', 'overdueFromInstitution'] as $flag) {
            $this->assertFalse($r[$flag], $flag);
        }
    }

    public function testTheInstitutionsValuesWinOverThePlatforms(): void
    {
        $r = AcademicDefaults::resolve(
            ['passing_percentage' => '60.00', 'grade_scale_id' => '9', 'inactive_days' => '21', 'behind_below_percent' => '40', 'overdue_grading_days' => '3'],
            ['passing_percentage' => '75.00', 'grade_scale_id' => '1'],
        );

        $this->assertSame(60.0, $r['passing']);
        $this->assertSame(9, $r['scaleId']);
        $this->assertSame(21, $r['inactiveDays']);
        $this->assertSame(40, $r['behindBelow']);
        $this->assertSame(3, $r['overdueGradingDays']);
        $this->assertTrue($r['passingFromInstitution']);
        $this->assertTrue($r['overdueFromInstitution']);
    }

    public function testEachValueFallsBackOnItsOwn(): void
    {
        $r = AcademicDefaults::resolve(['inactive_days' => '30', 'passing_percentage' => null], ['passing_percentage' => '80', 'grade_scale_id' => null]);

        $this->assertSame(30, $r['inactiveDays']);
        $this->assertTrue($r['inactiveFromInstitution']);
        $this->assertSame(80.0, $r['passing']);
        $this->assertFalse($r['passingFromInstitution']);
        $this->assertNull($r['scaleId']);
        $this->assertSame(50, $r['behindBelow']);
    }

    public function testWithNoPlatformTemplateEitherTheBuiltInValuesApply(): void
    {
        $r = AcademicDefaults::resolve(null, null);

        $this->assertSame(AcademicDefaults::PLATFORM_PASSING, $r['passing']);
        $this->assertNull($r['scaleId']);
        $this->assertSame(AcademicDefaults::PLATFORM_INACTIVE_DAYS, $r['inactiveDays']);
    }
}
