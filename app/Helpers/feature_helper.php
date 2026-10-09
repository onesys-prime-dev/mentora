<?php

use App\Services\Flags\FeatureFlags;
use App\Services\Subscriptions\PlanLimits;

if (! function_exists('current_features')) {

    /**
     * Every feature flag as the signed-in person sees it: on or off for their
     * institution. Someone outside an institution (a system administrator) gets
     * the answers that need no institution. A failure to work them out gives an
     * empty list rather than a broken page.
     *
     * @return array<string, bool>
     */
    function current_features(): array
    {
        try {
            $user          = auth()->user();
            $institutionId = $user === null ? null : (new PlanLimits())->institutionOf((int) $user->id);

            return (new FeatureFlags())->enabledFor($institutionId);
        } catch (Throwable $e) {
            log_message('error', 'Could not work out the feature flags: ' . $e->getMessage());

            return [];
        }
    }
}

if (! function_exists('feature_enabled')) {

    /**
     * Whether a feature flag is on for the signed-in person's institution.
     * An unknown flag is a mistake in the calling code and throws.
     *
     * @throws InvalidArgumentException
     */
    function feature_enabled(string $key): bool
    {
        $user          = auth()->user();
        $institutionId = $user === null ? null : (new PlanLimits())->institutionOf((int) $user->id);

        return (new FeatureFlags())->isEnabled($key, $institutionId);
    }
}
