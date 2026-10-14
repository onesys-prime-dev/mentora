<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDeanAssignmentsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'department_id' => ['type' => 'INT', 'unsigned' => true],
            'dean_user_id' => ['type' => 'INT', 'unsigned' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'active_marker' => ['type' => 'TINYINT', 'null' => true],
            'assigned_at' => ['type' => 'DATETIME'],
            'ended_at' => ['type' => 'DATETIME', 'null' => true],
            'assigned_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('department_id');
        $this->forge->addKey('dean_user_id');
        $this->forge->addKey('status');
        $this->forge->addUniqueKey(['department_id', 'active_marker']);
        $this->forge->addUniqueKey(['dean_user_id', 'active_marker']);
        $this->forge->addForeignKey('department_id', 'departments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('dean_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('assigned_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('dean_assignments');
    }

    public function down()
    {
        $this->forge->dropTable('dean_assignments');
    }
}