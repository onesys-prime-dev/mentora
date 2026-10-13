<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Admin\PlatformSettings;
use App\Services\Security\SecuritySettings;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;
use InvalidArgumentException;

/**
 * An institution's security settings, which can only make things stricter
 * than the platform's.
 *
 * Per setting, "stricter" is:
 *  - shortest password: longer;
 *  - sign out after inactivity: fewer minutes;
 *  - failed sign-ins before a lockout: fewer (and any number is stricter
 *    than the platform having none, 0);
 *  - lockout length: longer;
 *  - roles that need an emailed code: more roles (the platform's stay).
 *
 * The value in force for an institution's people is the stricter of the two,
 * worked out here and nowhere else (the password policy, the idle sign-out,
 * the lockout and the two-factor step all ask this class). A looser value is
 * refused when saved, naming the platform's; a value equal to the platform's
 * is not stored, so the institution follows the platform if it tightens.
 * Changes are audited by PlatformSettings with old and new values.
 */
class InstitutionSecurity
{
    /** The numeric settings an institution may tighten, in page order. */
    public const NUMERIC = ['passwordMinLength', 'sessionTimeoutMinutes', 'lockoutAttempts', 'lockoutMinutes'];

    /** @var array<string, array<string, mixed>> */
    private static array $memo = [];

    public function __construct(private readonly PlatformSettings $settings = new PlatformSettings())
    {
    }

    /**
     * The stricter of the platform's and the institution's value.
     *
     * @param 'passwordMinLength'|'sessionTimeoutMinutes'|'lockoutAttempts'|'lockoutMinutes' $key
     */
    public static function stricter(string $key, int $platform, ?int $institution): int
    {
        if ($institution === null) {
            return $platform;
        }

        return match ($key) {
            'passwordMinLength', 'lockoutMinutes' => max($platform, $institution),
            'sessionTimeoutMinutes'               => min($platform, $institution),
            // 0 is "never lock": the loosest there is.
            'lockoutAttempts'                     => $platform === 0 ? $institution : ($institution === 0 ? $platform : min($platform, $institution)),
            default                               => $platform,
        };
    }

    /**
     * Whether $institution is at least as strict as $platform for $key.
     *
     * @param 'passwordMinLength'|'sessionTimeoutMinutes'|'lockoutAttempts'|'lockoutMinutes' $key
     */
    public static function isStricterOrEqual(string $key, int $platform, int $institution): bool
    {
        return self::stricter($key, $platform, $institution) === $institution;
    }

    /**
     * What an institution has set of its own, and what is in force.
     *
     * @return array{platform: array<string, int|list<string>>, own: array<string, ?int>, ownRoles: list<string>, effective: array<string, int|list<string>>}
     */
    public function forInstitution(int $institutionId): array
    {
        $platform = (new SecuritySettings($this->settings))->all();

        $own = [];

        foreach (self::NUMERIC as $key) {
            $own[$key] = $this->settings->hasOverride($key, $institutionId) ? (int) $this->settings->get($key, $institutionId) : null;
        }

        $ownRoles = $this->settings->hasOverride('twoFactorRoles', $institutionId)
            ? $this->roles((string) $this->settings->get('twoFactorRoles', $institutionId))
            : [];

        $effective = [];

        foreach (self::NUMERIC as $key) {
            $effective[$key] = self::stricter($key, (int) $platform[$key], $own[$key]);
        }

        $effective['twoFactorRoles'] = array_values(array_unique(array_merge((array) $platform['twoFactorRoles'], $ownRoles)));

        return ['platform' => $platform, 'own' => $own, 'ownRoles' => $ownRoles, 'effective' => $effective];
    }

    /**
     * What is in force for a person's institution (the platform's for someone
     * who belongs to none, a system administrator for one).
     *
     * @return array<string, int|list<string>>
     */
    public function forUser(?User $user): array
    {
        $key = ($user === null ? 0 : (int) $user->id) . ':' . spl_object_id(service('request'));

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        self::$memo = [];

        $institutionId = $user === null ? null : (new PlanLimits())->institutionOf((int) $user->id);

        if ($institutionId === null) {
            return self::$memo[$key] = $this->forInstitutionLess();
        }

        return self::$memo[$key] = $this->forInstitution($institutionId)['effective'];
    }

    /**
     * What is in force for the institution of the account that signs in with
     * this email (the lockout works before anyone is signed in).
     *
     * @return array<string, int|list<string>>
     */
    public function forEmail(string $email): array
    {
        $email = strtolower(trim($email));

        $row = $email === '' ? null : db_connect()->table('auth_identities')
            ->select('user_id')
            ->where('type', 'email_password')
            ->where('LOWER(secret)', $email)
            ->get()->getRowArray();

        if ($row === null) {
            return $this->forInstitutionLess();
        }

        $institutionId = (new PlanLimits())->institutionOf((int) $row['user_id']);

        return $institutionId === null ? $this->forInstitutionLess() : $this->forInstitution($institutionId)['effective'];
    }

    /**
     * Saves an institution's settings. A blank value means "use the
     * platform's". Nothing is saved when anything is refused.
     *
     * @param array<string, mixed> $input passwordMinLength, sessionTimeoutMinutes, lockoutAttempts, lockoutMinutes (blank or a number), twoFactorRoles (list)
     *
     * @throws StructureException
     */
    public function save(int $institutionId, array $input): void
    {
        $platform = (new SecuritySettings($this->settings))->all();
        $new      = [];

        foreach (self::NUMERIC as $key) {
            $raw = trim((string) ($input[$key] ?? ''));

            if ($raw === '') {
                $new[$key] = null;

                continue;
            }

            $label = $this->settings->definitions()[$key]['label'];

            try {
                $value = (int) PlatformSettings::coerce($this->settings->definitions()[$key], $raw);
            } catch (InvalidArgumentException $e) {
                throw StructureException::field($key, "{$label}: " . lcfirst($e->getMessage()));
            }

            $mine = (int) $platform[$key];

            if (! self::isStricterOrEqual($key, $mine, $value)) {
                throw StructureException::field($key, $this->looser($key, $mine));
            }

            $new[$key] = $value === $mine ? null : $value;
        }

        $roles = array_values(array_filter((array) ($input['twoFactorRoles'] ?? []), 'is_string'));

        foreach ($roles as $role) {
            if (! in_array($role, SecuritySettings::TWO_FACTOR_CHOICES, true)) {
                throw StructureException::field('twoFactorRoles', 'That is not a role that can be asked for a sign-in code.');
            }
        }

        // The platform's roles stay on whatever is ticked; only extra ones are the institution's.
        $extra = array_values(array_diff(array_unique($roles), (array) $platform['twoFactorRoles']));

        foreach ($new as $key => $value) {
            $value === null ? $this->settings->forget($key, $institutionId) : $this->settings->set($key, $value, $institutionId);
        }

        $extra === [] ? $this->settings->forget('twoFactorRoles', $institutionId) : $this->settings->set('twoFactorRoles', implode(',', $extra), $institutionId);

        self::$memo = [];
    }

    /** Back to the platform's values for everything. */
    public function reset(int $institutionId): void
    {
        foreach ([...self::NUMERIC, 'twoFactorRoles'] as $key) {
            $this->settings->forget($key, $institutionId);
        }

        self::$memo = [];
    }

    /** For tests and long-running workers. */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @return array<string, int|list<string>> */
    private function forInstitutionLess(): array
    {
        return (new SecuritySettings($this->settings))->all();
    }

    /** @return list<string> */
    private function roles(string $csv): array
    {
        return array_values(array_intersect(array_filter(array_map('trim', explode(',', $csv))), SecuritySettings::TWO_FACTOR_CHOICES));
    }

    private function looser(string $key, int $platform): string
    {
        return match ($key) {
            'passwordMinLength'     => "The platform already requires {$platform} characters; an institution can only ask for more.",
            'sessionTimeoutMinutes' => "The platform already signs people out after {$platform} minutes; an institution can only choose fewer.",
            'lockoutAttempts'       => $platform === 0
                ? 'Choose a number of failed sign-ins from 1 up.'
                : "The platform already locks sign-in after {$platform} failed attempts; an institution can only choose fewer (and not 0, which means never).",
            'lockoutMinutes'        => "The platform already locks for {$platform} minutes; an institution can only choose longer.",
            default                 => 'That is looser than the platform allows.',
        };
    }
}
