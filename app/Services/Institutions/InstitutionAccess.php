<?php

declare(strict_types=1);

namespace App\Services\Institutions;

/**
 * Whether the people of an institution may change things (SA13).
 *
 * An institution whose subscription has run into its read-only period
 * (`read_only`), one that has ended or been suspended (`past`), and one that
 * has been deleted all keep their data, and their people can still sign in,
 * look and export, but nothing can be changed. The read-only filter uses this
 * to refuse writes, and the app layout uses it to show the banner.
 *
 * A user who belongs to no institution (a system administrator) is never
 * restricted. A user in several institutions is restricted only if none of
 * them allows changes.
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
        $rows = db_connect()->table('institution_users')
            ->select('institutions.status, institutions.deleted_at')
            ->join('institutions', 'institutions.id = institution_users.institution_id')
            ->where('institution_users.user_id', $userId)
            ->get()->getResultArray();

        if ($rows === []) {
            return self::WRITABLE;
        }

        $states = [];

        foreach ($rows as $row) {
            $states[] = match (true) {
                $row['deleted_at'] !== null || $row['status'] === 'past' => self::PAST,
                $row['status'] === 'read_only'                          => self::READ_ONLY,
                default                                                 => self::WRITABLE,
            };
        }

        if (in_array(self::WRITABLE, $states, true)) {
            return self::WRITABLE;
        }

        // None allows changes. Read-only only if every one of them is merely read-only.
        return in_array(self::PAST, $states, true) ? self::PAST : self::READ_ONLY;
    }
}
