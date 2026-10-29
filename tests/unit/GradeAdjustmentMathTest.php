<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeAdjustmentService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The arithmetic of grading on a curve: each method, the numbers it keeps,
 * and the limits (never below 0 or above the maximum).
 *
 * @internal
 */
final class GradeAdjustmentMathTest extends CIUnitTestCase
{
    public function testAddingPointsStaysWithinTheMaximumAndZero(): void
    {
        $params = ['points' => 5.0];

        $this->assertSame(17.0, GradeAdjustmentService::adjust('add_points', $params, 12.0, 20.0));
        $this->assertSame(20.0, GradeAdjustmentService::adjust('add_points', $params, 18.0, 20.0), 'capped at full marks');
        $this->assertSame(0.0, GradeAdjustmentService::adjust('add_points', ['points' => -5.0], 3.0, 20.0), 'never below zero');
    }

    public function testScalingMakesTheHighestScoreFullMarks(): void
    {
        $frozen = GradeAdjustmentService::freeze('scale_to_max', [], [10.0, 16.0, 12.0], 20.0);

        $this->assertTrue($frozen['success']);
        $this->assertSame(20.0, GradeAdjustmentService::adjust('scale_to_max', $frozen['params'], 16.0, 20.0));
        $this->assertSame(12.5, GradeAdjustmentService::adjust('scale_to_max', $frozen['params'], 10.0, 20.0));
    }

    public function testScalingIsRefusedWhenItWouldChangeNothingOrHasNothingToScale(): void
    {
        $this->assertFalse(GradeAdjustmentService::freeze('scale_to_max', [], [20.0, 12.0], 20.0)['success'], 'the top score is already full marks');
        $this->assertFalse(GradeAdjustmentService::freeze('scale_to_max', [], [], 20.0)['success']);
        $this->assertFalse(GradeAdjustmentService::freeze('scale_to_max', [], [0.0, 0.0], 20.0)['success']);
    }

    public function testTheLinearMethodStretchesTheClassRangeOntoANewRange(): void
    {
        $frozen = GradeAdjustmentService::freeze('linear', ['new_low' => 60, 'new_high' => 100], [40.0, 70.0, 90.0], 100.0);

        $this->assertTrue($frozen['success']);
        $this->assertSame(60.0, GradeAdjustmentService::adjust('linear', $frozen['params'], 40.0, 100.0), 'the lowest becomes the new low');
        $this->assertSame(100.0, GradeAdjustmentService::adjust('linear', $frozen['params'], 90.0, 100.0), 'the highest becomes the new high');
        $this->assertSame(80.0, GradeAdjustmentService::adjust('linear', $frozen['params'], 65.0, 100.0), 'in between, in proportion');
    }

    public function testTheLinearMethodNeedsASensibleRangeAndAClassThatVaries(): void
    {
        $this->assertFalse(GradeAdjustmentService::freeze('linear', ['new_low' => 90, 'new_high' => 60], [40.0, 90.0], 100.0)['success']);
        $this->assertFalse(GradeAdjustmentService::freeze('linear', ['new_low' => 60, 'new_high' => 120], [40.0, 90.0], 100.0)['success']);
        $this->assertFalse(GradeAdjustmentService::freeze('linear', ['new_low' => 'x', 'new_high' => 100], [40.0, 90.0], 100.0)['success']);
        $this->assertFalse(GradeAdjustmentService::freeze('linear', ['new_low' => 60, 'new_high' => 100], [70.0, 70.0], 100.0)['success'], 'everyone scored the same');
        $this->assertFalse(GradeAdjustmentService::freeze('linear', ['new_low' => 60, 'new_high' => 100], [70.0], 100.0)['success']);
    }

    public function testSettingTheMeanMovesEveryoneByTheSameAmount(): void
    {
        $frozen = GradeAdjustmentService::freeze('set_mean', ['target_mean' => 75], [50.0, 60.0, 70.0], 100.0);

        $this->assertTrue($frozen['success']);
        $this->assertEqualsWithDelta(15.0, $frozen['params']['shift'], 0.0001, 'the class mean is 60, so everyone gains 15');
        $this->assertSame(65.0, GradeAdjustmentService::adjust('set_mean', $frozen['params'], 50.0, 100.0));
        $this->assertSame(100.0, GradeAdjustmentService::adjust('set_mean', ['shift' => 40.0], 90.0, 100.0), 'capped');
    }

    public function testBadInputIsRefusedWithAReason(): void
    {
        foreach ([
            ['add_points', ['points' => 0]],
            ['add_points', ['points' => 'lots']],
            ['add_points', ['points' => 500]],
            ['set_mean', ['target_mean' => 150]],
            ['set_mean', ['target_mean' => -1]],
            ['nonsense', []],
        ] as [$method, $input]) {

            $result = GradeAdjustmentService::freeze($method, $input, [50.0, 60.0], 100.0);

            $this->assertFalse($result['success'], $method . ' ' . json_encode($input));
            $this->assertNotSame('', $result['message']);

        }
    }

    public function testEachMethodDescribesItselfInPlainWords(): void
    {
        $add = GradeAdjustmentService::freeze('add_points', ['points' => 5], [10.0], 20.0);
        $take = GradeAdjustmentService::freeze('add_points', ['points' => -2], [10.0], 20.0);

        $this->assertSame('Add 5 points for everyone', $add['label']);
        $this->assertSame('Take off 2 points for everyone', $take['label']);
        $this->assertStringContainsString('percentage points', GradeAdjustmentService::freeze('add_points', ['points' => 3], [70.0], 100.0, true)['label']);
    }

    public function testAnUnknownMethodChangesNothing(): void
    {
        $this->assertSame(12.0, GradeAdjustmentService::adjust('mystery', ['points' => 5.0], 12.0, 20.0));
    }
}
