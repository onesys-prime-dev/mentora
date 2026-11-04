<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Admin\PlatformSettings;
use Throwable;

/**
 * The institution's switch for student-to-student messages (spec section 4,
 * call 7). On by default. Off: no new student-to-student thread can start and
 * the existing ones stay readable but nobody posts in them. Student-to-
 * instructor messages and every other pair are not affected.
 *
 * Stored like the parent portal switch, as an institution value that is only
 * ever saved when it is off, so "on" is the default and switching on again
 * removes the value. The settings store audits each change.
 */
final class StudentMessaging
{
    private const SETTING = 'studentDmEnabled';

    public function __construct(private readonly PlatformSettings $settings = new PlatformSettings())
    {
    }

    /** A switch that cannot be read counts as off: the safer answer for private messages. */
    public function enabled(int $institutionId): bool
    {
        try {
            return (bool) $this->settings->get(self::SETTING, $institutionId);
        } catch (Throwable $e) {
            log_message('error', 'Could not read the student messages switch: ' . $e->getMessage());

            return false;
        }
    }

    public function set(int $institutionId, bool $on): void
    {
        $on ? $this->settings->forget(self::SETTING, $institutionId) : $this->settings->set(self::SETTING, false, $institutionId);
    }
}
