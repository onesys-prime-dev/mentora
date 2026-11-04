<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages actions: the per-person chat state on `conversation_participants`.
 *
 * `pinned_at` and `archived_at` are set while the chat is pinned or archived; `marked_unread` is a manual
 * "unread" flag; `cleared_before_message_id` hides everything up to that message for this person only
 * (Delete for me), 0 meaning nothing is cleared.
 */
class AddChatActionColumns extends Migration
{
    public function up()
    {
        $this->forge->addColumn('conversation_participants', [
            'pinned_at' => ['type' => 'DATETIME', 'null' => true],
            'archived_at' => ['type' => 'DATETIME', 'null' => true],
            'marked_unread' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'cleared_before_message_id' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('conversation_participants', ['pinned_at', 'archived_at', 'marked_unread', 'cleared_before_message_id']);
    }
}
