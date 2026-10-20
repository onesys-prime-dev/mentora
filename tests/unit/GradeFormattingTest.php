<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeStatistics;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * How grades are written on screen (one rule everywhere) and how a class is
 * summarized (one meaning of "average" and "passing").
 *
 * @internal
 */
final class GradeFormattingTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('grade');
    }

    public function testAPercentageHasUpToTwoDecimalsAndNoTrailingZeros(): void
    {
        $this->assertSame('85%', grade_percent(85));
        $this->assertSame('85%', grade_percent('85.00'));
        $this->assertSame('85.5%', grade_percent(85.5));
        $this->assertSame('85.25%', grade_percent(85.25));
        $this->assertSame('85.26%', grade_percent(85.256));
        $this->assertSame('0%', grade_percent(0));
    }

    public function testNoGradeIsADashOrWhatIsAsked(): void
    {
        $this->assertSame('—', grade_percent(null));
        $this->assertSame('—', grade_percent(''));
        $this->assertSame('', grade_percent(null, ''));
        $this->assertSame('—', grade_number(null));
        $this->assertSame('', grade_number(null, ''));
    }

    public function testANumberIsWrittenTheSameWay(): void
    {
        $this->assertSame('12.5', grade_number(12.5));
        $this->assertSame('100', grade_number(100.0));
        $this->assertSame('0', grade_number(0));
    }

    public function testEveryStandingHasALookWithAToneAndNeverOnlyColour(): void
    {
        foreach (['Passed', 'On track', 'Failed', 'At risk', 'INC'] as $tag) {
            $look = grade_tag_look($tag);

            $this->assertContains($look['tone'], ['success', 'warning', 'danger'], $tag);
            $this->assertNotNull($look['icon'], $tag . ' carries an icon as well as a colour');
        }

        $this->assertSame('neutral', grade_tag_look('Withdrawn')['tone']);
        $this->assertSame('neutral', grade_tag_look('Incomplete')['tone']);
        $this->assertSame('neutral', grade_tag_look('anything else')['tone']);
    }

    public function testStatisticsCountOnlyStudentsWithAGrade(): void
    {
        $stats = GradeStatistics::of([90, 70, null, 80, ''], 75.0);

        $this->assertSame(5, $stats['students']);
        $this->assertSame(3, $stats['graded']);
        $this->assertSame(80.0, $stats['mean']);
        $this->assertSame(80.0, $stats['median']);
        $this->assertSame(90.0, $stats['high']);
        $this->assertSame(70.0, $stats['low']);
        $this->assertSame(2, $stats['at_or_above_passing']);
        $this->assertSame(75.0, $stats['passing_mark']);
    }

    public function testTheMedianOfAnEvenNumberIsTheMiddlePair(): void
    {
        $this->assertSame(75.0, GradeStatistics::of([60, 70, 80, 90], 60.0)['median']);
    }

    public function testAnEmptyClassHasNoFiguresInsteadOfZeros(): void
    {
        $stats = GradeStatistics::of([null, null], 75.0);

        $this->assertSame(0, $stats['graded']);
        $this->assertNull($stats['mean']);
        $this->assertNull($stats['median']);
        $this->assertNull($stats['high']);
        $this->assertNull($stats['low']);
        $this->assertSame(0, $stats['at_or_above_passing']);

        $this->assertSame(0, GradeStatistics::of([], 75.0)['students']);
    }

    public function testAGradeExactlyAtThePassingMarkPasses(): void
    {
        $this->assertSame(1, GradeStatistics::of([75.0, 74.99], 75.0)['at_or_above_passing']);
    }
}
