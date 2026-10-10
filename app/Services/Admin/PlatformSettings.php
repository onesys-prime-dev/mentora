<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Config\Platform;
use InvalidArgumentException;

/**
 * Typed, validated access to the platform settings (SA2).
 *
 * Values live in the `settings` table through codeigniter4/settings, which
 * already gives a global value, an optional per-context override and a
 * fallback to the default in Config\Platform. This class adds what that
 * package does not: only known keys exist, every value is checked against its
 * type and limits on the way in (and again on the way out), and an
 * institution may override only the keys marked for it.
 *
 * Every write goes through set() or forget(), and each one that changes the
 * value in force leaves an audit entry (settings.changed / settings.reset).
 */
class PlatformSettings
{
    /**
     * Known settings. `type` is int, bool or string; `min`/`max` bound an int,
     * `maxLength` bounds a string; `institution` says whether one institution
     * may hold its own value.
     *
     * @var array<string, array{type: string, label: string, institution: bool, min?: int, max?: int, maxLength?: int}>
     */
    private const DEFINITIONS = [
        'auditRetentionDays'   => ['type' => 'int', 'label' => 'Audit log retention (days)', 'institution' => false, 'min' => 30, 'max' => 3650],
        'deletedInstitutionRetentionDays' => ['type' => 'int', 'label' => 'Days a deleted institution can be restored', 'institution' => false, 'min' => 1, 'max' => 3650],
        'auditShowSourceIp'    => ['type' => 'bool', 'label' => 'Show source IP in the audit log', 'institution' => false],
        'impersonationMinutes' => ['type' => 'int', 'label' => 'Impersonation time limit (minutes)', 'institution' => false, 'min' => 5, 'max' => 240],
        'backupAlertHours'     => ['type' => 'int', 'label' => 'Backup alert age (hours)', 'institution' => false, 'min' => 1, 'max' => 720],
        'expiryWarningDays'    => ['type' => 'int', 'label' => 'Expiry warning window (days)', 'institution' => true, 'min' => 0, 'max' => 365],
        'expiryGraceDays'      => ['type' => 'int', 'label' => 'Grace period (days)', 'institution' => true, 'min' => 0, 'max' => 365],
        'expiryReadOnlyDays'   => ['type' => 'int', 'label' => 'Read-only period (days)', 'institution' => true, 'min' => 0, 'max' => 365],
        'invitationValidityDays' => ['type' => 'int', 'label' => 'Days an invitation link works', 'institution' => false, 'min' => 1, 'max' => 60],
        'systemName'           => ['type' => 'string', 'label' => 'System name', 'institution' => false, 'maxLength' => 80],
        'supportContact'       => ['type' => 'string', 'label' => 'Support contact', 'institution' => false, 'maxLength' => 200],
        'timezone'             => ['type' => 'string', 'label' => 'Timezone', 'institution' => false, 'maxLength' => 64],
        'maintenanceMode'      => ['type' => 'bool', 'label' => 'Maintenance mode', 'institution' => false],
        'maintenanceMessage'   => ['type' => 'string', 'label' => 'Maintenance message', 'institution' => false, 'maxLength' => 500],
        'announcement'         => ['type' => 'string', 'label' => 'Announcement', 'institution' => false, 'maxLength' => 500],
        'passwordMinLength'    => ['type' => 'int', 'label' => 'Shortest password (characters)', 'institution' => false, 'min' => 8, 'max' => 64],
        'sessionTimeoutMinutes' => ['type' => 'int', 'label' => 'Sign out after inactivity (minutes)', 'institution' => false, 'min' => 5, 'max' => 120],
        'lockoutAttempts'      => ['type' => 'int', 'label' => 'Failed sign-ins before a lockout (0 = never)', 'institution' => false, 'min' => 0, 'max' => 50],
        'lockoutMinutes'       => ['type' => 'int', 'label' => 'Lockout length (minutes)', 'institution' => false, 'min' => 1, 'max' => 1440],
        'twoFactorRoles'       => ['type' => 'string', 'label' => 'Roles that need an emailed sign-in code', 'institution' => false, 'maxLength' => 200],
        'passwordResetValidityHours' => ['type' => 'int', 'label' => 'Hours a password reset link works', 'institution' => false, 'min' => 1, 'max' => 168],
    ];

    /** @return array<string, array{type: string, label: string, institution: bool, min?: int, max?: int, maxLength?: int}> */
    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * The value in force: the institution's own if it has one (and the key
     * allows it), else the global value, else the default.
     *
     * @return bool|int|string
     */
    public function get(string $name, ?int $institutionId = null)
    {
        $definition = $this->definition($name, $institutionId);
        $default    = config(Platform::class)->{$name};

        $stored = setting()->get($this->key($name), $this->context($institutionId));

        if ($stored === null) {
            return $default;
        }

        try {
            return self::coerce($definition, $stored);
        } catch (InvalidArgumentException) {
            // A value edited by hand into something unusable must not break
            // the pages that read it: use the default.
            return $default;
        }
    }

    /**
     * Saves a value after checking it.
     *
     * @param bool|int|string $value
     *
     * @throws InvalidArgumentException for an unknown key, a key an institution may not override, or an invalid value
     */
    public function set(string $name, $value, ?int $institutionId = null): void
    {
        $definition = $this->definition($name, $institutionId);
        $new        = self::coerce($definition, $value);
        $old        = $this->get($name, $institutionId);

        setting()->set($this->key($name), $new, $this->context($institutionId));

        if ($old !== $new) {
            $this->audit('settings.changed', $name, $institutionId, $old, $new);
        }
    }

    /** Removes a saved value so the next level down (global, then default) applies again. */
    public function forget(string $name, ?int $institutionId = null): void
    {
        $this->definition($name, $institutionId);

        $old = $this->get($name, $institutionId);

        setting()->forget($this->key($name), $this->context($institutionId));

        $new = $this->get($name, $institutionId);

        if ($old !== $new) {
            $this->audit('settings.reset', $name, $institutionId, $old, $new);
        }
    }

    /** True when this institution holds its own value for the key (not merely the global one). */
    public function hasOverride(string $name, int $institutionId): bool
    {
        $this->definition($name, $institutionId);

        // get() alone cannot tell "its own value" from "fell back to global",
        // so ask the store for the contextual row directly.
        return db_connect()
            ->table('settings')
            ->where('class', Platform::class)
            ->where('key', $name)
            ->where('context', $this->context($institutionId))
            ->countAllResults() > 0;
    }

    /**
     * Checks a value against a definition and returns it in the right type.
     *
     * @param array{type: string, label?: string, institution?: bool, min?: int, max?: int, maxLength?: int} $definition
     * @param mixed                                                                                        $value
     *
     * @return bool|int|string
     *
     * @throws InvalidArgumentException when the value is not acceptable
     */
    public static function coerce(array $definition, $value)
    {
        switch ($definition['type']) {
            case 'int':
                if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
                    $value = (int) trim($value);
                }

                if (! is_int($value)) {
                    throw new InvalidArgumentException('A whole number is required.');
                }

                if (isset($definition['min']) && $value < $definition['min']) {
                    throw new InvalidArgumentException("The value must be at least {$definition['min']}.");
                }

                if (isset($definition['max']) && $value > $definition['max']) {
                    throw new InvalidArgumentException("The value must be at most {$definition['max']}.");
                }

                return $value;

            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }

                $text = is_string($value) ? strtolower(trim($value)) : $value;

                if ($text === 1 || $text === '1' || $text === 'true') {
                    return true;
                }

                if ($text === 0 || $text === '0' || $text === 'false') {
                    return false;
                }

                throw new InvalidArgumentException('Yes or no is required.');

            case 'string':
                if (! is_string($value)) {
                    throw new InvalidArgumentException('Text is required.');
                }

                $value = trim($value);

                if (isset($definition['maxLength']) && mb_strlen($value) > $definition['maxLength']) {
                    throw new InvalidArgumentException("The text must be at most {$definition['maxLength']} characters.");
                }

                return $value;
        }

        throw new InvalidArgumentException("Unknown setting type {$definition['type']}.");
    }

    /**
     * @return array{type: string, label: string, institution: bool, min?: int, max?: int, maxLength?: int}
     *
     * @throws InvalidArgumentException
     */
    private function definition(string $name, ?int $institutionId): array
    {
        $definition = self::DEFINITIONS[$name] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown setting {$name}.");
        }

        if ($institutionId !== null && ! $definition['institution']) {
            throw new InvalidArgumentException("{$name} is a platform-wide setting; an institution cannot override it.");
        }

        return $definition;
    }

    /**
     * Every change to a setting is recorded with its old and new value, by
     * whoever is signed in. A failure to record is allowed to stop the change:
     * a setting must never change without a trace.
     *
     * @param bool|int|string $old
     * @param bool|int|string $new
     */
    private function audit(string $action, string $name, ?int $institutionId, $old, $new): void
    {
        service('auditLogger')->log($action, auth()->user(), [
            'target_type'    => 'setting',
            'target_id'      => $name,
            'institution_id' => $institutionId,
            'old'            => [$name => $old],
            'new'            => [$name => $new],
        ]);
    }

    private function key(string $name): string
    {
        return 'Platform.' . $name;
    }

    private function context(?int $institutionId): ?string
    {
        return $institutionId === null ? null : 'institution:' . $institutionId;
    }
}
