<?php

declare(strict_types=1);

namespace App\Services\Profiles;

/**
 * "Recent security activity" in Settings (D16): the person's own security changes and what an administrator did
 * to their account, read from the audit log. Every such entry targets the person's user row, so one query on the
 * (target_type, target_id) index finds them; profile edits (`profile.updated`) and the person's own photo changes
 * are not security events and are left out.
 */
class SecurityActivity
{
    /** The audit actions shown, with what the owner reads. Phase 6 adds the correction decisions here. */
    private const LABELS = [
        'profile.password_changed'        => 'Password changed',
        'profile.two_factor_on'           => 'Two-step sign-in turned on',
        'profile.two_factor_off'          => 'Two-step sign-in turned off',
        'profile.signed_out_others'       => 'Signed out of all other devices',
        'profile.photo_removed_by_admin'  => 'Your photo was removed by your school',
        'profile.photo_uploaded_by_admin' => 'Your school added a profile photo',
        'user.deactivated'                => 'Your account was deactivated',
        'user.reactivated'                => 'Your account was reactivated',
        'user.signed_out'                 => 'Your school signed you out of every device',
        'user.password_reset_sent'        => 'Your school sent you a password reset link',
        'user.password_reset'             => 'Password reset with an emailed link',
        'user.roles_changed'              => 'Your roles were changed',
        'user.updated'                    => 'Your school updated your name or email',
    ];

    /** @return list<array{action: string, label: string, at: string}> newest first */
    public function recent(int $userId, int $days = 90): array
    {
        $rows = db_connect()->table('audit_logs')
            ->select('action, actor_user_id, created_at')
            ->where('target_type', 'user')
            ->where('target_id', (string) $userId) // the column is a string: an int would skip the index
            ->whereIn('action', array_keys(self::LABELS))
            ->where('created_at >=', date('Y-m-d H:i:s', time() - $days * 86400))
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'action' => $row['action'],
            // A reset link asked for from the person's own Settings, not sent by the school.
            'label'  => $row['action'] === 'user.password_reset_sent' && (int) $row['actor_user_id'] === $userId
                ? 'You asked for a password reset link'
                : self::LABELS[$row['action']],
            'at'     => $row['created_at'],
        ], $rows);
    }
}
