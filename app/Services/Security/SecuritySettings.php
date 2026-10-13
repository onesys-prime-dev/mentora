<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Admin\PlatformSettings;
use InvalidArgumentException;

/**
 * The security settings page's rules (SA8): reading the values, and saving
 * them as a set after checking them. Each saved value is audited by
 * PlatformSettings.
 */
class SecuritySettings
{
    /** Roles that can be asked for an emailed code at sign-in. */
    public const TWO_FACTOR_CHOICES = ['institutional_admin', 'chairperson', 'instructor', 'student', 'parent'];

    private const INT_KEYS = ['passwordMinLength', 'sessionTimeoutMinutes', 'lockoutAttempts', 'lockoutMinutes'];

    public function __construct(private readonly PlatformSettings $settings = new PlatformSettings())
    {
    }

    /** @return array<string, int|list<string>> every value in force, with the roles as a list under `twoFactorRoles` */
    public function all(): array
    {
        $values = [];

        foreach (self::INT_KEYS as $key) {
            $values[$key] = (int) $this->settings->get($key);
        }

        $values['twoFactorRoles'] = $this->twoFactorRoles();

        return $values;
    }

    /**
     * The roles that sign in with an emailed code. System administrators are
     * always among them: the sign-in of that role is never down to a setting.
     *
     * @return non-empty-list<string>
     */
    public function twoFactorRoles(): array
    {
        $stored = array_filter(array_map('trim', explode(',', (string) $this->settings->get('twoFactorRoles'))));
        $roles  = array_values(array_unique(array_merge(['admin'], array_intersect($stored, self::TWO_FACTOR_CHOICES))));

        return $roles;
    }

    /**
     * Saves the whole page.
     *
     * @param array<string, mixed> $input passwordMinLength, sessionTimeoutMinutes, lockoutAttempts, lockoutMinutes, twoFactorRoles (list)
     *
     * @throws InvalidArgumentException saying which value is wrong; nothing is saved then
     */
    public function save(array $input): void
    {
        $ints = [];

        foreach (self::INT_KEYS as $key) {
            $label = $this->settings->definitions()[$key]['label'];

            if (! isset($input[$key]) || ! is_scalar($input[$key])) {
                throw new InvalidArgumentException("{$label}: enter a number.");
            }

            try {
                $ints[$key] = PlatformSettings::coerce($this->settings->definitions()[$key], $input[$key]);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("{$label}: " . lcfirst($e->getMessage()));
            }
        }

        $roles = array_values(array_filter((array) ($input['twoFactorRoles'] ?? []), 'is_string'));

        foreach ($roles as $role) {
            if (! in_array($role, self::TWO_FACTOR_CHOICES, true)) {
                throw new InvalidArgumentException('That is not a role that can be asked for a sign-in code.');
            }
        }

        foreach ($ints as $key => $value) {
            $this->settings->set($key, $value);
        }

        $this->settings->set('twoFactorRoles', implode(',', array_values(array_unique(array_merge(['admin'], $roles)))));
    }
}
