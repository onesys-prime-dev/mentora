<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages: blocking, reporting and per-person preferences.
 *
 * A report keeps `snapshot_enc` (the reported message plus up to 5 before it, encrypted when reported) so the
 * evidence survives the message being removed later, and `note_enc` (the reporter's note, encrypted).
 * `reason` is harassment, spam, inappropriate or other; `status` is open, dismissed or actioned.
 */
class CreateMessageSafetyTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'institution_id' => ['type' => 'INT', 'unsigned' => true],
            'blocker_id' => ['type' => 'INT', 'unsigned' => true],
            'blocked_id' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey(['blocker_id', 'blocked_id'], true);
        $this->forge->addKey('blocked_id');
        $this->forge->addKey('institution_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('blocker_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('blocked_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_blocks');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'unsigned' => true],
            'message_id' => ['type' => 'INT', 'unsigned' => true],
            'reporter_id' => ['type' => 'INT', 'unsigned' => true],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 20],
            'note_enc' => ['type' => 'MEDIUMTEXT', 'null' => true],
            'snapshot_enc' => ['type' => 'MEDIUMTEXT'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'open'],
            'reviewed_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'reviewed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['institution_id', 'status']);
        $this->forge->addKey('message_id');
        $this->forge->addKey('reporter_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('message_id', 'messages', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('reporter_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('reviewed_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('message_reports');

        $this->forge->addField([
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'quiet_start' => ['type' => 'TIME', 'null' => true],
            'quiet_end' => ['type' => 'TIME', 'null' => true],
            'email_alerts' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);

        $this->forge->addKey('user_id', true);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('message_preferences');
    }

    public function down()
    {
        $this->forge->dropTable('message_preferences');
        $this->forge->dropTable('message_reports');
        $this->forge->dropTable('user_blocks');
    }
}
