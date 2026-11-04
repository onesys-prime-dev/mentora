<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;

/**
 * Reporting a message (spec 3.3 rule 8, section 4 `message_reports`). The
 * report keeps an encrypted snapshot of the message and up to five before it,
 * taken when it is reported, so the evidence survives the message being removed
 * later. The reporter's note is encrypted too. The only reader is the
 * institutional administrator of the same institution (ReportReviewService);
 * the audit entry holds ids and the reason, never text.
 */
class ReportService
{
    public const REASONS = ['harassment', 'spam', 'inappropriate', 'other'];

    public const NOTE_MAX = 500;

    public const SNAPSHOT_CONTEXT = 5;

    private MessageCipher $cipher;

    private ConversationService $conversations;

    public function __construct(?MessageCipher $cipher = null, ?ConversationService $conversations = null)
    {
        $this->cipher        = $cipher ?? new MessageCipher();
        $this->conversations = $conversations ?? new ConversationService();
    }

    /**
     * False for a conversation that is not the reporter's, a message not in it or not visible to them, their own
     * message, a removed one, an unknown reason, a note that is too long, a message they already reported, or
     * when the key is unusable.
     *
     * @throws RateLimitedException when the reporter has sent too many reports today
     */
    public function report(User $reporter, int $conversationId, int $messageId, string $reason, ?string $note): bool
    {
        $conversation = $this->conversations->find($reporter, $conversationId);
        $note         = $note === null ? null : trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $note));

        if ($conversation === null || ! in_array($reason, self::REASONS, true) || ! $this->cipher->isUsable() || ($note !== null && mb_strlen($note) > self::NOTE_MAX)) {
            return false;
        }

        $db      = db_connect();
        $userId  = (int) $reporter->id;
        $message = $messageId < 1 ? null : $db->table('messages')->where(['id' => $messageId, 'conversation_id' => $conversationId])->get()->getRowArray();
        $first   = (int) $db->table('conversation_participants')->select('first_visible_message_id')->where(['conversation_id' => $conversationId, 'user_id' => $userId])->get()->getRow('first_visible_message_id');

        if ($message === null || (int) $message['sender_id'] === $userId || $message['removed_at'] !== null || (int) $message['id'] < $first || $this->hiddenFor($userId, $messageId)) {
            return false;
        }

        if ($db->table('message_reports')->where(['reporter_id' => $userId, 'message_id' => $messageId])->countAllResults() > 0) {
            return false;
        }

        (new MessageRateLimiter())->check('report', $userId);

        $snapshot = json_encode(['reported_id' => $messageId, 'messages' => $this->context($conversationId, $messageId, $first, $userId)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $db->table('message_reports')->insert([
            'institution_id' => (int) $conversation['institution_id'],
            'message_id'     => $messageId,
            'reporter_id'    => $userId,
            'reason'         => $reason,
            'note_enc'       => $note === null || $note === '' ? null : $this->cipher->encrypt($note, $conversationId, $userId),
            'snapshot_enc'   => $this->cipher->encrypt($snapshot, $conversationId, $userId),
            'status'         => 'open',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $reportId = (int) $db->insertID();

        // Ids and the reason only.
        service('auditLogger')->log('messages.report_created', $reporter, [
            'target_type'    => 'message_report',
            'target_id'      => $reportId,
            'institution_id' => (int) $conversation['institution_id'],
            'new'            => ['message_id' => $messageId, 'conversation_id' => $conversationId, 'reason' => $reason],
        ]);

        return true;
    }

    private function hiddenFor(int $userId, int $messageId): bool
    {
        return db_connect()->table('message_hidden')->where(['message_id' => $messageId, 'user_id' => $userId])->countAllResults() > 0;
    }

    /**
     * The reported message and up to SNAPSHOT_CONTEXT before it that the reporter could see, oldest first.
     *
     * @return list<array{id: int, sender_id: int, sender_name: string, body: string, created_at: string}>
     */
    private function context(int $conversationId, int $messageId, int $first, int $reporterId): array
    {
        $rows = db_connect()->table('messages m')->select('m.*')
            ->where('m.conversation_id', $conversationId)->where('m.id <=', $messageId)->where('m.id >=', $first)->where('m.removed_at', null)
            ->where('NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ' . $reporterId . ')', null, false)
            ->orderBy('m.id', 'DESC')->limit(self::SNAPSHOT_CONTEXT + 1)->get()->getResultArray();

        $names = ConversationPresenter::names(array_map('intval', array_column($rows, 'sender_id')));
        $out   = [];

        foreach (array_reverse($rows) as $row) {
            $body = $this->cipher->decrypt((string) $row['body_enc'], (int) $row['conversation_id'], (int) $row['sender_id']);

            if ($body === null) {
                continue;
            }

            $out[] = ['id' => (int) $row['id'], 'sender_id' => (int) $row['sender_id'], 'sender_name' => $names[(int) $row['sender_id']] ?? 'Former member', 'body' => $body, 'created_at' => (string) $row['created_at']];
        }

        return $out;
    }
}
