<?php

declare(strict_types=1);

namespace App\Services\Admin;

use DateTimeZone;
use InvalidArgumentException;

/**
 * The general settings page's rules (SA12): reading the values and saving the
 * set after checking it. Each changed value is audited by PlatformSettings.
 * This includes the default expiry timeline and the backup alert age, which
 * live in the same store as the rest.
 */
class GeneralSettings
{
    private const TEXT_KEYS = ['systemName', 'supportContact', 'timezone', 'maintenanceMessage', 'announcement'];

    private const INT_KEYS = ['expiryWarningDays', 'expiryGraceDays', 'expiryReadOnlyDays', 'backupAlertHours'];

    public function __construct(private readonly PlatformSettings $settings = new PlatformSettings())
    {
    }

    /** @return array<string, bool|int|string> */
    public function all(): array
    {
        $values = [];

        foreach ([...self::TEXT_KEYS, ...self::INT_KEYS, 'maintenanceMode'] as $key) {
            $values[$key] = $this->settings->get($key);
        }

        return $values;
    }

    /**
     * Saves the whole page. Nothing is saved if anything is wrong.
     *
     * @param array<string, mixed> $input
     *
     * @throws InvalidArgumentException saying which value is wrong
     */
    public function save(array $input): void
    {
        $definitions = $this->settings->definitions();
        $clean       = [];

        foreach (self::TEXT_KEYS as $key) {
            $value = $input[$key] ?? '';

            if (! is_string($value)) {
                throw new InvalidArgumentException("{$definitions[$key]['label']}: enter text.");
            }

            try {
                $clean[$key] = PlatformSettings::coerce($definitions[$key], $value);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("{$definitions[$key]['label']}: " . lcfirst($e->getMessage()));
            }
        }

        if ($clean['systemName'] === '') {
            throw new InvalidArgumentException('System name: enter a name.');
        }

        if (! in_array($clean['timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Timezone: choose one from the list.');
        }

        if ($clean['maintenanceMessage'] === '') {
            throw new InvalidArgumentException('Maintenance message: say what people should be told while it is on.');
        }

        foreach (self::INT_KEYS as $key) {
            $value = $input[$key] ?? null;

            if (! is_scalar($value)) {
                throw new InvalidArgumentException("{$definitions[$key]['label']}: enter a number.");
            }

            try {
                $clean[$key] = PlatformSettings::coerce($definitions[$key], $value);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("{$definitions[$key]['label']}: " . lcfirst($e->getMessage()));
            }
        }

        $clean['maintenanceMode'] = ($input['maintenanceMode'] ?? '') === '1';

        foreach ($clean as $key => $value) {
            $this->settings->set($key, $value);
        }
    }
}
