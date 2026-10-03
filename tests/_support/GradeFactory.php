<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Shield\Entities\User;

/**
 * Smallest valid rows for gradebook tests: a grade scale, a grading scheme
 * for an offering, its periods, categories and items, and a student's score
 * on an item. Pairs with CourseFactory (which provides the offering and the
 * students).
 */
final class GradeFactory
{
    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * A grade scale with the given bands, highest first.
     *
     * @param array<string, int|float> $bands letter => minimum percentage
     *
     * @return int grade scale id
     */
    public static function scale(array $bands = ['A' => 90, 'B' => 80, 'C' => 75, 'F' => 0]): int
    {
        $db = db_connect();

        $db->table('grade_scales')->insert(['name' => 'Test scale ' . uniqid(), 'created_at' => self::now()]);
        $scaleId = (int) $db->insertID();

        $maximum = 100;
        $order = 1;

        foreach ($bands as $name => $minimum) {
            $db->table('grade_scale_items')->insert([
                'grade_scale_id' => $scaleId,
                'name' => (string) $name,
                'minimum_percentage' => $minimum,
                'maximum_percentage' => $maximum,
                'sort_order' => $order++,
            ]);

            $maximum = $minimum - 0.01;
        }

        return $scaleId;
    }

    /** The grading scheme of an offering. @return int scheme id */
    public static function scheme(int $offeringId, float $passing = 75.0, bool $usesPeriods = false, ?int $scaleId = null): int
    {
        $db = db_connect();

        $db->table('grading_schemes')->insert([
            'course_offering_id' => $offeringId,
            'name' => 'Scheme',
            'passing_percentage' => $passing,
            'uses_periods' => $usesPeriods ? 1 : 0,
            'grade_scale_id' => $scaleId ?? self::scale(),
            'status' => 'Active',
            'needs_recalculation' => 1,
            'created_at' => self::now(),
        ]);

        return (int) $db->insertID();
    }

    public static function period(int $schemeId, string $name, float $weight, int $order = 1): int
    {
        $db = db_connect();

        $db->table('grading_periods')->insert([
            'grading_scheme_id' => $schemeId,
            'name' => $name,
            'weight_percentage' => $weight,
            'sort_order' => $order,
            'created_at' => self::now(),
        ]);

        return (int) $db->insertID();
    }

    public static function category(int $schemeId, string $name, float $weight, ?int $periodId = null, int $order = 1): int
    {
        $db = db_connect();

        $db->table('grade_categories')->insert([
            'grading_scheme_id' => $schemeId,
            'period_id' => $periodId,
            'name' => $name,
            'weight_percentage' => $weight,
            'sort_order' => $order,
            'created_at' => self::now(),
        ]);

        return (int) $db->insertID();
    }

    /** A manual gradebook item (not tied to an assessment). */
    public static function item(int $schemeId, ?int $categoryId, string $name, float $maxPoints, int $order = 1): int
    {
        $db = db_connect();

        $db->table('grade_items')->insert([
            'grading_scheme_id' => $schemeId,
            'category_id' => $categoryId,
            'name' => $name,
            'max_points' => $maxPoints,
            'sort_order' => $order,
            'created_at' => self::now(),
        ]);

        return (int) $db->insertID();
    }

    /** A student's score on a gradebook item. */
    public static function score(int $offeringId, User $student, int $itemId, ?float $rawScore, bool $missing = false): void
    {
        db_connect()->table('gradebook_item_scores')->insert([
            'course_offering_id' => $offeringId,
            'student_id' => $student->id,
            'grade_item_id' => $itemId,
            'raw_score' => $rawScore,
            'is_missing' => $missing ? 1 : 0,
            'created_at' => self::now(),
        ]);
    }
}
