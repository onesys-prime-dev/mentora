<?php

declare(strict_types=1);

namespace App\Services\Flags;

use Config\FeatureFlags as FlagConfig;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Is this feature on for this institution? (SA7)
 *
 * The flags are listed in Config\FeatureFlags; what has been changed is in
 * the feature_flag_overrides table; the plan an institution is on may grant
 * a flag; and FeatureFlagResolver puts those together. This class reads them
 * from the database and asks the resolver. It is the one place server code
 * (and, through the page, browser code) asks.
 *
 * Asking about a flag that is not listed is a mistake in the code asking, so
 * it throws rather than quietly answering "off".
 */
class FeatureFlags
{
    /**
     * Every flag that exists, with its description and code default.
     *
     * @return array<string, array{description: string, default: bool}>
     */
    public function definitions(): array
    {
        return config(FlagConfig::class)->flags;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    /**
     * @param ?int $institutionId null for a system administrator or anyone outside an institution
     *
     * @throws InvalidArgumentException for a flag that is not listed
     */
    public function isEnabled(string $key, ?int $institutionId, ?DateTimeImmutable $now = null): bool
    {
        return $this->explain($key, $institutionId, $now)['enabled'];
    }

    /**
     * The answer and why, for the flags page.
     *
     * @return array{enabled: bool, reason: string}
     *
     * @throws InvalidArgumentException for a flag that is not listed
     */
    public function explain(string $key, ?int $institutionId, ?DateTimeImmutable $now = null): array
    {
        $definitions = $this->definitions();

        if (! isset($definitions[$key])) {
            throw new InvalidArgumentException("Unknown feature flag \"{$key}\".");
        }

        return $this->resolveAll($institutionId, $now ?? new DateTimeImmutable(), [$key => $definitions[$key]])[$key];
    }

    /**
     * Every flag's answer for an institution.
     *
     * @return array<string, bool>
     */
    public function enabledFor(?int $institutionId, ?DateTimeImmutable $now = null): array
    {
        $result = [];

        foreach ($this->resolveAll($institutionId, $now ?? new DateTimeImmutable(), $this->definitions()) as $key => $answer) {
            $result[$key] = $answer['enabled'];
        }

        return $result;
    }

    /**
     * Everything that can say something about each flag, for one institution
     * (or for nobody, with null): the global row, the institution's own row,
     * and whether its plan grants the flag.
     *
     * @param list<string> $keys
     *
     * @return array{global: array<string, array<string, mixed>>, override: array<string, array<string, mixed>>, granted: list<string>}
     */
    private function gather(?int $institutionId, array $keys): array
    {
        $rows = db_connect()->table('feature_flag_overrides')
            ->whereIn('flag_key', $keys)
            ->groupStart()
                ->where('institution_id IS NULL', null, false);

        if ($institutionId !== null) {
            $rows->orWhere('institution_id', $institutionId);
        }

        $rows = $rows->groupEnd()->get()->getResultArray();

        $global   = [];
        $override = [];

        foreach ($rows as $row) {
            if ($row['institution_id'] === null) {
                $global[$row['flag_key']] = $row;
            } else {
                $override[$row['flag_key']] = $row;
            }
        }

        return ['global' => $global, 'override' => $override, 'granted' => $institutionId === null ? [] : $this->planFeatures($institutionId)];
    }

    /**
     * @param array<string, array{description: string, default: bool}> $definitions
     *
     * @return array<string, array{enabled: bool, reason: string}>
     */
    private function resolveAll(?int $institutionId, DateTimeImmutable $now, array $definitions): array
    {
        $keys = array_keys($definitions);

        if ($keys === []) {
            return [];
        }

        $found  = $this->gather($institutionId, $keys);
        $result = [];

        foreach ($definitions as $key => $definition) {
            $result[$key] = FeatureFlagResolver::resolve(
                $found['global'][$key] ?? null,
                $found['override'][$key] ?? null,
                (bool) $definition['default'],
                in_array($key, $found['granted'], true),
                $now,
            );
        }

        return $result;
    }

    /**
     * Each flag's answer for an institution together with what lies behind
     * it, for the flags pages: why it is on or off, the global row and the
     * institution's own row (each shown even if it has ended, with `ended`
     * saying so), and whether the plan grants it.
     *
     * @return array<string, array{enabled: bool, reason: string, global: ?array<string, mixed>, override: ?array<string, mixed>, plan: bool, default: bool}>
     */
    public function inspect(?int $institutionId, ?DateTimeImmutable $now = null): array
    {
        $now         = $now ?? new DateTimeImmutable();
        $definitions = $this->definitions();
        $keys        = array_keys($definitions);

        if ($keys === []) {
            return [];
        }

        $found    = $this->gather($institutionId, $keys);
        $answers  = $this->resolveAll($institutionId, $now, $definitions);
        $today    = $now->format('Y-m-d');
        $withEnd  = static function (?array $row) use ($today): ?array {
            if ($row === null) {
                return null;
            }

            $row['ended'] = $row['ends_on'] !== null && $row['ends_on'] < $today;

            return $row;
        };

        $result = [];

        foreach ($definitions as $key => $definition) {
            $result[$key] = $answers[$key] + [
                'global'   => $withEnd($found['global'][$key] ?? null),
                'override' => $withEnd($found['override'][$key] ?? null),
                'plan'     => in_array($key, $found['granted'], true),
                'default'  => (bool) $definition['default'],
            ];
        }

        return $result;
    }

    /**
     * How many institutions (not deleted) have each flag on, and how many
     * have their own override in force. For the flags list.
     *
     * @return array<string, array{on: int, total: int, overrides: int}>
     */
    public function adoption(?DateTimeImmutable $now = null): array
    {
        $now         = $now ?? new DateTimeImmutable();
        $today       = $now->format('Y-m-d');
        $definitions = $this->definitions();
        $keys        = array_keys($definitions);
        $db          = db_connect();

        $institutions = array_map('intval', array_column($db->table('institutions')->select('id')->where('deleted_at IS NULL', null, false)->get()->getResultArray(), 'id'));

        $global = [];
        $own    = [];

        foreach ($keys === [] ? [] : $db->table('feature_flag_overrides')->whereIn('flag_key', $keys)->get()->getResultArray() as $row) {
            if ($row['institution_id'] === null) {
                $global[$row['flag_key']] = $row;
            } else {
                $own[(int) $row['institution_id']][$row['flag_key']] = $row;
            }
        }

        $plans = [];

        foreach ($db->table('subscriptions')->select('subscriptions.institution_id, plans.features')->join('plans', 'plans.id = subscriptions.plan_id')->get()->getResultArray() as $row) {
            $decoded                              = json_decode((string) $row['features'], true);
            $plans[(int) $row['institution_id']] = is_array($decoded) ? array_map('strval', $decoded) : [];
        }

        $result = [];

        foreach ($definitions as $key => $definition) {
            $on        = 0;
            $overrides = 0;

            foreach ($institutions as $id) {
                $row = $own[$id][$key] ?? null;

                if ($row !== null && ($row['ends_on'] === null || $row['ends_on'] >= $today)) {
                    $overrides++;
                }

                $answer = FeatureFlagResolver::resolve($global[$key] ?? null, $row, (bool) $definition['default'], in_array($key, $plans[$id] ?? [], true), $now);

                if ($answer['enabled']) {
                    $on++;
                }
            }

            $result[$key] = ['on' => $on, 'total' => count($institutions), 'overrides' => $overrides];
        }

        return $result;
    }

    /**
     * The feature keys the institution's plan grants. None without a subscription.
     *
     * @return list<string>
     */
    private function planFeatures(int $institutionId): array
    {
        $row = db_connect()->table('subscriptions')
            ->select('plans.features')
            ->join('plans', 'plans.id = subscriptions.plan_id')
            ->where('subscriptions.institution_id', $institutionId)
            ->get()->getRowArray();

        $decoded = json_decode((string) ($row['features'] ?? ''), true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
