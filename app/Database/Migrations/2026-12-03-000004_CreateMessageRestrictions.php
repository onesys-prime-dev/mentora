<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages: restrictions (an administrator's no_new / no_send, or the automatic pause as a no_new row with
 * source 'pause') and the pause a report was counted toward (`message_reports.pause_id`, no foreign key).
 */
class CreateMessageRestrictions extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'kind' => ['type' => 'VARCHAR', 'constraint' => 10],
            'source' => ['type' => 'VARCHAR', 'constraint' => 10],
            'starts_at' => ['type' => 'DATETIME'],
            'ends_at' => ['type' => 'DATETIME', 'null' => true],
            'ended_at' => ['type' => 'DATETIME', 'null' => true],
            'ended_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'end_reason' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'ended_at']);
        $this->forge->addKey('institution_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('ended_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('message_restrictions');

        $this->forge->addColumn('message_reports', ['pause_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true]]);
    }

    public function down()
    {
        $this->forge->dropColumn('message_reports', 'pause_id');
        $this->forge->dropTable('message_restrictions');
    }
}
