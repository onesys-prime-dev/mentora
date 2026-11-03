<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages: the conversations and who is in them (docs/superpowers/specs/2026-11-02-messages-design.md, section 4).
 *
 * `type` is course, direct or group. A course chat belongs to one offering (`offering_id`, unique); a direct
 * conversation carries a `direct_key` (unique) so that starting one that exists returns it. MySQL has no partial
 * indexes, so both uniques are plain: each column is NULL for the other types, and NULLs never collide.
 * `last_message_id` and the participants' message ids are plain indexed columns with no foreign key, because
 * messages point back at conversations.
 */
class CreateConversationTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'unsigned' => true],
            'type' => ['type' => 'VARCHAR', 'constraint' => 10],
            'offering_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'subject_student_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'title' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'is_locked' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'direct_key' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'last_message_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'last_message_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('offering_id');
        $this->forge->addUniqueKey('direct_key');
        $this->forge->addKey(['institution_id', 'type']);
        $this->forge->addKey('last_message_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('subject_student_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('conversations');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'conversation_id' => ['type' => 'INT', 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'joined_at' => ['type' => 'DATETIME'],
            'left_at' => ['type' => 'DATETIME', 'null' => true],
            'first_visible_message_id' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'last_read_message_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'last_notified_message_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'is_starred' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_muted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['conversation_id', 'user_id']);
        $this->forge->addKey(['user_id', 'left_at']);
        $this->forge->addForeignKey('conversation_id', 'conversations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('conversation_participants');
    }

    public function down()
    {
        $this->forge->dropTable('conversation_participants');
        $this->forge->dropTable('conversations');
    }
}
