<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Admin\PlatformSettings;
use InvalidArgumentException;
use Throwable;

/**
 * Who a student can start a message with, set by the institution (D3): `off`, `course` (classmates only) or
 * `institution` (default). Off: no new student-to-student thread can start and the existing ones stay
 * readable but nobody posts in them. Student-to-instructor messages and every other pair are not affected.
 *
 * Stored as an institution value that is only saved when it is not the default, so "institution" removes it.
 * The settings store audits each change.
 *
 * Until Phase 7, enabled() is `scope !== 'off'` for every existing caller: 'course' is stored and shown but
 * its reach rule (classmates only) lands in Phase 7, so today it behaves like 'institution'.
 */
final class StudentMessaging
{
    public const SCOPES = ['off', 'course', 'institution'];

    private const SETTING = 'studentMessagingScope';

    public function __construct(private readonly PlatformSettings $settings = new PlatformSettings())
    {
    }

    /** A scope that cannot be read counts as off: the safer answer for private messages. */
    public function scope(int $institutionId): string
    {
        try {
            return (string) $this->settings->get(self::SETTING, $institutionId);
        } catch (Throwable $e) {
            log_message('error', 'Could not read the student messages scope: ' . $e->getMessage());

            return 'off';
        }
    }

    public function enabled(int $institutionId): bool
    {
        return $this->scope($institutionId) !== 'off';
    }

    /** @throws InvalidArgumentException for a scope that is not one of SCOPES */
    public function setScope(int $institutionId, string $scope): void
    {
        $scope === 'institution' ? $this->settings->forget(self::SETTING, $institutionId) : $this->settings->set(self::SETTING, $scope, $institutionId);
    }

    public function set(int $institutionId, bool $on): void
    {
        $this->setScope($institutionId, $on ? 'institution' : 'off');
    }
}
