<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per graded member of a group submission: the group part, the member's own part, any adjustment with its
 * reason, a private note and the final score. submission_id is the shared group row, or the member's own row when
 * members submit separately. Scores use the precision of assessment_submissions.score / percentage.
 */
class CreateGroupMemberScores extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                       => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'submission_id'            => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'student_user_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'group_id'                 => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'group_points'             => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'individual_points'        => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'individual_rubric_scores' => ['type' => 'JSON', 'null' => true],
            'adjustment'               => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 0],
            'adjustment_reason'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'private_note'             => ['type' => 'TEXT', 'null' => true],
            'final_score'              => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'final_percentage'         => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'excused'                  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'pending'                  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'graded_by'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'graded_at'                => ['type' => 'DATETIME', 'null' => true],
            'created_at'               => ['type' => 'DATETIME', 'null' => true],
            'updated_at'               => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['submission_id', 'student_user_id'], 'uq_group_member_score');
        $this->forge->addKey('student_user_id');
        $this->forge->addKey('group_id');
        $this->forge->addForeignKey('submission_id', 'assessment_submissions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('student_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('group_id', 'student_groups', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('graded_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('group_member_scores');
    }

    public function down()
    {
        $this->forge->dropTable('group_member_scores', true);
    }
}
