<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Shield\Entities\User;

/**
 * The minor flag (FR8): an institution's administrator marks a student of that institution, and Messages
 * (MessagingContextResolver::party) narrows who may write to them. Nothing here is shown to the student or to
 * anyone who is not an administrator or staff.
 */
class MinorFlag
{
    public function isMinor(int $institutionId, int $userId): bool
    {
        return (int) db_connect()->table('institution_users')->select('is_minor')
            ->where('institution_id', $institutionId)->where('user_id', $userId)->get()->getRow('is_minor') === 1;
    }

    /**
     * False, changing nothing, when the actor does not administer the institution, or the person is not one of its members (or, to mark, not a student).
     * Administration is the same global group plus membership check People uses: roles are global, there is no per-institution role to check.
     * Setting what is already set is true and is not audited again.
     */
    public function set(User $admin, int $institutionId, int $userId, bool $minor): bool
    {
        $db = db_connect();

        $mayAdminister = $admin->inGroup('admin')
            || ($admin->inGroup('institutional_admin') && $db->table('institution_users')->where('institution_id', $institutionId)->where('user_id', (int) $admin->id)->countAllResults() === 1);

        // Marking needs the student role; clearing only needs membership, so a flag can never get stuck on someone who lost the role.
        $allowed = $mayAdminister
            && $db->table('institution_users')->where('institution_id', $institutionId)->where('user_id', $userId)->countAllResults() === 1
            && (! $minor || $db->table('auth_groups_users')->where('user_id', $userId)->where('group', 'student')->countAllResults() > 0);

        if (! $allowed) {
            return false;
        }

        if ($this->isMinor($institutionId, $userId) !== $minor) {
            $db->table('institution_users')->where('institution_id', $institutionId)->where('user_id', $userId)->update(['is_minor' => $minor ? 1 : 0]);

            service('auditLogger')->log($minor ? 'messages.minor_flag_set' : 'messages.minor_flag_cleared', $admin, [
                'target_type' => 'user', 'target_id' => $userId, 'institution_id' => $institutionId,
            ]);
        }

        return true;
    }
}
