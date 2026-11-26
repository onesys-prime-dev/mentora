<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Test setup: a direct thread made with ConversationService::startDirect has no message, so lists hide it (FR3).
 * This gives it a first message, written straight to the tables like the other factories.
 */
final class Threads
{
    public static function withMessage(int $conversationId, int $senderId): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('messages')->insert(['conversation_id' => $conversationId, 'sender_id' => $senderId, 'body_enc' => 'x', 'client_uuid' => bin2hex(random_bytes(18)), 'created_at' => $now]);
        $id = (int) $db->insertID();
        $db->table('conversations')->where('id', $conversationId)->update(['last_message_id' => $id, 'last_message_at' => $now]);

        return $id;
    }
}
