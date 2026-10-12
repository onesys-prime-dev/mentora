<?php

declare(strict_types=1);

namespace App\Services\Admin;

use CodeIgniter\Session\Session;
use CodeIgniter\Shield\Entities\User;

/**
 * Remembers that a person in an administrative area (the system administrator,
 * the institutional administrator) has just typed their password again.
 *
 * Dangerous actions (purge, restore, permission changes, closing a term, ...)
 * sit behind the `reauth` route filter. The filter lets a request through only while the
 * password was confirmed within the last WINDOW_SECONDS, so a session left
 * open on an unattended machine cannot be used to do damage.
 *
 * The clock is always passed in, so the boundaries are testable.
 */
class ReauthService
{
    /** How long a confirmed password stays good (a setting once the settings store is in use). */
    public const WINDOW_SECONDS = 300;

    /** Wrong passwords in a row before the session is ended. */
    public const MAX_ATTEMPTS = 5;

    private const CONFIRMED = 'reauth';

    private const FAILURES = 'reauth_failures';

    private const RETURN_TO = 'reauth_return';

    private Session $session;

    public function __construct(?Session $session = null)
    {
        $this->session = $session ?? session();
    }

    /** True when this user confirmed their password within the window. */
    public function isFresh(User $user, int $now): bool
    {
        $confirmed = $this->session->get(self::CONFIRMED);

        if (! is_array($confirmed) || ($confirmed['user'] ?? null) !== $user->id) {
            return false;
        }

        $age = $now - (int) ($confirmed['at'] ?? 0);

        return $age >= 0 && $age < self::WINDOW_SECONDS;
    }

    /** Checks the typed password against the account and, if right, starts a fresh window. */
    public function confirm(User $user, string $password, int $now): bool
    {
        $result = auth('session')->check(['email' => $user->email, 'password' => $password]);

        if (! $result->isOK()) {
            $this->session->set(self::FAILURES, $this->failures() + 1);

            return false;
        }

        $this->session->set(self::CONFIRMED, ['user' => $user->id, 'at' => $now]);
        $this->session->remove(self::FAILURES);

        return true;
    }

    /** @phpstan-impure reads session state that confirm() changes */
    public function failures(): int
    {
        return (int) $this->session->get(self::FAILURES);
    }

    /** @phpstan-impure reads session state that confirm() changes */
    public function tooManyFailures(): bool
    {
        return $this->failures() >= self::MAX_ATTEMPTS;
    }

    /** Remembers the page to return to after the password is confirmed. */
    public function rememberReturn(string $path): void
    {
        // An address outside the areas is stored as nothing, so pulling it falls back to the caller's own home.
        $this->session->set(self::RETURN_TO, self::safeReturn($path, ''));
    }

    /** The page to go back to (once), always inside an administrative area. */
    public function pullReturn(string $fallback = 'admin/dashboard'): string
    {
        $path = (string) $this->session->get(self::RETURN_TO);
        $this->session->remove(self::RETURN_TO);

        return self::safeReturn($path, $fallback);
    }

    /** The areas that have guarded actions, and so may be returned to. */
    public const AREAS = ['admin', 'institution'];

    /**
     * Only a relative address inside an administrative area may be returned
     * to, so the confirm page can never be used to bounce someone to another
     * site. Anything else (and the confirm pages themselves) goes to $fallback.
     */
    public static function safeReturn(string $path, string $fallback = 'admin/dashboard'): string
    {
        $path = ltrim($path, '/');

        $first = explode('/', $path)[0];

        if (! in_array($first, self::AREAS, true) || str_contains($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path) === 1) {
            return $fallback;
        }

        if ($path === 'admin/reauth' || $path === 'institution/reauth') {
            return $fallback;
        }

        return $path;
    }
}
