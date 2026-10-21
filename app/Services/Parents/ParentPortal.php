<?php

declare(strict_types=1);

namespace App\Services\Parents;

use App\Services\Admin\PlatformSettings;
use App\Services\Flags\FeatureFlags;
use Throwable;

/**
 * Is the parent portal on for an institution?
 *
 * It is on only when Mentora's `parent.portal` flag is on for the institution
 * AND the institution's administrator has not switched it off. The
 * administrator can only switch it off (and on again): they can never turn on
 * what Mentora has off. A switch that cannot be read means off, so parents'
 * data is never shown on a guess.
 */
class ParentPortal
{
    private const FLAG    = 'parent.portal';
    private const SETTING = 'parentPortalEnabled';

    public function __construct(
        private readonly FeatureFlags $flags = new FeatureFlags(),
        private readonly PlatformSettings $settings = new PlatformSettings(),
    ) {
    }

    public function enabled(?int $institutionId): bool
    {
        if ($institutionId === null) {
            return false;
        }

        try {
            return $this->platformOn($institutionId) && ! $this->institutionSwitchedOff($institutionId);
        } catch (Throwable $e) {
            log_message('error', 'Could not read the parent portal switch: ' . $e->getMessage());

            return false;
        }
    }

    /** Mentora's side only: the `parent.portal` flag for the institution (false when it cannot be read). */
    public function platformOn(int $institutionId): bool
    {
        try {
            return $this->flags->isEnabled(self::FLAG, $institutionId);
        } catch (Throwable $e) {
            log_message('error', 'Could not read the parent.portal flag: ' . $e->getMessage());

            return false;
        }
    }

    /** The institution's own switch, whatever Mentora's flag says. */
    public function institutionSwitchedOff(int $institutionId): bool
    {
        return ! (bool) $this->settings->get(self::SETTING, $institutionId);
    }

    /** Saves the institution's switch (audited by the settings store). */
    public function setInstitutionOn(int $institutionId, bool $on): void
    {
        $on ? $this->settings->forget(self::SETTING, $institutionId) : $this->settings->set(self::SETTING, false, $institutionId);
    }
}
