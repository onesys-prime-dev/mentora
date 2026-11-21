<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Who is in a group. The two unique keys make "one group per student per set / per activity" a database rule;
 * a NULL set_id or config_id never collides, so a row only counts against the scope it belongs to.
 */
class CreateStudentGroupMembers extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'group_id'        => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'set_id'          => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            // No foreign key yet: activity_group_configs is created in Phase 3, which adds it.
            'config_id'       => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'student_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'joined_at'       => ['type' => 'DATETIME'],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('group_id');
        $this->forge->addUniqueKey(['set_id', 'student_user_id'], 'uq_group_member_set');
        $this->forge->addUniqueKey(['config_id', 'student_user_id'], 'uq_group_member_config');
        $this->forge->addForeignKey('group_id', 'student_groups', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('set_id', 'group_sets', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('student_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('student_group_members');
    }

    public function down()
    {
        $this->forge->dropTable('student_group_members', true);
    }
}
