<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages: owner-run groups (FR7). `owner_id` is who runs a group (backfilled from `created_by`, which stays the
 * creator), `closed_at` makes a group read-only. `conversation_events` holds the membership lines shown in a group
 * (ids only, never text): who did what to whom.
 */
class AddGroupOwnership extends Migration
{
    public function up()
    {
        $this->forge->addColumn('conversations', [
            'owner_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'closed_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->db->query('ALTER TABLE conversations ADD CONSTRAINT fk_conversations_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE');
        $this->db->query("UPDATE conversations SET owner_id = created_by WHERE type = 'group' AND owner_id IS NULL");

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'conversation_id' => ['type' => 'INT', 'unsigned' => true],
            'event'           => ['type' => 'VARCHAR', 'constraint' => 30],
            'actor_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'target_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['conversation_id', 'id']);
        $this->forge->addKey(['conversation_id', 'created_at']);
        $this->forge->addForeignKey('conversation_id', 'conversations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('conversation_events');
    }

    public function down()
    {
        $this->forge->dropTable('conversation_events', true);

        if ($this->db->query("SHOW COLUMNS FROM conversations LIKE 'owner_id'")->getNumRows() > 0) {
            $this->db->query('ALTER TABLE conversations DROP FOREIGN KEY fk_conversations_owner');
            $this->forge->dropColumn('conversations', ['owner_id', 'closed_at']);
        }
    }
}
