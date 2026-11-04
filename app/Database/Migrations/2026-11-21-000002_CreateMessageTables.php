<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages: the messages themselves and "delete for me".
 *
 * `body_enc` is ciphertext (MessageCipher). There is no plaintext column and no plaintext preview column.
 * `client_uuid` makes a retried send harmless: the same sender sending the same uuid to a conversation twice
 * is refused by the unique key.
 */
class CreateMessageTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'conversation_id' => ['type' => 'INT', 'unsigned' => true],
            'sender_id' => ['type' => 'INT', 'unsigned' => true],
            'body_enc' => ['type' => 'MEDIUMTEXT'],
            'client_uuid' => ['type' => 'CHAR', 'constraint' => 36],
            'removed_at' => ['type' => 'DATETIME', 'null' => true],
            'removed_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['conversation_id', 'sender_id', 'client_uuid']);
        $this->forge->addKey(['conversation_id', 'id']);
        // The send rate limit counts one sender's messages in the last minute.
        $this->forge->addKey(['sender_id', 'created_at']);
        $this->forge->addForeignKey('conversation_id', 'conversations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('sender_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('removed_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('messages');

        $this->forge->addField([
            'message_id' => ['type' => 'INT', 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
        ]);

        $this->forge->addKey(['message_id', 'user_id'], true);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('message_id', 'messages', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('message_hidden');
    }

    public function down()
    {
        $this->forge->dropTable('message_hidden');
        $this->forge->dropTable('messages');
    }
}
