<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One leader vote per member per group; a nomination is a vote.
 */
class CreateStudentGroupLeaderVotes extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'group_id'          => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'voter_user_id'     => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'candidate_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'voted_at'          => ['type' => 'DATETIME'],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['group_id', 'voter_user_id'], 'uq_group_leader_vote');
        $this->forge->addKey('voter_user_id');
        $this->forge->addKey('candidate_user_id');
        $this->forge->addForeignKey('group_id', 'student_groups', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('voter_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('candidate_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('student_group_leader_votes');
    }

    public function down()
    {
        $this->forge->dropTable('student_group_leader_votes', true);
    }
}
