<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Courses\ChairpersonAccessService;
use App\Services\Courses\DeanAccessService;
use CodeIgniter\Shield\Entities\User;

/**
 * The per-chat switches a manager can flip (reactions, replies, forwarding, bump,
 * delete for everyone) and the lock. Who may flip them is MessagingPolicy's call;
 * a chat that is not the person's, or that they may not manage, is null (the shared 404).
 */
class ChatControls
{
    public const SWITCHES = ['reactions', 'replies', 'forwarding', 'bump', 'delete_everyone'];

    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    public function __construct(?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null)
    {
        $this->resolver = $resolver ?? new MessagingContextResolver();
        $this->policy   = $policy ?? new MessagingPolicy();
    }

    /** @param array<string, mixed> $conversation a `conversations` row */
    public static function allows(array $conversation, string $switch): bool
    {
        return (int) ($conversation['allow_' . $switch] ?? 1) === 1;
    }

    /**
     * @param array<string, mixed> $conversation a `conversations` row
     *
     * @return array<string, bool>
     */
    public static function forConversation(array $conversation): array
    {
        $out = [];

        foreach (self::SWITCHES as $switch) {
            $out[$switch] = self::allows($conversation, $switch);
        }

        $out['lock'] = (int) ($conversation['is_locked'] ?? 0) === 1;

        return $out;
    }

    /** @param array<string, mixed> $conversation a `conversations` row */
    public function canManage(User $user, array $conversation): bool
    {
        $role       = $this->resolver->activeRole($user);
        $offeringId = ($conversation['offering_id'] ?? null) === null ? null : (int) $conversation['offering_id'];
        $teaches    = $offeringId !== null && $this->resolver->isCourseInstructor((int) $user->id, $offeringId);
        $inScope    = false;

        if ($offeringId !== null) {
            $inScope = match ($role) {
                'chairperson' => (new ChairpersonAccessService())->canAccess($user, $offeringId),
                'dean'        => (new DeanAccessService())->canAccessOffering($user, $offeringId),
                default       => false,
            };
        }

        return $this->policy->canManageControls(
            $role === 'institutional_admin' ? $role : ($teaches ? 'instructor' : $role),
            (string) $conversation['type'],
            $teaches,
            $inScope,
            (int) ($conversation['created_by'] ?? 0) === (int) $user->id,
            $this->resolver->institutionOf($user) === (int) $conversation['institution_id'],
        );
    }

    /**
     * @return array{ok: bool, reason?: string, controls?: array<string, bool>}|null
     */
    public function set(User $user, int $conversationId, string $switch, bool $on): ?array
    {
        $conversation = (new ConversationService())->find($user, $conversationId);

        if ($conversation === null || ! $this->canManage($user, $conversation)) {
            return null;
        }

        if ($switch !== 'lock' && ! in_array($switch, self::SWITCHES, true)) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        $column = $switch === 'lock' ? 'is_locked' : 'allow_' . $switch;
        $old    = $switch === 'lock' ? (int) $conversation['is_locked'] === 1 : self::allows($conversation, $switch);

        if ($old !== $on) {
            db_connect()->table('conversations')->where('id', $conversationId)->update([$column => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
            $conversation[$column] = $on ? 1 : 0;

            if ($switch === 'lock' && $conversation['type'] === 'course') {
                $action = $on ? 'messages.course_chat_locked' : 'messages.course_chat_unlocked';
                $detail = ['old' => ['is_locked' => $old], 'new' => ['is_locked' => $on]];
            } else {
                $action = 'messages.chat_control_changed';
                $detail = ['old' => [$switch => $old], 'new' => [$switch => $on]];
            }

            service('auditLogger')->log($action, $user, [
                'target_type'    => 'conversation',
                'target_id'      => $conversationId,
                'institution_id' => (int) $conversation['institution_id'],
                ...$detail,
            ]);
        }

        return ['ok' => true, 'controls' => self::forConversation($conversation)];
    }
}
