<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Blocking (spec 3.3 rule 6). A blocks B: B cannot start a thread with A and
 * cannot post in the direct thread with A; A can still post, and both can read.
 * It never takes anyone out of a course chat or a group. Institutional
 * administrators cannot be blocked, and nobody can block someone they share an
 * active course with as instructor and student (the protected link), nor, as a
 * parent, a teacher of their child. Each change is audited with ids only.
 */
class BlockService
{
    private MessagingContextResolver $resolver;

    public function __construct(?MessagingContextResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new MessagingContextResolver();
    }

    /**
     * Whether $user may block $targetId: not self, known, same institution, holds a role, not the bare
     * system admin, not an institutional admin, not in a protected link with $user, and (for a parent) not
     * a teacher of one of their linked children.
     */
    public function canBlock(User $user, int $targetId): bool
    {
        $target = $targetId < 1 || $targetId === (int) $user->id ? null : model(UserModel::class)->findById($targetId);

        return $target !== null && $this->allows(
            $user,
            $this->resolver->institutionOf($user),
            $target,
            $this->resolver->institutionOf($target),
            $this->resolver->protectedLink((int) $user->id, $targetId),
        );
    }

    /**
     * The canBlock rule on facts the caller already has (the inbox computes them once per list).
     * $protectedLink false asks "would anything but the protected link refuse?", which is what the
     * UI offers: the server then answers a protected-link block with its own sentence (refusal()).
     */
    public function allows(User $user, ?int $institution, User $target, ?int $targetInstitution, bool $protectedLink): bool
    {
        if ($institution === null || $targetInstitution !== $institution || (int) $target->id === (int) $user->id) {
            return false;
        }

        $roles = $this->resolver->roles($target);

        if ($roles === [] || $roles === ['admin'] || in_array('institutional_admin', $roles, true) || $protectedLink) {
            return false;
        }

        return ! (in_array('parent', $this->resolver->roles($user), true) && in_array('instructor', $roles, true)
            && $this->resolver->sharedLinkedChildIds($user, $target, 'instructor') !== []);
    }

    /** The sentence to show when only the protected link stops $user blocking $targetId; null for every other refusal (those stay generic). */
    public function refusal(User $user, int $targetId): ?string
    {
        if ($targetId === (int) $user->id) {
            return null;
        }

        if ($this->resolver->teaches($targetId, (int) $user->id)) {
            return "You can't block your instructor while you share a course.";
        }

        return $this->resolver->teaches((int) $user->id, $targetId) ? "You can't block a student while you teach them." : null;
    }

    /**
     * False when the target cannot be blocked (see canBlock). Blocking someone already blocked succeeds
     * without a second row or audit entry.
     */
    public function block(User $user, int $blockedId): bool
    {
        $institution = $this->resolver->institutionOf($user);

        if ($institution === null || ! $this->canBlock($user, $blockedId)) {
            return false;
        }

        $db = db_connect();
        $db->table('user_blocks')->ignore(true)->insert([
            'institution_id' => $institution, 'blocker_id' => (int) $user->id, 'blocked_id' => $blockedId, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($db->affectedRows() > 0) {
            $this->audit('messages.user_blocked', $user, $institution, $blockedId);
        }

        // Blocking someone who opened a first contact with you answers it: the line goes.
        $db->query(
            'UPDATE conversations c JOIN conversation_participants p ON p.conversation_id = c.id AND p.user_id = ?
             SET c.first_contact_cleared_at = ? WHERE c.type = \'direct\' AND c.first_contact = 1 AND c.first_contact_cleared_at IS NULL AND c.created_by = ?',
            [(int) $user->id, date('Y-m-d H:i:s'), $blockedId],
        );

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

    /** @return list<array{id: int, name: string, paused: bool}> paused: the row exists but a protected link keeps it from binding */
    public function blockedBy(User $user): array
    {
        $ids = array_map('intval', array_column(
            db_connect()->table('user_blocks')->select('blocked_id')->where('blocker_id', (int) $user->id)->orderBy('created_at', 'DESC')->get()->getResultArray(),
            'blocked_id',
        ));
        $names = ConversationPresenter::names($ids);

        return array_map(fn (int $id): array => [
            'id' => $id, 'name' => $names[$id] ?? 'Former member', 'paused' => $this->resolver->protectedLink((int) $user->id, $id),
        ], $ids);
    }

    private function audit(string $action, User $user, int $institution, int $targetId): void
    {
        service('auditLogger')->log($action, $user, ['target_type' => 'user', 'target_id' => $targetId, 'institution_id' => $institution]);
    }
}
