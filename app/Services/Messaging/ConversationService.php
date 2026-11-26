<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Institutions\InstitutionAccess;
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
     * Whether $sender may start (or reopen) a direct conversation with $recipient, and about which child.
     * The one check behind startDirect and the profile's Message button, so the button never promises
     * a conversation that starting refuses. Null when refused. `$opening` ignores the sender's own restriction and a
     * read-only institution: they stop creating threads, not reaching one that exists (the caller checks that one does).
     *
     * @return array{subject_student_id: ?int}|null
     */
    public function resolveDirectStart(User $sender, User $recipient, ?int $subjectStudentId = null, bool $opening = false): ?array
    {
        $inst = $this->resolver->institutionOf($sender);

        if ($inst === null || $this->resolver->institutionOf($recipient) !== $inst) {
            return null;
        }

        $senderParty    = $this->resolver->party($sender);
        $recipientParty = $this->resolver->party($recipient);
        $shared         = $this->resolver->childSubjects($sender, $senderParty, $recipient, $recipientParty);

        // Only a parent thread is about a child, and it is about exactly one: the one named, or else the only
        // child the parent and the teacher have in common. With several in common the child must be named,
        // so a pair never ends up with a thread per way of asking.
        if ($shared === null) {
            $subjectStudentId = null;
        } elseif ($subjectStudentId === null) {
            // Two staff who are also parents are a staff pair first; only if that is refused is the thread about the one shared child.
            $plain = $opening ? $this->resolver->forStart($sender, $recipient, null, $senderParty, $recipientParty)?->forOpening() : $this->resolver->forStart($sender, $recipient, null, $senderParty, $recipientParty);

            if ($plain !== null && $this->policy->canStart($senderParty, $recipientParty, null, $plain)) {
                return ['subject_student_id' => null];
            }

            if (count($shared) !== 1) {
                return null;
            }

            $subjectStudentId = $shared[0];
        }

        $context = $this->resolver->forStart($sender, $recipient, $subjectStudentId, $senderParty, $recipientParty);
        $context = $opening ? $context?->forOpening() : $context;

        if ($context === null || ! $this->policy->canStart($senderParty, $recipientParty, $subjectStudentId, $context)) {
            return null;
        }

        // A parent thread must name a child the parent can speak for.
        if ($subjectStudentId !== null && ! $context->linkedChild()) {
            return null;
        }

        return ['subject_student_id' => $subjectStudentId];
    }

    /**
     * The direct conversation that already exists between $sender and the recipient (same pair, and for a
     * parent the same child), or null: refused by the policy, or nothing sent yet. Creates nothing.
     *
     * @return array<string, mixed>|null a `conversations` row
     */
    public function existingDirect(User $sender, int $recipientId, ?int $subjectStudentId = null): ?array
    {
        $start = $this->startFor($sender, $recipientId, $subjectStudentId, true);

        return $start === null ? null : $this->byKey(DirectKey::for((int) $this->resolver->institutionOf($sender), (int) $sender->id, $recipientId, $start['subject_student_id']));
    }

    /**
     * What opening a person shows before anything is sent: nothing is stored until the first message goes.
     * Null when the policy refuses. `conversation_id` is the existing thread, if there is one.
     *
     * @return array{recipient_id: int, subject_student_id: ?int, first_contact: bool, conversation_id: ?int}|null
     */
    public function draftFor(User $sender, int $recipientId, ?int $subjectStudentId = null): ?array
    {
        $start = $this->startFor($sender, $recipientId, $subjectStudentId, true);

        if ($start === null) {
            return null;
        }

        $existing = $this->byKey(DirectKey::for((int) $this->resolver->institutionOf($sender), (int) $sender->id, $recipientId, $start['subject_student_id']));

        // A restriction (FR6) or a read-only institution (FR11) lets someone open a thread they already have, never begin one.
        if ($existing === null && ($this->resolver->restrictionKind((int) $sender->id) !== 'none' || ! (new InstitutionAccess())->canWrite((int) $sender->id))) {
            return null;
        }

        return [
            'recipient_id'       => $recipientId,
            'subject_student_id' => $start['subject_student_id'],
            'first_contact'      => $this->neverTalked($sender, $recipientId),
            'conversation_id'    => $existing === null ? null : (int) $existing['id'],
        ];
    }

    /**
     * Never talked before (FR5). This is the one value the sender sees and the email scope reads; the recipient's
     * minor override (Party::$minor, from Phase 5) is applied where the recipient's line is presented, so it never
     * reaches the sender (NFR Privacy).
     */
    private function neverTalked(User $sender, int $recipientId): bool
    {
        return ! $this->resolver->talkedBefore((int) $sender->id, $recipientId);
    }

    /** @return array{subject_student_id: ?int}|null */
    private function startFor(User $sender, int $recipientId, ?int $subjectStudentId, bool $opening = false): ?array
    {
        $recipient = $recipientId < 1 ? null : model(UserModel::class)->findById($recipientId);

        return $recipient === null ? null : $this->resolveDirectStart($sender, $recipient, $subjectStudentId, $opening);
    }

    /** @return array<string, mixed>|null */
    private function byKey(string $key): ?array
    {
        return db_connect()->table('conversations')->where('direct_key', $key)->get()->getRowArray() ?: null;
    }

    /**
     * The direct conversation between $sender and the recipient, created if it does not exist (the same pair, and
     * for a parent the same child, is one conversation whichever side starts it). Null when the policy refuses or
     * the recipient is not someone the sender can reach. The app never calls this to open a person:
     * MessageService::sendFirst does, with the first message in the same transaction. Tests use it to set up a thread.
     *
     * @throws RateLimitedException when the sender has started too many new threads this hour
     *
     * @return array<string, mixed>|null a `conversations` row
     */
    public function startDirect(User $sender, int $recipientId, ?int $subjectStudentId = null): ?array
    {
        $plan = $this->planDirect($sender, $recipientId, $subjectStudentId);

        return $plan === null ? null : $this->createDirect($sender, $recipientId, $plan);
    }

    /**
     * Everything startDirect reads before it writes: the policy, the new-thread limit and the first-contact fact.
     * Run it before the transaction that creates the thread opens, so the transaction's own read view only starts
     * after the insert and sees a thread the other side committed meanwhile.
     *
     * @throws RateLimitedException when the sender has started too many new threads this hour
     *
     * @return array{key: string, institution_id: int, subject_student_id: ?int, first_contact: bool}|null
     */
    public function planDirect(User $sender, int $recipientId, ?int $subjectStudentId = null): ?array
    {
        $start = $this->startFor($sender, $recipientId, $subjectStudentId);

        if ($start === null) {
            return null;
        }

        $inst             = (int) $this->resolver->institutionOf($sender);
        $subjectStudentId = $start['subject_student_id'];
        $key              = DirectKey::for($inst, (int) $sender->id, $recipientId, $subjectStudentId);

        // Starting a thread that exists returns it and creates nothing, so it is not counted.
        if (db_connect()->table('conversations')->where('direct_key', $key)->countAllResults() === 0) {
            (new MessageRateLimiter())->check('thread', (int) $sender->id, null, $this->resolver->party($sender));
        }

        return ['key' => $key, 'institution_id' => $inst, 'subject_student_id' => $subjectStudentId, 'first_contact' => $this->neverTalked($sender, $recipientId)];
    }

    /**
     * Creates the thread planDirect planned, or finds it. INSERT IGNORE then a read by key: two people starting at
     * once end up with the one row either way. Null only if the row cannot be read back.
     *
     * @param array{key: string, institution_id: int, subject_student_id: ?int, first_contact: bool} $plan
     *
     * @return array<string, mixed>|null a `conversations` row
     */
    public function createDirect(User $sender, int $recipientId, array $plan): ?array
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->transStart();
        $db->table('conversations')->ignore(true)->insert([
            'institution_id' => $plan['institution_id'], 'type' => 'direct', 'subject_student_id' => $plan['subject_student_id'],
            'created_by' => (int) $sender->id, 'is_locked' => 0, 'direct_key' => $plan['key'], 'first_contact' => $plan['first_contact'] ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $conversation = $this->byKey($plan['key']);

        foreach ($conversation === null ? [] : [(int) $sender->id, $recipientId] as $userId) {
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
     * Whether the user may lock or remove messages in this conversation. A course
     * chat is moderated by its instructor (and the institution's admin); a staff
     * group by the chairperson or dean who created it. Direct threads by nobody.
     *
     * @param array<string, mixed> $conversation a `conversations` row
     */
    public function canModerate(User $user, array $conversation): bool
    {
        $offeringId = $conversation['offering_id'] === null ? null : (int) $conversation['offering_id'];
        $teaches    = $offeringId !== null && $this->resolver->isCourseInstructor((int) $user->id, $offeringId);
        $created    = $conversation['type'] === 'group' && (int) ($conversation['owner_id'] ?? 0) === (int) $user->id;

        $party = $this->resolver->party($user);

        return $this->policy->canModerate(
            $teaches ? $party->withRole('instructor') : $party,
            (string) $conversation['type'],
            $teaches || $created,
            $this->resolver->institutionOf($user) === (int) $conversation['institution_id'],
        );
    }

    /**
     * Locks a course chat to announcements-only, or opens it again. Null when the
     * chat is not the user's or they may not moderate it (the same "not found").
     *
     * @return array{id: int, is_locked: bool}|null
     */
    public function setLocked(User $user, int $conversationId, bool $locked): ?array
    {
        $conversation = $this->find($user, $conversationId);

        if ($conversation === null || $conversation['type'] !== 'course' || ! $this->canModerate($user, $conversation)) {
            return null;
        }

        if ((bool) $conversation['is_locked'] !== $locked) {
            db_connect()->table('conversations')->where('id', $conversationId)->update(['is_locked' => $locked ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);

            service('auditLogger')->log($locked ? 'messages.course_chat_locked' : 'messages.course_chat_unlocked', $user, [
                'target_type'    => 'conversation',
                'target_id'      => $conversationId,
                'institution_id' => (int) $conversation['institution_id'],
                'old'            => ['is_locked' => (bool) $conversation['is_locked']],
                'new'            => ['is_locked' => $locked],
            ]);
        }

        return ['id' => $conversationId, 'is_locked' => $locked];
    }

    /**
     * The user's conversations: pinned first, then course chats, then newest. The main list leaves out archived
     * chats ($archived shows only those) and chats the user deleted for themselves until a newer message arrives.
     * No message text.
     *
     * @param 'all'|'course'|'direct'|'group' $filter
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user, string $filter = 'all', bool $unreadOnly = false, bool $archived = false): array
    {
        $inst = $this->resolver->institutionOf($user);

        if ($inst === null) {
            return [];
        }

        $sql = 'SELECT c.id, c.type, c.title, c.offering_id, c.subject_student_id, c.created_by, c.owner_id, c.closed_at, c.institution_id, c.is_locked, c.allow_reactions, c.allow_replies, c.allow_forwarding, c.allow_bump, c.allow_delete_everyone, c.last_message_at, c.first_contact, c.first_contact_cleared_at,
                    p.is_muted, p.is_starred, p.pinned_at, p.archived_at, p.marked_unread,
                    (SELECT o.user_id FROM conversation_participants o WHERE o.conversation_id = c.id AND o.user_id <> p.user_id LIMIT 1) AS other_user_id,
                    GREATEST((' . $this->unreadSql() . '), p.marked_unread) AS unread,
                    (' . $this->unreadSql() . ') AS new_messages
                FROM conversations c
                JOIN conversation_participants p ON p.conversation_id = c.id AND p.left_at IS NULL
                WHERE p.user_id = ? AND c.institution_id = ? AND NOT (c.type = \'direct\' AND c.last_message_id IS NULL)
                  AND NOT (p.cleared_before_message_id > 0 AND COALESCE(c.last_message_id, 0) <= p.cleared_before_message_id)
                  AND p.archived_at IS ' . ($archived ? 'NOT NULL' : 'NULL');
        $binds = [(int) $user->id, $inst];

        if (in_array($filter, ['course', 'direct', 'group'], true)) {
            $sql    .= ' AND c.type = ?';
            $binds[] = $filter;
        }

        if ($unreadOnly) {
            $sql .= ' HAVING unread > 0';
        }

        $sql .= " ORDER BY (p.pinned_at IS NOT NULL) DESC, p.pinned_at DESC, (c.type = 'course') DESC, c.last_message_at DESC, c.id DESC";

        $rows = db_connect()->query($sql, $binds)->getResultArray();

        foreach ($rows as &$row) {
            $row['unread']        = (int) $row['unread'];
            $row['marked_unread'] = (int) $row['marked_unread'];
            $row['new_messages']  = (int) $row['new_messages'];
        }

        return $rows;
    }

    /** How many chats the user has archived (and not deleted for themselves). */
    public function archivedCount(User $user): int
    {
        $inst = $this->resolver->institutionOf($user);

        if ($inst === null) {
            return 0;
        }

        $row = db_connect()->query(
            'SELECT COUNT(*) AS total FROM conversations c
                JOIN conversation_participants p ON p.conversation_id = c.id AND p.left_at IS NULL
                WHERE p.user_id = ? AND c.institution_id = ? AND p.archived_at IS NOT NULL AND NOT (c.type = \'direct\' AND c.last_message_id IS NULL)
                  AND NOT (p.cleared_before_message_id > 0 AND COALESCE(c.last_message_id, 0) <= p.cleared_before_message_id)',
            [(int) $user->id, $inst],
        )->getRowArray();

        return (int) $row['total'];
    }

    public function unreadCount(User $user): int
    {
        $inst = $this->resolver->institutionOf($user);

        if ($inst === null) {
            return 0;
        }

        $row = db_connect()->query(
            'SELECT COALESCE(SUM(unread), 0) AS total FROM (
                SELECT GREATEST((' . $this->unreadSql() . '), p.marked_unread) AS unread
                FROM conversations c
                JOIN conversation_participants p ON p.conversation_id = c.id AND p.left_at IS NULL
                WHERE p.user_id = ? AND c.institution_id = ? AND p.is_muted = 0 AND NOT (c.type = \'direct\' AND c.last_message_id IS NULL)
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
                  AND m.id > p.cleared_before_message_id
                  AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = p.user_id)';
    }
}
