<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Blocking (spec 3.3 rule 6). A blocks B: B cannot start a thread with A and
 * cannot post in the direct thread with A; A can still post, and both can read.
 * It never takes anyone out of a course chat or a group. Instructors and
 * institutional administrators cannot be blocked, because a student must always
 * be able to reach their instructor. Each change is audited with ids only.
 */
class BlockService
{
    /** Roles a block cannot shut out (the same list the policy uses). */
    public const UNBLOCKABLE = ['instructor', 'institutional_admin'];

    private MessagingContextResolver $resolver;

    public function __construct(?MessagingContextResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new MessagingContextResolver();
    }

    /** Whether a person of this active role can be blocked at all. */
    public static function blockableRole(string $role): bool
    {
        return $role !== '' && $role !== 'admin' && ! in_array($role, self::UNBLOCKABLE, true);
    }

    /**
     * False when the target is yourself, unknown, in another institution, or cannot be blocked. Blocking
     * someone already blocked succeeds without a second row or audit entry.
     */
    public function block(User $user, int $blockedId): bool
    {
        $institution = $this->resolver->institutionOf($user);
        $target      = $blockedId < 1 || $blockedId === (int) $user->id ? null : model(UserModel::class)->findById($blockedId);

        if ($institution === null || $target === null || $this->resolver->institutionOf($target) !== $institution || ! self::blockableRole($this->resolver->activeRole($target))) {
            return false;
        }

        $db = db_connect();
        $db->table('user_blocks')->ignore(true)->insert([
            'institution_id' => $institution, 'blocker_id' => (int) $user->id, 'blocked_id' => $blockedId, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($db->affectedRows() > 0) {
            $this->audit('messages.user_blocked', $user, $institution, $blockedId);
        }

        return true;
    }

    /** False for an unknown target; unblocking someone who is not blocked succeeds quietly. */
    public function unblock(User $user, int $blockedId): bool
    {
        $institution = $this->resolver->institutionOf($user);

        if ($institution === null || $blockedId < 1) {
            return false;
        }

        $db = db_connect();
        $db->table('user_blocks')->where(['blocker_id' => (int) $user->id, 'blocked_id' => $blockedId, 'institution_id' => $institution])->delete();

        if ($db->affectedRows() > 0) {
            $this->audit('messages.user_unblocked', $user, $institution, $blockedId);
        }

        return true;
    }

    /** @return list<array{id: int, name: string}> */
    public function blockedBy(User $user): array
    {
        $ids = array_map('intval', array_column(
            db_connect()->table('user_blocks')->select('blocked_id')->where('blocker_id', (int) $user->id)->orderBy('created_at', 'DESC')->get()->getResultArray(),
            'blocked_id',
        ));
        $names = ConversationPresenter::names($ids);

        return array_map(static fn (int $id): array => ['id' => $id, 'name' => $names[$id] ?? 'Former member'], $ids);
    }

    private function audit(string $action, User $user, int $institution, int $targetId): void
    {
        service('auditLogger')->log($action, $user, ['target_type' => 'user', 'target_id' => $targetId, 'institution_id' => $institution]);
    }
}
