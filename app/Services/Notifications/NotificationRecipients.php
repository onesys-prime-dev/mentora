<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Who is told what (SA15): the platform's system administrators, and an
 * institution's own administrators. Deactivated and deleted accounts are
 * never told.
 */
class NotificationRecipients
{
    /** @return list<int> */
    public function administrators(): array
    {
        return $this->ids(db_connect()->table('users')
            ->select('users.id')
            ->join('auth_groups_users', "auth_groups_users.user_id = users.id AND auth_groups_users.group = 'admin'")
            ->where('users.deleted_at IS NULL', null, false)
            ->where('users.active', 1));
    }

    /** @return list<int> */
    public function institutionAdministrators(int $institutionId): array
    {
        return $this->ids(db_connect()->table('users')
            ->select('users.id')
            ->join('auth_groups_users', "auth_groups_users.user_id = users.id AND auth_groups_users.group = 'institutional_admin'")
            ->join('institution_users', 'institution_users.user_id = users.id')
            ->where('institution_users.institution_id', $institutionId)
            ->where('users.deleted_at IS NULL', null, false)
            ->where('users.active', 1));
    }

    /** @return list<int> */
    private function ids(\CodeIgniter\Database\BaseBuilder $builder): array
    {
        return array_values(array_unique(array_map('intval', array_column($builder->get()->getResultArray(), 'id'))));
    }
}
