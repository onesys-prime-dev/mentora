<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * In-app notifications (SA15). One row per person told.
 *
 * `dedupe_key` is what makes a trigger safe to run again and again: the same
 * thing (say, "institution 7's subscription ended on 2026-10-01") is only ever
 * told to a person once, enforced by the unique key (user_id, dedupe_key).
 * `emailed_at` is set when an urgent one was also emailed.
 */
class CreateNotificationsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'type' => ['type' => 'VARCHAR', 'constraint' => 60],
            'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            'body' => ['type' => 'TEXT', 'null' => true],
            'link' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'urgent' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'dedupe_key' => ['type' => 'VARCHAR', 'constraint' => 190],
            'read_at' => ['type' => 'DATETIME', 'null' => true],
            'emailed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'dedupe_key']);
        $this->forge->addKey(['user_id', 'read_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('notifications');
    }

    public function down()
    {
        $this->forge->dropTable('notifications', true);
    }
}
