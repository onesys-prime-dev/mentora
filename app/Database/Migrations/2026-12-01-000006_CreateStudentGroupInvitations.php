<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * An invited student must accept before becoming a member of a proposed group.
 */
class CreateStudentGroupInvitations extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'group_id'        => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'student_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'status'          => ['type' => 'ENUM', 'constraint' => ['pending', 'accepted', 'declined'], 'default' => 'pending'],
            'invited_at'      => ['type' => 'DATETIME'],
            'responded_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['group_id', 'student_user_id'], 'uq_group_invitation');
        $this->forge->addKey('student_user_id');
        $this->forge->addForeignKey('group_id', 'student_groups', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('student_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('student_group_invitations');
    }

    public function down()
    {
        $this->forge->dropTable('student_group_invitations', true);
    }
}
