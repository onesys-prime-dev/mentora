<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeSheetLayout;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The grade sheet's shape, fingerprints and spreadsheet formulas, from
 * hand-written grading setups (no database).
 *
 * @internal
 */
final class GradeSheetLayoutTest extends CIUnitTestCase
{
    /**
     * Quizzes 40% (q1 /10 locked to an assessment, q2 /20 manual) and
     * Exams 60% (e1 /100 manual).
     */
    private function context(array $overrides = []): array
    {
        return $overrides + [
            'offeringId' => 5,
            'scheme' => ['id' => 9, 'passing_percentage' => 75, 'grade_scale_id' => 2, 'uses_periods' => 0],
            'periods' => [],
            'bands' => [
                ['id' => 1, 'name' => 'A', 'minimum_percentage' => 90],
                ['id' => 2, 'name' => 'B', 'minimum_percentage' => 80],
                ['id' => 3, 'name' => 'F', 'minimum_percentage' => 0],
            ],
            'categories' => [
                ['id' => 1, 'period_id' => null, 'name' => 'Quizzes', 'weight_percentage' => 40],
                ['id' => 2, 'period_id' => null, 'name' => 'Exams', 'weight_percentage' => 60],
            ],
            'items' => [
                ['id' => 11, 'category_id' => 1, 'name' => 'Q1', 'max_points' => 10, 'assessment_id' => 77, 'assessment_title' => 'Quiz One'],
                ['id' => 12, 'category_id' => 1, 'name' => 'Q2', 'max_points' => 20, 'assessment_id' => null],
                ['id' => 13, 'category_id' => 2, 'name' => 'E1', 'max_points' => 100, 'assessment_id' => null],
            ],
        ];
    }

    private function periodContext(): array
    {
        return $this->context([
            'scheme' => ['id' => 9, 'passing_percentage' => 75, 'grade_scale_id' => 2, 'uses_periods' => 1],
            'periods' => [
                ['id' => 1, 'name' => 'Midterm', 'weight_percentage' => 50],
                ['id' => 2, 'name' => 'Finals', 'weight_percentage' => 50],
            ],
            'categories' => [
                ['id' => 1, 'period_id' => 1, 'name' => 'Quizzes', 'weight_percentage' => 100],
                ['id' => 2, 'period_id' => 2, 'name' => 'Exams', 'weight_percentage' => 100],
                ['id' => 3, 'period_id' => null, 'name' => 'Orphan', 'weight_percentage' => 10],
            ],
            'items' => [
                ['id' => 11, 'category_id' => 1, 'name' => 'Q1', 'max_points' => 10, 'assessment_id' => null],
                ['id' => 13, 'category_id' => 2, 'name' => 'E1', 'max_points' => 100, 'assessment_id' => null],
                ['id' => 14, 'category_id' => 3, 'name' => 'Lost', 'max_points' => 5, 'assessment_id' => null],
            ],
        ]);
    }

    public function testColumnsAreLaidOutByCategoryWithItsPercentageAfterItsItems(): void
    {
        $layout = new GradeSheetLayout($this->context());

        $this->assertSame([
            'student_id', 'student_number', 'student_name',
            'item:11', 'item:12', 'category:1',
            'item:13', 'category:2',
            'final', 'grade', 'remarks', 'row_hash',
        ], $layout->keys());
    }

    public function testHeadersShowPointsAndLockedItems(): void
    {
        $headers = array_column((new GradeSheetLayout($this->context()))->columns(), 'header', 'key');

        $this->assertSame('Quiz One (/10) [locked]', $headers['item:11'], 'the assessment title wins, and it is locked');
        $this->assertSame('Q2 (/20)', $headers['item:12']);
        $this->assertSame('Quizzes % (40%)', $headers['category:1']);
        $this->assertSame('Final %', $headers['final']);
    }

    public function testOnlyAssessmentItemsAreLocked(): void
    {
        $layout = new GradeSheetLayout($this->context());

        $this->assertFalse($layout->isManual(['assessment_id' => 5]));
        $this->assertTrue($layout->isManual(['assessment_id' => null]));
        $this->assertTrue($layout->isManual([]));
        $this->assertSame([11, 12, 13], array_map(static fn ($i) => $i['id'], $layout->items()));
    }

    public function testItemsOutsideAnyCategoryAreLeftOut(): void
    {
        $context = $this->context();
        $context['items'][] = ['id' => 14, 'category_id' => 99, 'name' => 'Stray', 'max_points' => 5, 'assessment_id' => null];

        $this->assertSame([11, 12, 13], array_map(static fn ($i) => $i['id'], (new GradeSheetLayout($context))->items()));
    }

    public function testPeriodsGroupTheirCategoriesAndAddAPeriodColumn(): void
    {
        $layout = new GradeSheetLayout($this->periodContext());

        $this->assertTrue($layout->usesPeriods());
        $this->assertSame([
            'student_id', 'student_number', 'student_name',
            'item:11', 'category:1', 'item:13', 'category:2',
            'period:1', 'period:2',
            'final', 'grade', 'remarks', 'row_hash',
        ], $layout->keys());
        $this->assertNotContains('category:3', $layout->keys(), 'a category with no period does not count');
        $this->assertNotContains('item:14', $layout->keys());
    }

    public function testPeriodsAreIgnoredWhenTheSchemeDoesNotUseThem(): void
    {
        $context = $this->periodContext();
        $context['scheme']['uses_periods'] = 0;

        $this->assertFalse((new GradeSheetLayout($context))->usesPeriods());

        $context = $this->periodContext();
        $context['periods'] = [];

        $this->assertFalse((new GradeSheetLayout($context))->usesPeriods());
    }

    public function testNumbersAreWrittenWithoutTrailingZeros(): void
    {
        $this->assertSame('10', GradeSheetLayout::number(10));
        $this->assertSame('7.5', GradeSheetLayout::number(7.5));
        $this->assertSame('0.33', GradeSheetLayout::number(0.333));
        $this->assertSame('0', GradeSheetLayout::number(0));
    }

    public function testColumnLetters(): void
    {
        $this->assertSame('A', GradeSheetLayout::letter(1));
        $this->assertSame('Z', GradeSheetLayout::letter(26));
        $this->assertSame('AA', GradeSheetLayout::letter(27));
        $this->assertSame('AZ', GradeSheetLayout::letter(52));
        $this->assertSame('BA', GradeSheetLayout::letter(53));
    }

    /*
    |--------------------------------------------------------------------------
    | Fingerprints
    |--------------------------------------------------------------------------
    */

    public function testTheSignatureIsStableForTheSameSetup(): void
    {
        $this->assertSame((new GradeSheetLayout($this->context()))->signature(), (new GradeSheetLayout($this->context()))->signature());
        $this->assertSame(32, strlen((new GradeSheetLayout($this->context()))->signature()));
    }

    /** @return iterable<string, array{0: callable(array): array}> */
    public static function setupChanges(): iterable
    {
        yield 'a category weight' => [static function (array $c): array { $c['categories'][0]['weight_percentage'] = 35; return $c; }];
        yield 'an item max' => [static function (array $c): array { $c['items'][1]['max_points'] = 25; return $c; }];
        yield 'the passing mark' => [static function (array $c): array { $c['scheme']['passing_percentage'] = 60; return $c; }];
        yield 'the grade scale' => [static function (array $c): array { $c['scheme']['grade_scale_id'] = 3; return $c; }];
        yield 'a band minimum' => [static function (array $c): array { $c['bands'][1]['minimum_percentage'] = 85; return $c; }];
        yield 'another section' => [static function (array $c): array { $c['offeringId'] = 6; return $c; }];
        yield 'an item moving category' => [static function (array $c): array { $c['items'][1]['category_id'] = 2; return $c; }];
        yield 'a removed item' => [static function (array $c): array { array_pop($c['items']); return $c; }];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('setupChanges')]
    public function testTheSignatureChangesWhenTheGradingSetupDoes(callable $change): void
    {
        $this->assertNotSame(
            (new GradeSheetLayout($this->context()))->signature(),
            (new GradeSheetLayout($change($this->context())))->signature()
        );
    }

    public function testRenamingAnItemDoesNotChangeTheSignature(): void
    {
        $context = $this->context();
        $context['items'][1]['name'] = 'Renamed';

        $this->assertSame((new GradeSheetLayout($this->context()))->signature(), (new GradeSheetLayout($context))->signature());
    }

    public function testTheRowHashCoversTheStudentAndOnlyTheLockedScores(): void
    {
        $layout = new GradeSheetLayout($this->context());

        $base = $layout->rowHash(3, '2026-01', [11 => 8.0]);

        $this->assertSame($base, $layout->rowHash(3, '2026-01', [11 => 8.0]));
        $this->assertSame($base, $layout->rowHash(3, ' 2026-01 ', [11 => 8.0]), 'the number is trimmed');
        $this->assertSame($base, $layout->rowHash(3, '2026-01', [11 => 8.0, 12 => 19.0]), 'a manual score is free to change');
        $this->assertNotSame($base, $layout->rowHash(3, '2026-01', [11 => 9.0]));
        $this->assertNotSame($base, $layout->rowHash(3, '2026-01', [11 => null]));
        $this->assertNotSame($base, $layout->rowHash(4, '2026-01', [11 => 8.0]));
        $this->assertNotSame($base, $layout->rowHash(3, '2026-02', [11 => 8.0]));
    }

    /*
    |--------------------------------------------------------------------------
    | Formulas
    |--------------------------------------------------------------------------
    */

    public function testTheCategoryFormulaAveragesOnlyGradedItems(): void
    {
        $layout = new GradeSheetLayout($this->context());

        // item:11 is column D, item:12 is column E.
        $this->assertSame(
            '=IF((IF(ISNUMBER(D4),10,0)+IF(ISNUMBER(E4),20,0))=0,"",SUM(D4,E4)/(IF(ISNUMBER(D4),10,0)+IF(ISNUMBER(E4),20,0))*100)',
            $layout->categoryFormula(['id' => 1], 4)
        );
    }

    public function testACategoryWithoutItemsIsBlank(): void
    {
        $this->assertSame('=""', (new GradeSheetLayout($this->context()))->categoryFormula(['id' => 99], 4));
    }

    public function testTheFinalFormulaWeightsTheCategoriesThatHaveWork(): void
    {
        // category:1 is column F, category:2 is column H.
        $this->assertSame(
            '=IF((IF(ISNUMBER(F4),40,0)+IF(ISNUMBER(H4),60,0))=0,"",ROUND((IF(ISNUMBER(F4),F4*40,0)+IF(ISNUMBER(H4),H4*60,0))/(IF(ISNUMBER(F4),40,0)+IF(ISNUMBER(H4),60,0)),2))',
            (new GradeSheetLayout($this->context()))->finalFormula(4)
        );
    }

    public function testAZeroWeightCategoryIsLeftOutOfTheRollup(): void
    {
        $context = $this->context();
        $context['categories'][1]['weight_percentage'] = 0;

        $formula = (new GradeSheetLayout($context))->finalFormula(4);

        $this->assertStringNotContainsString('H4', $formula);
        $this->assertStringContainsString('F4', $formula);
    }

    public function testWithNoWeightedCategoriesTheFinalIsBlank(): void
    {
        $context = $this->context();
        $context['categories'][0]['weight_percentage'] = 0;
        $context['categories'][1]['weight_percentage'] = 0;

        $this->assertSame('=""', (new GradeSheetLayout($context))->finalFormula(4));
    }

    public function testWithPeriodsTheFinalRollsUpThePeriodsAndEachPeriodItsCategories(): void
    {
        $layout = new GradeSheetLayout($this->periodContext());

        // period:1 is column H, period:2 is column I; category:1 is E, category:2 is G.
        $this->assertStringContainsString('H4*50', $layout->finalFormula(4));
        $this->assertStringContainsString('I4*50', $layout->finalFormula(4));
        $this->assertStringNotContainsString('E4*', $layout->finalFormula(4));
        $this->assertStringContainsString('E4*100', $layout->periodFormula(['id' => 1], 4));
        $this->assertStringNotContainsString('G4', $layout->periodFormula(['id' => 1], 4));
    }

    public function testTheGradeFormulaWalksTheBandsFromTheTopDown(): void
    {
        // final is column I in the flat layout.
        $this->assertSame(
            '=IF(I4="","",IF(I4>=90,"A",IF(I4>=80,"B","F")))',
            (new GradeSheetLayout($this->context()))->gradeFormula(4)
        );
    }

    public function testNoBandsMeansNoGradeAndAQuoteInABandNameIsEscaped(): void
    {
        $context = $this->context();
        $context['bands'] = [];
        $this->assertSame('=""', (new GradeSheetLayout($context))->gradeFormula(4));

        $context['bands'] = [['id' => 1, 'name' => 'A"+', 'minimum_percentage' => 0]];
        $this->assertSame('=IF(I4="","","A""+")', (new GradeSheetLayout($context))->gradeFormula(4));
    }

    public function testTheRemarksFormulaUsesThePassingMark(): void
    {
        $this->assertSame(
            '=IF(I4="","Incomplete",IF(I4>=75,"Passed","Failed"))',
            (new GradeSheetLayout($this->context()))->remarksFormula(4)
        );
    }

    public function testCellOfFindsAColumnByKey(): void
    {
        $layout = new GradeSheetLayout($this->context());

        $this->assertSame('D7', $layout->cellOf('item:11', 7));
        $this->assertSame('L9', $layout->cellOf('row_hash', 9));
    }
}
