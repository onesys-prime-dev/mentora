<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Starting, finding, listing and counting conversations for the acting user.
 *
 * Every method takes the person acting and answers only for conversations they
 * are an active participant of; anything else is null, never a reason, so an id
 * that is not theirs looks exactly like one that does not exist.
 */
class ConversationService
{
    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    public function __construct(?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null)
    {
        $this->resolver = $resolver ?? new MessagingContextResolver();
        $this->policy   = $policy ?? new MessagingPolicy();
    }

    /**
     * The direct conversation between $sender and the recipient, created if it
     * does not exist (the same pair, and for a parent the same child, is one
     * conversation whichever side starts it). Null when the policy refuses or
     * the recipient is not someone the sender can reach.
     *
     * @return array<string, mixed>|null a `conversations` row
     */
    public function startDirect(User $sender, int $recipientId, ?int $subjectStudentId = null): ?array
    {
        $recipient = $recipientId < 1 ? null : model(UserModel::class)->findById($recipientId);
        $inst      = $this->resolver->institutionOf($sender);

        if ($recipient === null || $inst === null || $this->resolver->institutionOf($recipient) !== $inst) {
            return null;
        }

        $senderRole    = $this->resolver->activeRole($sender);
        $recipientRole = $this->resolver->activeRole($recipient);

        // Only a parent thread is about a child, and it is about exactly one: the one named, or else the only
        // child the parent and the teacher have in common. With several in common the child must be named,
        // so a pair never ends up with a thread per way of asking.
        if ($senderRole !== 'parent' && $recipientRole !== 'parent') {
            $subjectStudentId = null;
        } elseif ($subjectStudentId === null) {
            [$parent, $staff, $staffRole] = $senderRole === 'parent' ? [$sender, $recipient, $recipientRole] : [$recipient, $sender, $senderRole];
            $shared                       = $this->resolver->sharedLinkedChildIds($parent, $staff, $staffRole);

            if (count($shared) !== 1) {
                return null;
            }

            $subjectStudentId = $shared[0];
        }

        $context = $this->resolver->forStart($sender, $recipient, $subjectStudentId);

        if ($context === null || ! $this->policy->canStart($senderRole, $recipientRole, $context)) {
            return null;
        }

        // A parent thread must name a child the parent can speak for.
        if ($subjectStudentId !== null && ! $context->linkedChild) {
            return null;
        }

        $db  = db_connect();
        $key = DirectKey::for($inst, (int) $sender->id, $recipientId, $subjectStudentId);
        $now = date('Y-m-d H:i:s');

        // INSERT IGNORE then read by key: two people starting at once end up with the one row either way.
        $db->transStart();
        $db->table('conversations')->ignore(true)->insert([
            'institution_id' => $inst, 'type' => 'direct', 'subject_student_id' => $subjectStudentId,
            'created_by' => (int) $sender->id, 'is_locked' => 0, 'direct_key' => $key, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $conversation = $db->table('conversations')->where('direct_key', $key)->get()->getRowArray();

        foreach ([(int) $sender->id, $recipientId] as $userId) {
            $db->table('conversation_participants')->ignore(true)->insert([
                'conversation_id' => $conversation['id'], 'user_id' => $userId, 'joined_at' => $now, 'first_visible_message_id' => 0,
            ]);
        }

        $db->transComplete();

        return $conversation;
    }

    /** @return array<string, mixed>|null */
    public function find(User $user, int $conversationId): ?array
    {
        $inst = $this->resolver->institutionOf($user);

        if ($inst === null || $conversationId < 1) {
            return null;
        }

        return db_connect()->table('conversations c')->select('c.*')
            ->join('conversation_participants p', 'p.conversation_id = c.id AND p.left_at IS NULL', 'inner', false)
            ->where('p.user_id', (int) $user->id)->where('c.id', $conversationId)->where('c.institution_id', $inst)
            ->get()->getRowArray();
    }

    /**
     * The user's conversations, course chats first, then newest. No message text.
     *
     * @param 'all'|'course'|'direct'|'group' $filter
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user, string $filter = 'all', bool $unreadOnly = false): array
    {
        $inst = $this->resolver->institutionOf($user);

        if ($inst === null) {
            return [];
        }

        $sql = 'SELECT c.id, c.type, c.title, c.offering_id, c.subject_student_id, c.is_locked, c.last_message_at,
                    p.is_muted, p.is_starred,
                    (SELECT o.user_id FROM conversation_participants o WHERE o.conversation_id = c.id AND o.user_id <> p.user_id LIMIT 1) AS other_user_id,
                    (' . $this->unreadSql() . ') AS unread
                FROM conversations c
                JOIN conversation_participants p ON p.conversation_id = c.id AND p.left_at IS NULL
                WHERE p.user_id = ? AND c.institution_id = ?';
        $binds = [(int) $user->id, $inst];

        if (in_array($filter, ['course', 'direct', 'group'], true)) {
            $sql    .= ' AND c.type = ?';
            $binds[] = $filter;
        }

        if ($unreadOnly) {
            $sql .= ' HAVING unread > 0';
        }

        $sql .= " ORDER BY (c.type = 'course') DESC, c.last_message_at DESC, c.id DESC";

        $rows = db_connect()->query($sql, $binds)->getResultArray();

        foreach ($rows as &$row) {
            $row['unread'] = (int) $row['unread'];
        }

        return $rows;
    }

    public function unreadCount(User $user): int
    {
        $inst = $this->resolver->institutionOf($user);

        if ($inst === null) {
            return 0;
        }

        $row = db_connect()->query(
            'SELECT COALESCE(SUM(unread), 0) AS total FROM (
                SELECT (' . $this->unreadSql() . ') AS unread
                FROM conversations c
                JOIN conversation_participants p ON p.conversation_id = c.id AND p.left_at IS NULL
                WHERE p.user_id = ? AND c.institution_id = ?
            ) t',
            [(int) $user->id, $inst],
        )->getRowArray();

        return (int) $row['total'];
    }

    /** Messages from others in conversation `c`, unread by participant `p`, still visible to them. */
    private function unreadSql(): string
    {
        return 'SELECT COUNT(*) FROM messages m
                WHERE m.conversation_id = c.id AND m.sender_id <> p.user_id AND m.removed_at IS NULL
                  AND m.id > COALESCE(p.last_read_message_id, 0) AND m.id >= p.first_visible_message_id
                  AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = p.user_id)';
    }
}
