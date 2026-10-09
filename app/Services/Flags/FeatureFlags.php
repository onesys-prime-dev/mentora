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

        $rows = db_connect()->table('feature_flag_overrides')
            ->select('flag_key, institution_id, enabled, ends_on')
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

        $granted = $institutionId === null ? [] : $this->planFeatures($institutionId);

        $result = [];

        foreach ($definitions as $key => $definition) {
            $result[$key] = FeatureFlagResolver::resolve(
                $global[$key] ?? null,
                $override[$key] ?? null,
                (bool) $definition['default'],
                in_array($key, $granted, true),
                $now,
            );
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
