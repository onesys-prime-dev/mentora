<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Admin\PlatformSettings;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * One institution-wide banner, set by its administrator and shown to the
 * institution's own people under the platform's announcement.
 *
 *  - Plain text of up to 300 characters (it is printed escaped, never as
 *    markup).
 *  - Optionally shown only until a date (that whole day, in the platform's
 *    timezone); with none it stays until removed.
 *  - Stored per institution in the settings store, so it is audited like any
 *    other setting. System administrators belong to no institution and see
 *    only the platform's line.
 */
class InstitutionAnnouncement
{
    public const MAX_LENGTH = 300;

    /** @var array<string, ?string> */
    private static array $memo = [];

    public function __construct(private readonly PlatformSettings $settings = new PlatformSettings())
    {
    }

    /**
     * What an institution has set, whether or not it is showing now.
     *
     * @return array{text: string, until: string, showing: bool}
     */
    public function get(int $institutionId, ?DateTimeImmutable $now = null): array
    {
        $text  = $this->settings->hasOverride('institutionAnnouncement', $institutionId) ? (string) $this->settings->get('institutionAnnouncement', $institutionId) : '';
        $until = $this->settings->hasOverride('institutionAnnouncementUntil', $institutionId) ? (string) $this->settings->get('institutionAnnouncementUntil', $institutionId) : '';

        return ['text' => $text, 'until' => $until, 'showing' => $text !== '' && ! $this->ended($until, $now)];
    }

    /**
     * The text to show a person right now, or null.
     */
    public function forUser(?User $user, ?DateTimeImmutable $now = null): ?string
    {
        if ($user === null || $user->inGroup('admin')) {
            return null;
        }

        $key = (int) $user->id . ':' . spl_object_id(service('request'));

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        self::$memo = [];

        $institutionId = (new PlanLimits())->institutionOf((int) $user->id);
        $state         = $institutionId === null ? null : $this->get($institutionId, $now);

        return self::$memo[$key] = ($state !== null && $state['showing']) ? $state['text'] : null;
    }

    /**
     * @throws StructureException
     */
    public function save(int $institutionId, string $text, string $until): void
    {
        $text  = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $until = trim($until);

        if ($text === '') {
            $this->remove($institutionId);

            return;
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw StructureException::field('text', 'The announcement can be at most ' . self::MAX_LENGTH . ' characters.');
        }

        if (preg_match('/[<>]/', $text) === 1) {
            throw StructureException::field('text', 'The announcement is plain text: leave out < and >.');
        }

        if ($until !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $until);

            if ($date === false || $date->format('Y-m-d') !== $until) {
                throw StructureException::field('until', 'Choose the last day it is shown.');
            }

            if ($until < $this->today()->format('Y-m-d')) {
                throw StructureException::field('until', 'That day has already passed.');
            }
        }

        $this->settings->set('institutionAnnouncement', $text, $institutionId);

        $until === '' ? $this->settings->forget('institutionAnnouncementUntil', $institutionId) : $this->settings->set('institutionAnnouncementUntil', $until, $institutionId);

        self::$memo = [];
    }

    public function remove(int $institutionId): void
    {
        $this->settings->forget('institutionAnnouncement', $institutionId);
        $this->settings->forget('institutionAnnouncementUntil', $institutionId);

        self::$memo = [];
    }

    /** For tests and long-running workers. */
    public static function forget(): void
    {
        self::$memo = [];
    }

    private function ended(string $until, ?DateTimeImmutable $now): bool
    {
        if ($until === '') {
            return false;
        }

        return ($now ?? $this->today())->format('Y-m-d') > $until;
    }

    private function today(): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable('now', new DateTimeZone((string) $this->settings->get('timezone')));
        } catch (Throwable) {
            return new DateTimeImmutable();
        }
    }
}
