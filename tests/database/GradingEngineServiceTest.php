<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradingEngineService;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * How final grades are calculated: weighted categories (optionally grouped
 * into weighted periods), ungraded work left out rather than counted as zero,
 * the grade-scale band and the Passed/Failed/Incomplete remark, plus the
 * stored gradebook records and instructor overrides.
 *
 * The first half feeds hand-written data straight into compute(), so every
 * expected number can be checked with a calculator. The second half runs the
 * same maths through the database.
 *
 * @internal
 */
final class GradingEngineServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradingEngineService $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new GradingEngineService();
    }

    /*
    |--------------------------------------------------------------------------
    | Hand-built calculation data
    |--------------------------------------------------------------------------
    */

    private const STUDENT = 7;

    /**
     * Quizzes (40%): q1 /10, q2 /10. Exams (60%): e1 /100.
     *
     * @param array<int, float|null> $scores item id => raw score for STUDENT (null = not graded)
     */
    private function context(array $scores, array $overrides = []): array
    {
        $scoreRows = [];

        foreach ($scores as $itemId => $raw) {
            $scoreRows[$itemId][self::STUDENT] = ['raw_score' => $raw, 'is_missing' => 0];
        }

        return $overrides + [
            'offeringId' => 1,
            'scheme' => ['id' => 1, 'passing_percentage' => 75, 'uses_periods' => 0],
            'periods' => [],
            'categories' => [
                ['id' => 1, 'period_id' => null, 'name' => 'Quizzes', 'weight_percentage' => 40],
                ['id' => 2, 'period_id' => null, 'name' => 'Exams', 'weight_percentage' => 60],
            ],
            'items' => [
                ['id' => 1, 'category_id' => 1, 'max_points' => 10],
                ['id' => 2, 'category_id' => 1, 'max_points' => 10],
                ['id' => 3, 'category_id' => 2, 'max_points' => 100],
            ],
            'studentIds' => [self::STUDENT],
            'scores' => $scoreRows,
            'bands' => [
                ['id' => 1, 'name' => 'A', 'minimum_percentage' => 90],
                ['id' => 2, 'name' => 'B', 'minimum_percentage' => 80],
                ['id' => 3, 'name' => 'C', 'minimum_percentage' => 75],
                ['id' => 4, 'name' => 'F', 'minimum_percentage' => 0],
            ],
        ];
    }

    public function testTheFinalGradeIsTheWeightedAverageOfTheCategories(): void
    {
        // Quizzes 18/20 = 90%, exams 70/100 = 70%: (90 x 40 + 70 x 60) / 100 = 78.
        $result = $this->engine->compute($this->context([1 => 8.0, 2 => 10.0, 3 => 70.0]), self::STUDENT);

        $this->assertSame(78.0, $result['percentage']);
        $this->assertSame(90.0, $result['categories'][0]['percentage']);
        $this->assertSame(70.0, $result['categories'][1]['percentage']);
        $this->assertSame(88.0, $result['points_earned']);
        $this->assertSame(120.0, $result['points_possible']);
        $this->assertSame('C', $result['band']['name']);
        $this->assertSame('Passed', $result['remarks']);
    }

    public function testWorkNotYetGradedIsLeftOutNotCountedAsZero(): void
    {
        // q2 is ungraded: quizzes are 8/10 = 80%, not 8/20.
        $result = $this->engine->compute($this->context([1 => 8.0, 2 => null, 3 => 70.0]), self::STUDENT);

        $this->assertSame(80.0, $result['categories'][0]['percentage']);
        $this->assertSame(1, $result['categories'][0]['graded_count']);
        $this->assertSame(74.0, $result['percentage']); // (80 x 40 + 70 x 60) / 100
    }

    public function testAnItemMarkedMissingIsLeftOutEvenWithAScoreOnIt(): void
    {
        $context = $this->context([1 => 8.0, 3 => 70.0]);
        $context['scores'][2][self::STUDENT] = ['raw_score' => 0.0, 'is_missing' => 1];

        $result = $this->engine->compute($context, self::STUDENT);

        $this->assertSame(80.0, $result['categories'][0]['percentage']);
    }

    public function testACategoryWithNothingGradedDoesNotDragTheGradeDown(): void
    {
        // No exam is graded yet, so the quizzes alone decide the grade.
        $result = $this->engine->compute($this->context([1 => 9.0, 2 => 9.0]), self::STUDENT);

        $this->assertNull($result['categories'][1]['percentage']);
        $this->assertSame(90.0, $result['percentage']);
    }

    public function testWeightsThatDoNotAddUpTo100AreNormalised(): void
    {
        $context = $this->context([1 => 9.0, 2 => 9.0, 3 => 70.0]);
        $context['categories'][0]['weight_percentage'] = 30;
        $context['categories'][1]['weight_percentage'] = 30;

        $result = $this->engine->compute($context, self::STUDENT);

        $this->assertSame(80.0, $result['percentage']); // (90 x 30 + 70 x 30) / 60
    }

    public function testACategoryWithZeroWeightDoesNotCount(): void
    {
        $context = $this->context([1 => 10.0, 2 => 10.0, 3 => 50.0]);
        $context['categories'][0]['weight_percentage'] = 0;

        $result = $this->engine->compute($context, self::STUDENT);

        $this->assertSame(50.0, $result['percentage']);
    }

    public function testAnItemWithoutACategoryDoesNotCount(): void
    {
        $context = $this->context([1 => 10.0, 2 => 10.0, 3 => 100.0, 4 => 0.0]);
        $context['items'][] = ['id' => 4, 'category_id' => null, 'max_points' => 50];

        $result = $this->engine->compute($context, self::STUDENT);

        $this->assertSame(100.0, $result['percentage']);
        $this->assertSame(120.0, $result['points_possible'], 'the uncategorized item adds no points');
    }

    public function testNothingGradedMeansIncompleteWithNoBand(): void
    {
        $result = $this->engine->compute($this->context([]), self::STUDENT);

        $this->assertNull($result['percentage']);
        $this->assertNull($result['band']);
        $this->assertSame('Incomplete', $result['remarks']);
        $this->assertSame(0.0, $result['points_possible']);
    }

    public function testPercentagesAreRoundedToTwoDecimals(): void
    {
        $context = $this->context([1 => 2.0]);
        $context['categories'] = [['id' => 1, 'period_id' => null, 'name' => 'Only', 'weight_percentage' => 100]];
        $context['items'] = [['id' => 1, 'category_id' => 1, 'max_points' => 3]];

        $result = $this->engine->compute($context, self::STUDENT);

        $this->assertSame(66.67, $result['percentage']);
    }

    public function testPreviewScoresReplaceTheGradebookWithoutChangingIt(): void
    {
        $context = $this->context([1 => 8.0, 2 => 10.0, 3 => 70.0]);

        $preview = $this->engine->compute($context, self::STUDENT, [2 => 0.0]);
        $this->assertSame(40.0, $preview['categories'][0]['percentage']); // 8/20

        $withdrawn = $this->engine->compute($context, self::STUDENT, [2 => null]);
        $this->assertSame(80.0, $withdrawn['categories'][0]['percentage'], 'a null preview score means not graded');

        $unchanged = $this->engine->compute($context, self::STUDENT);
        $this->assertSame(90.0, $unchanged['categories'][0]['percentage']);
    }

    /*
    |--------------------------------------------------------------------------
    | Periods
    |--------------------------------------------------------------------------
    */

    /**
     * Midterm (50%): Quizzes 40 + Exams 60. Finals (50%): Project 100.
     */
    private function periodContext(array $scores): array
    {
        $context = $this->context($scores);

        $context['scheme']['uses_periods'] = 1;
        $context['periods'] = [
            ['id' => 10, 'name' => 'Midterm', 'weight_percentage' => 50],
            ['id' => 11, 'name' => 'Finals', 'weight_percentage' => 50],
        ];
        $context['categories'][0]['period_id'] = 10;
        $context['categories'][1]['period_id'] = 10;
        $context['categories'][] = ['id' => 3, 'period_id' => 11, 'name' => 'Project', 'weight_percentage' => 100];
        $context['items'][] = ['id' => 4, 'category_id' => 3, 'max_points' => 50];

        return $context;
    }

    public function testPeriodsAreAveragedFirstThenWeightedIntoTheFinalGrade(): void
    {
        // Midterm: quizzes 90, exams 70 -> 78. Finals: project 50/50 -> 100. (78 + 100) / 2 = 89.
        $result = $this->engine->compute($this->periodContext([1 => 8.0, 2 => 10.0, 3 => 70.0, 4 => 50.0]), self::STUDENT);

        $this->assertSame(78.0, $result['periods'][0]['percentage']);
        $this->assertSame(100.0, $result['periods'][1]['percentage']);
        $this->assertSame(89.0, $result['percentage']);
        $this->assertSame('B', $result['band']['name']);
    }

    public function testAPeriodWithNothingGradedIsLeftOut(): void
    {
        $result = $this->engine->compute($this->periodContext([1 => 8.0, 2 => 10.0, 3 => 70.0]), self::STUDENT);

        $this->assertNull($result['periods'][1]['percentage']);
        $this->assertSame(78.0, $result['percentage']);
    }

    public function testACategoryOutsideEveryPeriodDoesNotCountWhenPeriodsAreUsed(): void
    {
        $context = $this->periodContext([1 => 8.0, 2 => 10.0, 3 => 70.0, 4 => 50.0, 5 => 0.0]);
        $context['categories'][] = ['id' => 4, 'period_id' => null, 'name' => 'Stray', 'weight_percentage' => 100];
        $context['items'][] = ['id' => 5, 'category_id' => 4, 'max_points' => 100];

        $result = $this->engine->compute($context, self::STUDENT);

        $this->assertSame(89.0, $result['percentage']);
    }

    /*
    |--------------------------------------------------------------------------
    | Bands and remarks
    |--------------------------------------------------------------------------
    */

    #[DataProvider('provideBandBoundaries')]
    public function testBandsFollowTheScaleBoundaries(?float $percentage, ?string $expected): void
    {
        $bands = $this->context([])['bands'];

        $this->assertSame($expected, $this->engine->bandFor($bands, $percentage)['name'] ?? null);
    }

    /**
     * @return iterable<string, array{float|null, string|null}>
     */
    public static function provideBandBoundaries(): iterable
    {
        yield 'exactly 90 is an A' => [90.0, 'A'];
        yield 'just under 90 is a B' => [89.99, 'B'];
        yield 'exactly 80 is a B' => [80.0, 'B'];
        yield 'exactly the 75 pass mark is a C' => [75.0, 'C'];
        yield 'just under 75 falls to F' => [74.99, 'F'];
        yield 'zero is an F' => [0.0, 'F'];
        yield 'below every band still gets the lowest' => [-1.0, 'F'];
        yield '100 is an A' => [100.0, 'A'];
        yield 'no percentage means no band' => [null, null];
    }

    public function testNoBandsMeansNoBand(): void
    {
        $this->assertNull($this->engine->bandFor([], 85.0));
    }

    #[DataProvider('provideRemarks')]
    public function testRemarksFollowThePassingMark(?float $percentage, float $passing, string $expected): void
    {
        $this->assertSame($expected, $this->engine->remarksFor($percentage, $passing));
    }

    /**
     * @return iterable<string, array{float|null, float, string}>
     */
    public static function provideRemarks(): iterable
    {
        yield 'nothing graded' => [null, 75.0, 'Incomplete'];
        yield 'exactly the passing mark passes' => [75.0, 75.0, 'Passed'];
        yield 'just under fails' => [74.99, 75.0, 'Failed'];
        yield 'well above' => [100.0, 75.0, 'Passed'];
        yield 'a different passing mark' => [59.0, 60.0, 'Failed'];
    }

    /*
    |--------------------------------------------------------------------------
    | The same calculation through the database
    |--------------------------------------------------------------------------
    */

    /**
     * An offering with a scheme (Quizzes 40, Exams 60) and three students:
     * a strong one (78 = Passed), a weak one (50 = Failed) and one with no work.
     *
     * @return array{offering: int, scheme: int, strong: User, weak: User, none: User, quiz: int, exam: int}
     */
    private function gradebook(): array
    {
        $offering = CourseFactory::offering();
        $scheme = GradeFactory::scheme($offering);
        $quizzes = GradeFactory::category($scheme, 'Quizzes', 40, null, 1);
        $exams = GradeFactory::category($scheme, 'Exams', 60, null, 2);
        $quiz = GradeFactory::item($scheme, $quizzes, 'Quiz 1', 20);
        $exam = GradeFactory::item($scheme, $exams, 'Midterm', 100);

        $strong = CourseFactory::user('student');
        $weak = CourseFactory::user('student');
        $none = CourseFactory::user('student');

        foreach ([$strong, $weak, $none] as $student) {
            CourseFactory::enroll($offering, $student);
        }

        GradeFactory::score($offering, $strong, $quiz, 18);
        GradeFactory::score($offering, $strong, $exam, 70);
        GradeFactory::score($offering, $weak, $quiz, 10);
        GradeFactory::score($offering, $weak, $exam, 50);

        return compact('offering', 'scheme', 'strong', 'weak', 'none', 'quiz', 'exam');
    }

    private function record(int $offering, User $student): ?array
    {
        return db_connect()->table('gradebook_records')
            ->where('course_offering_id', $offering)->where('student_id', $student->id)
            ->get()->getRowArray();
    }

    public function testRecalculatingStoresEachEnrolledStudentsGrade(): void
    {
        $g = $this->gradebook();

        $this->assertSame(3, $this->engine->recalculateOffering($g['offering']));

        $strong = $this->record($g['offering'], $g['strong']);
        $this->assertSame(78.0, (float) $strong['calculated_percentage']);
        $this->assertSame('Passed', $strong['remarks']);

        $weak = $this->record($g['offering'], $g['weak']);
        $this->assertSame(50.0, (float) $weak['calculated_percentage']); // (50 x 40 + 50 x 60) / 100
        $this->assertSame('Failed', $weak['remarks']);

        $none = $this->record($g['offering'], $g['none']);
        $this->assertNull($none['calculated_percentage']);
        $this->assertSame('Incomplete', $none['remarks']);
        $this->assertNull($none['calculated_grade_scale_item_id']);
    }

    public function testRecalculatingClearsTheRecalculationFlag(): void
    {
        $g = $this->gradebook();

        $this->engine->recalculateOffering($g['offering']);

        $scheme = db_connect()->table('grading_schemes')->where('id', $g['scheme'])->get()->getRowArray();
        $this->assertSame(0, (int) $scheme['needs_recalculation']);
        $this->assertNotNull($scheme['last_recalculated_at']);
    }

    public function testRecalculatingTwiceKeepsOneRecordPerStudent(): void
    {
        $g = $this->gradebook();

        $this->engine->recalculateOffering($g['offering']);
        $this->engine->recalculateOffering($g['offering']);

        $this->assertSame(3, db_connect()->table('gradebook_records')->where('course_offering_id', $g['offering'])->countAllResults());
    }

    public function testStudentsWhoDroppedAreNotGraded(): void
    {
        $g = $this->gradebook();
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($g['offering'], $dropped, 'dropped');

        $this->assertSame(3, $this->engine->recalculateOffering($g['offering']));
        $this->assertNull($this->record($g['offering'], $dropped));
    }

    public function testAnOfferingWithoutASchemeHasNothingToCalculate(): void
    {
        $offering = CourseFactory::offering();
        CourseFactory::enroll($offering, CourseFactory::user('student'));

        $this->assertNull($this->engine->context($offering));
        $this->assertSame(0, $this->engine->recalculateOffering($offering));
    }

    public function testRecalculatingOneStudentLeavesTheSchemeFlagAlone(): void
    {
        $g = $this->gradebook();

        $this->engine->recalculateStudent($g['offering'], $g['strong']->id);

        $this->assertNotNull($this->record($g['offering'], $g['strong']));
        $this->assertNull($this->record($g['offering'], $g['weak']), 'only the one student is recalculated');

        $scheme = db_connect()->table('grading_schemes')->where('id', $g['scheme'])->get()->getRowArray();
        $this->assertSame(1, (int) $scheme['needs_recalculation']);
    }

    public function testRecalculatingAStudentWhoIsNotEnrolledDoesNothing(): void
    {
        $g = $this->gradebook();
        $stranger = CourseFactory::user('student');

        $this->engine->recalculateStudent($g['offering'], $stranger->id);

        $this->assertNull($this->record($g['offering'], $stranger));
    }

    public function testNewScoresChangeTheGradeOnTheNextRecalculation(): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);

        db_connect()->table('gradebook_item_scores')
            ->where('student_id', $g['weak']->id)->where('grade_item_id', $g['exam'])
            ->update(['raw_score' => 100]);

        $this->engine->recalculateStudent($g['offering'], $g['weak']->id);

        // Quiz 10/20 = 50%, exam 100% -> (50 x 40 + 100 x 60) / 100 = 80.
        $this->assertSame(80.0, (float) $this->record($g['offering'], $g['weak'])['calculated_percentage']);
        $this->assertSame('Passed', $this->record($g['offering'], $g['weak'])['remarks']);
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor overrides
    |--------------------------------------------------------------------------
    */

    public function testAnOverrideReplacesTheCalculatedGradeAndIsLogged(): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);

        $result = $this->engine->overrideFinalGrade($this->instructor($g), $g['offering'], $g['weak']->id, 76.0, 'Extra credit agreed');

        $this->assertTrue($result['success']);

        $record = $this->record($g['offering'], $g['weak']);
        $this->assertSame(1, (int) $record['is_override']);
        $this->assertSame(76.0, (float) $record['overridden_percentage']);
        $this->assertSame('Extra credit agreed', $record['override_reason']);
        $this->assertSame('Passed', $record['remarks'], 'remarks follow the overridden percentage');

        $history = db_connect()->table('grade_history')->where('gradebook_record_id', $record['id'])->get()->getRowArray();
        $this->assertSame(50.0, (float) $history['previous_percentage']);
        $this->assertSame(76.0, (float) $history['new_percentage']);
        $this->assertSame('Extra credit agreed', $history['reason']);
    }

    public function testAnOverrideSurvivesRecalculation(): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);
        $this->engine->overrideFinalGrade($this->instructor($g), $g['offering'], $g['weak']->id, 76.0, 'Agreed');

        $this->engine->recalculateOffering($g['offering']);

        $record = $this->record($g['offering'], $g['weak']);
        $this->assertSame(1, (int) $record['is_override']);
        $this->assertSame(76.0, (float) $record['overridden_percentage']);
        $this->assertSame(50.0, (float) $record['calculated_percentage'], 'the calculated grade is still tracked underneath');
        $this->assertSame('Passed', $record['remarks']);
    }

    public function testRemovingAnOverrideRestoresTheCalculatedGrade(): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);
        $this->engine->overrideFinalGrade($this->instructor($g), $g['offering'], $g['weak']->id, 76.0, 'Agreed');

        $result = $this->engine->overrideFinalGrade($this->instructor($g), $g['offering'], $g['weak']->id, null, '');

        $this->assertSame('Override removed.', $result['message']);

        $record = $this->record($g['offering'], $g['weak']);
        $this->assertSame(0, (int) $record['is_override']);
        $this->assertNull($record['overridden_percentage']);
        $this->assertSame('Failed', $record['remarks']);

        $log = db_connect()->table('grade_history')->where('gradebook_record_id', $record['id'])->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('Override removed', $log['reason']);
        $this->assertSame(76.0, (float) $log['previous_percentage']);
        $this->assertSame(50.0, (float) $log['new_percentage']);
    }

    #[DataProvider('provideInvalidOverrides')]
    public function testInvalidOverridesAreRefusedAndChangeNothing(?float $percentage, string $reason, string $message): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);

        $result = $this->engine->overrideFinalGrade($this->instructor($g), $g['offering'], $g['weak']->id, $percentage, $reason);

        $this->assertFalse($result['success']);
        $this->assertSame($message, $result['message']);
        $this->assertSame(0, (int) $this->record($g['offering'], $g['weak'])['is_override']);
        $this->assertSame(0, db_connect()->table('grade_history')->countAllResults());
    }

    /**
     * @return iterable<string, array{float|null, string, string}>
     */
    public static function provideInvalidOverrides(): iterable
    {
        yield 'above 100' => [100.5, 'Because', 'The grade must be between 0 and 100.'];
        yield 'below 0' => [-1.0, 'Because', 'The grade must be between 0 and 100.'];
        yield 'no reason' => [80.0, '   ', 'Give a reason for the override.'];
    }

    public function testOverridingAStudentWhoIsNotEnrolledIsRefused(): void
    {
        $g = $this->gradebook();
        $stranger = CourseFactory::user('student');

        $result = $this->engine->overrideFinalGrade($this->instructor($g), $g['offering'], $stranger->id, 80.0, 'Because');

        $this->assertFalse($result['success']);
        $this->assertSame('Student is not enrolled in this section.', $result['message']);
    }

    public function testAnOverrideOnAStudentNotYetCalculatedCalculatesThemFirst(): void
    {
        $g = $this->gradebook();

        $result = $this->engine->overrideFinalGrade($this->instructor($g), $g['offering'], $g['strong']->id, 95.0, 'Honours');

        $this->assertTrue($result['success']);
        $this->assertSame(78.0, (float) $this->record($g['offering'], $g['strong'])['calculated_percentage']);
    }

    /*
    |--------------------------------------------------------------------------
    | Tags: Incomplete (INC) and Withdrawn
    |--------------------------------------------------------------------------
    */

    public function testAnIncompleteIsNeitherPassedNorFailedAndKeepsTheGrade(): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);
        $teacher = $this->instructor($g);

        $result = $this->engine->markIncomplete($teacher, $g['offering'], $g['weak']->id, 'Medical leave', '2026-12-15');

        $this->assertTrue($result['success']);
        $record = $this->record($g['offering'], $g['weak']);
        $this->assertSame('INC', $record['remarks']);
        $this->assertSame('Medical leave', $record['inc_reason']);
        $this->assertSame('2026-12-15', $record['inc_until']);
        $this->assertEquals(50.0, (float) $record['calculated_percentage'], 'the grade itself is untouched');
    }

    public function testAnIncompleteSurvivesRecalculationAndAnOverride(): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);
        $teacher = $this->instructor($g);
        $this->engine->markIncomplete($teacher, $g['offering'], $g['strong']->id, 'Late project');

        $this->engine->recalculateOffering($g['offering']);
        $this->assertSame('INC', $this->record($g['offering'], $g['strong'])['remarks']);

        $this->engine->overrideFinalGrade($teacher, $g['offering'], $g['strong']->id, 90.0, 'Re-evaluated');
        $this->assertSame('INC', $this->record($g['offering'], $g['strong'])['remarks']);
    }

    public function testRemovingTheIncompleteGivesPassedOrFailedBack(): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);
        $teacher = $this->instructor($g);
        $this->engine->markIncomplete($teacher, $g['offering'], $g['strong']->id, 'Late project');

        $result = $this->engine->clearIncomplete($teacher, $g['offering'], $g['strong']->id);

        $this->assertTrue($result['success']);
        $record = $this->record($g['offering'], $g['strong']);
        $this->assertSame('Passed', $record['remarks']);
        $this->assertNull($record['inc_set_at']);
        $this->assertNull($record['inc_reason']);
    }

    public function testAnIncompleteNeedsAReasonAndARealDate(): void
    {
        $g = $this->gradebook();
        $teacher = $this->instructor($g);

        $this->assertFalse($this->engine->markIncomplete($teacher, $g['offering'], $g['weak']->id, '   ')['success']);
        $this->assertFalse($this->engine->markIncomplete($teacher, $g['offering'], $g['weak']->id, 'Why', '2026-13-45')['success']);
        $this->assertFalse($this->engine->markIncomplete($teacher, $g['offering'], $g['weak']->id, 'Why', 'next week')['success']);
        $this->assertFalse($this->engine->clearIncomplete($teacher, $g['offering'], $g['weak']->id)['success'], 'nothing to remove');
    }

    public function testSomeoneNotInTheSectionCannotBeMarkedIncomplete(): void
    {
        $g = $this->gradebook();

        $result = $this->engine->markIncomplete($this->instructor($g), $g['offering'], CourseFactory::user('student')->id, 'Why');

        $this->assertFalse($result['success']);
    }

    public function testAStudentWhoWithdrewIsTaggedWithdrawnAndKeepsTheirGrade(): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);

        db_connect()->table('enrollments')->where('offering_id', $g['offering'])->where('student_user_id', $g['weak']->id)->update(['status' => 'withdrawn']);
        $this->engine->recalculateOffering($g['offering']);

        $record = $this->record($g['offering'], $g['weak']);
        $this->assertSame('Withdrawn', $record['remarks']);
        $this->assertEquals(50.0, (float) $record['calculated_percentage'], 'the grade they had stays on record');
    }

    public function testEnrolmentChangesMoveTheTagAtOnceAndBack(): void
    {
        $g = $this->gradebook();
        $this->engine->recalculateOffering($g['offering']);
        $enrol = static fn (string $status) => db_connect()->table('enrollments')->where('offering_id', $g['offering'])->where('student_user_id', $g['strong']->id)->update(['status' => $status]);

        $enrol('dropped');
        $this->engine->syncEnrolment($g['offering'], $g['strong']->id);
        $this->assertSame('Withdrawn', $this->record($g['offering'], $g['strong'])['remarks']);

        $enrol('enrolled');
        $this->engine->syncEnrolment($g['offering'], $g['strong']->id);
        $this->assertSame('Passed', $this->record($g['offering'], $g['strong'])['remarks']);
    }

    public function testSyncingAStudentWithoutARecordDoesNothing(): void
    {
        $g = $this->gradebook();

        $this->engine->syncEnrolment($g['offering'], $g['none']->id);

        $this->assertNull($this->record($g['offering'], $g['none']));
    }

    /** An instructor user for override calls (any user works; the service only records who changed it). */
    private function instructor(array $gradebook): User
    {
        return CourseFactory::user('instructor');
    }
}
