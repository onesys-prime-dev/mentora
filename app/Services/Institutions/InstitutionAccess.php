<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Subscriptions\EffectiveStatus;

/**
 * Whether the people of an institution may change things (SA13).
 *
 * An institution whose subscription has run into its read-only period
 * (`read_only`), one that has ended or been suspended (`past`), and one that
 * has been deleted all keep their data, and their people can still sign in,
 * look and export, but nothing can be changed. The read-only filter uses this
 * to refuse writes, and the app layout uses it to show the banner.
 *
 * The status is the effective one (EffectiveStatus): the subscription dates
 * count even before the nightly job has moved the stored status (decision 6.1).
 * An account belongs to one institution (decision D1), so that institution
 * decides. A user who belongs to none (a system administrator) is never
 * restricted.
 */
class InstitutionAccess
{
    public const WRITABLE = 'writable';

    public const READ_ONLY = 'read_only';

    public const PAST = 'past';

    /** One of WRITABLE, READ_ONLY or PAST. */
    public function stateFor(int $userId): string
    {
        // Worked out afresh each time, never remembered between requests: a renewal or a
        // suspension must take effect on the very next click, even in a long-running worker.
        return $this->work($userId);
    }

    public function canWrite(int $userId): bool
    {
        return $this->stateFor($userId) === self::WRITABLE;
    }

    private function work(int $userId): string
    {
        $row = db_connect()->table('institution_users')
            ->select('institutions.id, institutions.deleted_at')
            ->join('institutions', 'institutions.id = institution_users.institution_id')
            ->where('institution_users.user_id', $userId)
            ->get()->getRowArray();

        if ($row === null) {
            return self::WRITABLE;
        }

        if ($row['deleted_at'] !== null) {
            return self::PAST;
        }

        return match (EffectiveStatus::of((int) $row['id'])) {
            'past'      => self::PAST,
            'read_only' => self::READ_ONLY,
            default     => self::WRITABLE,
        };
    }
}
