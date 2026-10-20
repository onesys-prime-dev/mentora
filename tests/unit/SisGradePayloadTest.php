<?php

declare(strict_types=1);

use App\Services\Sis\SisGradePayload;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * What is sent to the SIS: final grades only, only what is new or changed,
 * and a delivery key that is the same for the same content.
 *
 * @internal
 */
final class SisGradePayloadTest extends CIUnitTestCase
{
    /** @return array{offering_id: int, student_id: int, course_external_id: string, student_external_id: string, percentage: ?float, remarks: string} */
    private static function candidate(int $offering, int $student, ?float $percentage, string $remarks = 'Passed', string $course = 'C1'): array
    {
        return ['offering_id' => $offering, 'student_id' => $student, 'course_external_id' => $course, 'student_external_id' => "S{$student}", 'percentage' => $percentage, 'remarks' => $remarks];
    }

    public function testTheFinalPercentageIsTheOverrideWhenThereIsOneOtherwiseTheCalculatedOne(): void
    {
        $this->assertSame(88.5, SisGradePayload::finalPercentage(['calculated_percentage' => '88.50', 'is_override' => 0, 'overridden_percentage' => null]));
        $this->assertSame(95.0, SisGradePayload::finalPercentage(['calculated_percentage' => '70.00', 'is_override' => 1, 'overridden_percentage' => '95.00']));
        $this->assertNull(SisGradePayload::finalPercentage(['calculated_percentage' => null, 'is_override' => 0, 'overridden_percentage' => null]));
        $this->assertNull(SisGradePayload::finalPercentage(['calculated_percentage' => '70.00', 'is_override' => 1, 'overridden_percentage' => null]));
        $this->assertSame(0.0, SisGradePayload::finalPercentage(['calculated_percentage' => '0.00', 'is_override' => 0, 'overridden_percentage' => null]));
    }

    public function testNewGradesAreSentWithOnlyTheFourColumns(): void
    {
        $plan = SisGradePayload::plan([self::candidate(1, 10, 91.456), self::candidate(1, 11, 70.0, 'Failed')], []);

        $this->assertSame(2, $plan['created']);
        $this->assertSame(0, $plan['updated']);
        $this->assertSame(0, $plan['skipped']);
        $this->assertSame(['course_external_id', 'student_external_id', 'percentage', 'remarks'], array_keys($plan['rows'][0]));
        $this->assertSame('91.46', $plan['rows'][0]['percentage']);
        $this->assertSame('70.00', $plan['rows'][1]['percentage']);
        $this->assertSame('Failed', $plan['rows'][1]['remarks']);
    }

    public function testRowsAreInAStableOrder(): void
    {
        $plan = SisGradePayload::plan([self::candidate(1, 12, 80.0), self::candidate(2, 10, 80.0, 'Passed', 'C0'), self::candidate(1, 11, 80.0)], []);

        $this->assertSame(['C0/S10', 'C1/S11', 'C1/S12'], array_map(static fn (array $r): string => "{$r['course_external_id']}/{$r['student_external_id']}", $plan['rows']));
    }

    public function testAGradeThatIsUnchangedSinceItWasSentIsLeftOut(): void
    {
        $plan = SisGradePayload::plan([self::candidate(1, 10, 91.0)], ['1:10' => ['percentage' => 91.0, 'remarks' => 'Passed']]);

        $this->assertSame([], $plan['rows']);
        $this->assertSame(1, $plan['skipped']);
    }

    public function testAChangedPercentageOrRemarkIsSentAgainAsAnUpdate(): void
    {
        $sent = ['1:10' => ['percentage' => 91.0, 'remarks' => 'Passed'], '1:11' => ['percentage' => 80.0, 'remarks' => 'Passed']];

        $plan = SisGradePayload::plan([self::candidate(1, 10, 92.0), self::candidate(1, 11, 80.0, 'Failed')], $sent);

        $this->assertSame(0, $plan['created']);
        $this->assertSame(2, $plan['updated']);
        $this->assertSame(['updated', 'updated'], array_column($plan['items'], 'change'));
    }

    public function testAGradeNobodyHasGivenYetIsNotSent(): void
    {
        $plan = SisGradePayload::plan([self::candidate(1, 10, null, 'Incomplete')], []);

        $this->assertSame([], $plan['rows']);
        $this->assertSame(1, $plan['skipped']);
    }

    public function testEveryGradeLeftOutNamesWhy(): void
    {
        $plan = SisGradePayload::plan([
            self::candidate(1, 10, 91.0),
            self::candidate(1, 11, 80.0) + ['skip' => SisGradePayload::NOT_FINAL],
            self::candidate(1, 12, 80.0) + ['skip' => SisGradePayload::WITHDRAWN],
            self::candidate(1, 13, 80.0) + ['skip' => SisGradePayload::INCOMPLETE],
            self::candidate(1, 14, 80.0) + ['skip' => SisGradePayload::NOT_IN_SIS],
            self::candidate(1, 15, null, 'Incomplete'),
            self::candidate(1, 16, 70.0),
        ], ['1:16' => ['percentage' => 70.0, 'remarks' => 'Passed']]);

        $this->assertSame(1, $plan['created']);
        $this->assertSame(6, $plan['skipped']);
        $this->assertSame(
            [
                11 => SisGradePayload::NOT_FINAL,
                12 => SisGradePayload::WITHDRAWN,
                13 => SisGradePayload::INCOMPLETE,
                14 => SisGradePayload::NOT_IN_SIS,
                15 => SisGradePayload::NO_GRADE,
                16 => SisGradePayload::UNCHANGED,
            ],
            array_column($plan['left_out'], 'reason', 'student_id')
        );
    }

    public function testEveryReasonHasWordsForThePersonReadingThePreview(): void
    {
        foreach ([SisGradePayload::NOT_FINAL, SisGradePayload::WITHDRAWN, SisGradePayload::INCOMPLETE, SisGradePayload::NO_GRADE, SisGradePayload::NOT_IN_SIS, SisGradePayload::UNCHANGED] as $reason) {
            $this->assertNotSame('', SisGradePayload::REASONS[$reason] ?? '', $reason);
        }
    }

    public function testASubCentGradeChangeIsNotAChange(): void
    {
        $plan = SisGradePayload::plan([self::candidate(1, 10, 91.001)], ['1:10' => ['percentage' => 91.0, 'remarks' => 'Passed']]);

        $this->assertSame([], $plan['rows']);
    }

    public function testTheSameGradesGiveTheSameKeyInAnyOrder(): void
    {
        $a = SisGradePayload::plan([self::candidate(1, 10, 91.0), self::candidate(1, 11, 80.0)], [])['rows'];
        $b = SisGradePayload::plan([self::candidate(1, 11, 80.0), self::candidate(1, 10, 91.0)], [])['rows'];

        $this->assertSame(SisGradePayload::batchKey(7, $a), SisGradePayload::batchKey(7, $b));
        $this->assertSame(SisGradePayload::batchKey(7, $a), SisGradePayload::batchKey(7, array_reverse($a)));
        $this->assertMatchesRegularExpression('/^g7-[0-9a-f]{24}$/', SisGradePayload::batchKey(7, $a));
    }

    public function testDifferentGradesOrAnotherInstitutionGiveAnotherKey(): void
    {
        $a = SisGradePayload::plan([self::candidate(1, 10, 91.0)], [])['rows'];
        $b = SisGradePayload::plan([self::candidate(1, 10, 92.0)], [])['rows'];

        $this->assertNotSame(SisGradePayload::batchKey(7, $a), SisGradePayload::batchKey(7, $b));
        $this->assertNotSame(SisGradePayload::batchKey(7, $a), SisGradePayload::batchKey(8, $a));
    }

    public function testTheKeyIsSafeForTheFileConnectorsBatchKeyRule(): void
    {
        $key = SisGradePayload::batchKey(123456, SisGradePayload::plan([self::candidate(1, 10, 91.0)], [])['rows']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $key);
    }
}
