<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * Which things the SIS owns. While an institution's connection is enabled,
 * the people it supplied are managed by the SIS: their name and email are not
 * editable in Mentora, because the next sync would put them back.
 *
 * (Courses and enrolments are SIS-owned in the same way, but nothing in
 * Mentora edits them by hand today, so there is nothing to lock yet.)
 */
class SisOwnership
{
    /** The person fields the SIS decides. */
    public const PERSON_FIELDS = ['first_name', 'last_name', 'email'];

    /** Whether the SIS is currently the owner of this person's details. */
    public function managesPerson(int $userId): bool
    {
        return db_connect()->table('sis_links')
            ->join('sis_connections', 'sis_connections.institution_id = sis_links.institution_id')
            ->where('sis_links.entity', 'person')
            ->where('sis_links.local_id', $userId)
            ->where('sis_connections.enabled', 1)
            ->countAllResults() > 0;
    }
}
