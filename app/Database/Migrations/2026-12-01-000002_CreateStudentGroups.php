<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A student group: belongs to a set (set_id) or, once copied onto an activity, to that activity's config (config_id).
 */
class CreateStudentGroups extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'set_id'             => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            // No foreign key yet: activity_group_configs is created in Phase 3, which adds it.
            'config_id'          => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'name'               => ['type' => 'VARCHAR', 'constraint' => 150],
            'leader_user_id'     => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'leader_finalized_at' => ['type' => 'DATETIME', 'null' => true],
            'status'             => ['type' => 'ENUM', 'constraint' => ['active', 'proposed', 'declined'], 'default' => 'active'],
            'proposed_by'        => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'frozen_at'          => ['type' => 'DATETIME', 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('set_id');
        $this->forge->addKey('config_id');
        $this->forge->addForeignKey('set_id', 'group_sets', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('leader_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('proposed_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('student_groups');
    }

    public function down()
    {
        $this->forge->dropTable('student_groups', true);
    }
}
