<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages actions, part 2: what can be done to a single message, and the per-chat switches.
 *
 * `messages` gains reply, forward, bump, delete-for-everyone and pin state; `conversations` gains the five
 * `allow_*` switches (all on by default); `message_reactions` holds one emoji per person per message.
 */
class AddMessageActionColumns extends Migration
{
    private const SWITCHES = ['allow_reactions', 'allow_replies', 'allow_forwarding', 'allow_bump', 'allow_delete_everyone'];

    public function up()
    {
        $this->forge->addColumn('messages', [
            'reply_to_message_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'forwarded' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'bumped_from_message_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'deleted_everyone_at' => ['type' => 'DATETIME', 'null' => true],
            'pinned_at' => ['type' => 'DATETIME', 'null' => true],
            'pinned_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'changed_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $db = $this->db;
        $db->query('ALTER TABLE `messages` ADD INDEX `messages_changed_at` (`changed_at`)');
        $db->query('ALTER TABLE `messages` ADD CONSTRAINT `messages_reply_to_message_id_foreign` FOREIGN KEY (`reply_to_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
        $db->query('ALTER TABLE `messages` ADD CONSTRAINT `messages_bumped_from_message_id_foreign` FOREIGN KEY (`bumped_from_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
        $db->query('ALTER TABLE `messages` ADD CONSTRAINT `messages_pinned_by_foreign` FOREIGN KEY (`pinned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');

        $switches = [];

        foreach (self::SWITCHES as $switch) {
            $switches[$switch] = ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1];
        }

        $this->forge->addColumn('conversations', $switches);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'message_id' => ['type' => 'INT', 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'emoji' => ['type' => 'VARCHAR', 'constraint' => 16],
            'created_at' => ['type' => 'DATETIME'],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['message_id', 'user_id']);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('message_id', 'messages', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('message_reactions');
    }

    public function down()
    {
        $this->forge->dropTable('message_reactions', true);
        $this->forge->dropColumn('conversations', self::SWITCHES);

        // Foreign keys (and the index) go before the columns they cover.
        foreach (['reply_to_message_id', 'bumped_from_message_id', 'pinned_by'] as $column) {
            $this->db->query("ALTER TABLE `messages` DROP FOREIGN KEY `messages_{$column}_foreign`");
        }

        $this->db->query('ALTER TABLE `messages` DROP INDEX `messages_changed_at`');
        $this->forge->dropColumn('messages', ['reply_to_message_id', 'forwarded', 'bumped_from_message_id', 'deleted_everyone_at', 'pinned_at', 'pinned_by', 'changed_at']);
    }
}
