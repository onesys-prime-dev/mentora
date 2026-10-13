<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Shield\Entities\User;

/**
 * The academic defaults an institution can set, and the one place that says
 * which value is in force.
 *
 * Resolution, for every value: the institution's own setting, else the
 * platform's (the system default grading scheme for the passing mark, scale
 * and categories; the constants below for the thresholds). So an institution
 * that sets nothing behaves exactly as before.
 *
 *  - Passing mark, grade scale and the default grading scheme (weighted
 *    categories) are what a NEW offering's scheme starts from. Offerings that
 *    already have a scheme keep it.
 *  - The three thresholds are what reports and dashboards use for "no recent
 *    activity" (days), "behind" (percent of the published items) and
 *    "overdue grading" (days an assessment has had ungraded work).
 *
 * Every change is audited with old and new values.
 */
class AcademicDefaults
{
    /** What an institution gets when it has set nothing, for the values the platform template does not carry. */
    public const PLATFORM_PASSING = 75.0;

    public const PLATFORM_INACTIVE_DAYS = 14;

    public const PLATFORM_BEHIND_BELOW = 50;

    public const PLATFORM_OVERDUE_DAYS = 7;

    public const MAX_CATEGORIES = 12;

    /**
     * The value in force for each setting.
     *
     * @param array<string, mixed>|null $stored   the institution's row, or null
     * @param array<string, mixed>|null $platform the system default grading scheme row, or null
     *
     * @return array{passing: float, passingFromInstitution: bool, scaleId: ?int, scaleFromInstitution: bool, inactiveDays: int, inactiveFromInstitution: bool, behindBelow: int, behindFromInstitution: bool, overdueGradingDays: int, overdueFromInstitution: bool}
     */
    public static function resolve(?array $stored, ?array $platform): array
    {
        $stored ??= [];

        $passing = $stored['passing_percentage'] ?? null;
        $scale   = $stored['grade_scale_id'] ?? null;
        $inactive = $stored['inactive_days'] ?? null;
        $behind  = $stored['behind_below_percent'] ?? null;
        $overdue = $stored['overdue_grading_days'] ?? null;

        return [
            'passing'                 => $passing !== null ? (float) $passing : (float) ($platform['passing_percentage'] ?? self::PLATFORM_PASSING),
            'passingFromInstitution'  => $passing !== null,
            'scaleId'                 => $scale !== null ? (int) $scale : (isset($platform['grade_scale_id']) ? (int) $platform['grade_scale_id'] : null),
            'scaleFromInstitution'    => $scale !== null,
            'inactiveDays'            => $inactive !== null ? (int) $inactive : self::PLATFORM_INACTIVE_DAYS,
            'inactiveFromInstitution' => $inactive !== null,
            'behindBelow'             => $behind !== null ? (int) $behind : self::PLATFORM_BEHIND_BELOW,
            'behindFromInstitution'   => $behind !== null,
            'overdueGradingDays'      => $overdue !== null ? (int) $overdue : self::PLATFORM_OVERDUE_DAYS,
            'overdueFromInstitution'  => $overdue !== null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * What is in force for an institution, with the institution's own
     * categories (or the platform's) and the scales it may choose from.
     *
     * @return array<string, mixed>
     */
    public function forInstitution(int $institutionId): array
    {
        $db       = db_connect();
        $stored   = $db->table('institution_academic_defaults')->where('institution_id', $institutionId)->get()->getRowArray();
        $platform = $db->table('grading_schemes')->where(['is_system_default' => 1, 'course_offering_id' => null, 'institution_id' => null])->get()->getRowArray();

        $own = $db->table('grading_schemes')->where(['institution_id' => $institutionId, 'course_offering_id' => null])->get()->getRowArray();

        $categorySource = $own ?? $platform;
        $categories     = $categorySource === null ? [] : $db->table('grade_categories')
            ->select('name, weight_percentage, sort_order')
            ->where('grading_scheme_id', $categorySource['id'])
            ->orderBy('sort_order')->orderBy('id')
            ->get()->getResultArray();

        return self::resolve($stored, $platform) + [
            'categories'             => array_map(static fn (array $c): array => ['name' => (string) $c['name'], 'weight' => (float) $c['weight_percentage']], $categories),
            'categoriesFromInstitution' => $own !== null,
            'scales'                 => $db->table('grade_scales')->select('id, name')->where('status', 'Active')->orderBy('name')->get()->getResultArray(),
        ];
    }

    /**
     * The thresholds for the institution an offering belongs to.
     *
     * @return array{inactiveDays: int, behindBelow: int, overdueGradingDays: int}
     */
    public function thresholdsForOffering(int $offeringId): array
    {
        return $this->thresholdsForOfferings([$offeringId])[$offeringId] ?? $this->platformThresholds();
    }

    /**
     * The thresholds for many offerings at once (one query).
     *
     * @param list<int> $offeringIds
     *
     * @return array<int, array{inactiveDays: int, behindBelow: int, overdueGradingDays: int}>
     */
    public function thresholdsForOfferings(array $offeringIds): array
    {
        $offeringIds = array_values(array_unique(array_filter(array_map('intval', $offeringIds))));

        if ($offeringIds === []) {
            return [];
        }

        $rows = db_connect()->table('course_offerings o')
            ->select('o.id AS offering_id, d.inactive_days, d.behind_below_percent, d.overdue_grading_days')
            ->join('academic_terms t', 't.id = o.term_id')
            ->join('institution_academic_defaults d', 'd.institution_id = t.institution_id', 'left')
            ->whereIn('o.id', $offeringIds)
            ->get()->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $r = self::resolve($row, null);

            $out[(int) $row['offering_id']] = ['inactiveDays' => $r['inactiveDays'], 'behindBelow' => $r['behindBelow'], 'overdueGradingDays' => $r['overdueGradingDays']];
        }

        return $out;
    }

    /**
     * The thresholds for the institution a person belongs to.
     *
     * @return array{inactiveDays: int, behindBelow: int, overdueGradingDays: int}
     */
    public function thresholdsForUser(?User $user): array
    {
        if ($user === null) {
            return $this->platformThresholds();
        }

        $institutionId = (new \App\Services\Subscriptions\PlanLimits())->institutionOf((int) $user->id);

        if ($institutionId === null) {
            return $this->platformThresholds();
        }

        $r = $this->forInstitution($institutionId);

        return ['inactiveDays' => $r['inactiveDays'], 'behindBelow' => $r['behindBelow'], 'overdueGradingDays' => $r['overdueGradingDays']];
    }

    /**
     * What a new offering's grading scheme starts from: the passing mark, the
     * scale and the categories, the institution's where it set them.
     *
     * @return array{passing: float, scaleId: ?int, categories: list<array{name: string, weight: float, sort: int}>}
     */
    public function templateForOffering(int $offeringId): array
    {
        $db   = db_connect();
        $term = $db->table('course_offerings o')->select('t.institution_id')->join('academic_terms t', 't.id = o.term_id')->where('o.id', $offeringId)->get()->getRowArray();

        $platform = $db->table('grading_schemes')->where(['is_system_default' => 1, 'course_offering_id' => null, 'institution_id' => null])->get()->getRowArray();

        if ($term === null) {
            return $this->fromScheme($platform, self::resolve(null, $platform));
        }

        $institutionId = (int) $term['institution_id'];
        $stored        = $db->table('institution_academic_defaults')->where('institution_id', $institutionId)->get()->getRowArray();
        $own           = $db->table('grading_schemes')->where(['institution_id' => $institutionId, 'course_offering_id' => null])->get()->getRowArray();

        return $this->fromScheme($own ?? $platform, self::resolve($stored, $platform));
    }

    /*
    |--------------------------------------------------------------------------
    | Saving
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $input passing_percentage, grade_scale_id, inactive_days, behind_below_percent,
     *                                    overdue_grading_days, category_name[], category_weight[]
     *
     * @throws StructureException
     */
    public function save(int $institutionId, array $input, ?User $actor = null): void
    {
        $values = [
            'passing_percentage'   => $this->decimal($input['passing_percentage'] ?? '', 'passing_percentage', 1, 100, 'The passing mark is a percentage from 1 to 100.'),
            'inactive_days'        => $this->whole($input['inactive_days'] ?? '', 'inactive_days', 1, 365, 'Days without activity is a whole number from 1 to 365.'),
            'behind_below_percent' => $this->whole($input['behind_below_percent'] ?? '', 'behind_below_percent', 1, 100, 'Behind below is a percentage from 1 to 100.'),
            'overdue_grading_days' => $this->whole($input['overdue_grading_days'] ?? '', 'overdue_grading_days', 1, 90, 'Overdue grading is a whole number of days from 1 to 90.'),
            'grade_scale_id'       => $this->scale($input['grade_scale_id'] ?? ''),
        ];

        $categories = $this->categories($input['category_name'] ?? [], $input['category_weight'] ?? []);

        $db     = db_connect();
        $before = $db->table('institution_academic_defaults')->where('institution_id', $institutionId)->get()->getRowArray();
        $old    = $this->forInstitution($institutionId);

        $db->transStart();

        $row = $values + ['institution_id' => $institutionId, 'updated_by_user_id' => ($actor ?? auth()->user())?->id, 'updated_at' => date('Y-m-d H:i:s')];

        if ($before === null) {
            $db->table('institution_academic_defaults')->insert($row);
        } else {
            $db->table('institution_academic_defaults')->where('institution_id', $institutionId)->update($row);
        }

        $this->writeCategories($institutionId, $categories);

        $db->transComplete();

        $new = $this->forInstitution($institutionId);

        service('auditLogger')->log('institution.academic_defaults_updated', $actor ?? auth()->user(), [
            'target_type'    => 'institution',
            'target_id'      => $institutionId,
            'institution_id' => $institutionId,
            'old'            => $this->summary($old),
            'new'            => $this->summary($new),
        ]);
    }

    /** Back to the platform's values for everything. */
    public function reset(int $institutionId, ?User $actor = null): void
    {
        $old = $this->forInstitution($institutionId);

        $db = db_connect();
        $db->transStart();
        $db->table('institution_academic_defaults')->where('institution_id', $institutionId)->delete();
        $this->writeCategories($institutionId, []);
        $db->transComplete();

        service('auditLogger')->log('institution.academic_defaults_updated', $actor ?? auth()->user(), [
            'target_type'    => 'institution',
            'target_id'      => $institutionId,
            'institution_id' => $institutionId,
            'old'            => $this->summary($old),
            'new'            => $this->summary($this->forInstitution($institutionId)),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @return array{inactiveDays: int, behindBelow: int, overdueGradingDays: int} */
    private function platformThresholds(): array
    {
        return ['inactiveDays' => self::PLATFORM_INACTIVE_DAYS, 'behindBelow' => self::PLATFORM_BEHIND_BELOW, 'overdueGradingDays' => self::PLATFORM_OVERDUE_DAYS];
    }

    /**
     * @param array<string, mixed>|null $scheme
     * @param array<string, mixed>      $resolved
     *
     * @return array{passing: float, scaleId: ?int, categories: list<array{name: string, weight: float, sort: int}>}
     */
    private function fromScheme(?array $scheme, array $resolved): array
    {
        $categories = [];

        if ($scheme !== null) {
            $rows = db_connect()->table('grade_categories')->where('grading_scheme_id', $scheme['id'])->orderBy('sort_order')->orderBy('id')->get()->getResultArray();

            foreach ($rows as $row) {
                $categories[] = ['name' => (string) $row['name'], 'weight' => (float) $row['weight_percentage'], 'sort' => (int) $row['sort_order']];
            }
        }

        return ['passing' => $resolved['passing'], 'scaleId' => $resolved['scaleId'], 'categories' => $categories];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function summary(array $state): array
    {
        return [
            'mark'        => $state['passing'],
            'scale'       => $state['scaleId'],
            'inactive'    => $state['inactiveDays'],
            'behind'      => $state['behindBelow'],
            'overdue'     => $state['overdueGradingDays'],
            'categories'  => $state['categoriesFromInstitution'] ? $state['categories'] : 'platform',
        ];
    }

    /**
     * Replaces the institution's default grading categories, or removes them
     * (an empty list) so it falls back to the platform's.
     *
     * @param list<array{name: string, weight: float}> $categories
     */
    private function writeCategories(int $institutionId, array $categories): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $scheme = $db->table('grading_schemes')->where(['institution_id' => $institutionId, 'course_offering_id' => null])->get()->getRowArray();

        if ($categories === []) {
            if ($scheme !== null) {
                $db->table('grade_categories')->where('grading_scheme_id', $scheme['id'])->delete();
                $db->table('grading_schemes')->where('id', $scheme['id'])->delete();
            }

            return;
        }

        if ($scheme === null) {
            $db->table('grading_schemes')->insert([
                'course_offering_id'  => null,
                'institution_id'      => $institutionId,
                'name'                => 'Institution default',
                'is_system_default'   => 0,
                'passing_percentage'  => 75,
                'status'              => 'Published',
                'needs_recalculation' => 0,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $schemeId = (int) $db->insertID();
        } else {
            $schemeId = (int) $scheme['id'];
            $db->table('grade_categories')->where('grading_scheme_id', $schemeId)->delete();
        }

        foreach ($categories as $i => $category) {
            $db->table('grade_categories')->insert([
                'grading_scheme_id' => $schemeId,
                'name'              => $category['name'],
                'weight_percentage' => $category['weight'],
                'sort_order'        => $i + 1,
                'created_at'        => $now,
                'updated_at'        => $now,
                'status'            => 'Active',
            ]);
        }
    }

    /**
     * @param mixed $names
     * @param mixed $weights
     *
     * @return list<array{name: string, weight: float}>
     *
     * @throws StructureException
     */
    private function categories(mixed $names, mixed $weights): array
    {
        $names   = is_array($names) ? array_values($names) : [];
        $weights = is_array($weights) ? array_values($weights) : [];

        $out  = [];
        $seen = [];
        $sum  = 0.0;

        foreach ($names as $i => $name) {
            $name   = trim((string) $name);
            $weight = trim((string) ($weights[$i] ?? ''));

            if ($name === '' && $weight === '') {
                continue;
            }

            if ($name === '' || mb_strlen($name) > 100) {
                throw StructureException::field('categories', 'Every category needs a name of up to 100 characters.');
            }

            if (isset($seen[mb_strtolower($name)])) {
                throw StructureException::field('categories', "{$name} is listed twice.");
            }

            if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $weight) !== 1 || (float) $weight <= 0 || (float) $weight > 100) {
                throw StructureException::field('categories', "The weight of {$name} must be a percentage above 0 and up to 100.");
            }

            $seen[mb_strtolower($name)] = true;
            $sum                       += (float) $weight;
            $out[]                      = ['name' => $name, 'weight' => (float) $weight];
        }

        if (count($out) > self::MAX_CATEGORIES) {
            throw StructureException::field('categories', 'Use at most ' . self::MAX_CATEGORIES . ' categories.');
        }

        if ($out !== [] && abs($sum - 100.0) > 0.01) {
            throw StructureException::field('categories', 'The weights add up to ' . rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.') . '%; they must add up to 100%.');
        }

        return $out;
    }

    /** @param mixed $value */
    private function decimal(mixed $value, string $field, float $min, float $max, string $message): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) !== 1 || (float) $value < $min || (float) $value > $max) {
            throw StructureException::field($field, $message);
        }

        return $value;
    }

    /** @param mixed $value */
    private function whole(mixed $value, string $field, int $min, int $max, string $message): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            throw StructureException::field($field, $message);
        }

        return (int) $value;
    }

    /**
     * @param mixed $value
     *
     * @throws StructureException
     */
    private function scale(mixed $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $found = ctype_digit($value) ? db_connect()->table('grade_scales')->where(['id' => (int) $value, 'status' => 'Active'])->countAllResults() : 0;

        if ($found === 0) {
            throw StructureException::field('grade_scale_id', 'Choose one of the listed grade scales.');
        }

        return (int) $value;
    }
}
