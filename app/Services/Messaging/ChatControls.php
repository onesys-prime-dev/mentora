<?php

declare(strict_types=1);

namespace App\Services\Messaging;

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
        return $this->policy->canManageControls(
            $this->resolver->activeRole($user),
            (string) $conversation['type'],
            false,
            false,
            false,
            $this->resolver->institutionOf($user) === (int) $conversation['institution_id'],
        );
    }

    /**
     * Every course chat and staff group of the administrator's institution with its switches, for the Settings
     * picker. Names and switches only, never a message. Empty for anyone who is not that administrator.
     *
     * @return list<array{id: int, name: string, type: string, controls: array<string, bool>}>
     */
    public function chatsFor(User $user): array
    {
        $institution = $this->resolver->institutionOf($user);

        if ($institution === null || $this->resolver->activeRole($user) !== 'institutional_admin') {
            return [];
        }

        $rows = db_connect()->table('conversations c')
            ->select('c.*, k.course_code, k.course_title')
            ->join('course_offerings o', 'o.id = c.offering_id', 'left')
            ->join('courses k', 'k.id = o.course_id', 'left')
            ->where('c.institution_id', $institution)->whereIn('c.type', ['course', 'group'])
            ->orderBy('c.type')->orderBy('c.id')->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id'       => (int) $row['id'],
            'name'     => $row['type'] === 'course'
                ? trim((string) $row['course_code'] . ' · ' . (string) $row['course_title'], ' ·')
                : (string) ($row['title'] ?? 'Group'),
            'type'     => (string) $row['type'],
            'controls' => self::forConversation($row),
        ], $rows);
    }

    /** The administrator's own institution's course chat or staff group by id, member or not; null otherwise. */
    private function adminChat(User $user, int $conversationId): ?array
    {
        $institution = $this->resolver->institutionOf($user);

        if ($institution === null || $this->resolver->activeRole($user) !== 'institutional_admin') {
            return null;
        }

        return db_connect()->table('conversations')->where(['id' => $conversationId, 'institution_id' => $institution])
            ->whereIn('type', ['course', 'group'])->get()->getRowArray();
    }

    /**
     * @return array{ok: bool, reason?: string, controls?: array<string, bool>}|null
     */
    public function set(User $user, int $conversationId, string $switch, bool $on): ?array
    {
        // Not through the person's membership: the administrator is in no course chat.
        $conversation = $this->adminChat($user, $conversationId);

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
