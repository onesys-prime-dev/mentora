<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A shared group submission is one row with group_id set (attempts counted per group); per-member and non-group rows
 * keep group_id NULL, and the unique key lets NULLs repeat. group_extensions gives one group more time on one activity.
 */
class AddGroupToAssessmentSubmissions extends Migration
{
    public function up()
    {
        $t = db_connect()->prefixTable('assessment_submissions');

        $this->db->query("ALTER TABLE {$t}
            ADD COLUMN group_id INT UNSIGNED NULL,
            ADD COLUMN submitted_by_user_id INT UNSIGNED NULL,
            ADD CONSTRAINT fk_submission_group FOREIGN KEY (group_id) REFERENCES student_groups (id) ON DELETE SET NULL ON UPDATE CASCADE,
            ADD CONSTRAINT fk_submission_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
            ADD UNIQUE KEY uq_submission_group_attempt (assessment_id, group_id, attempt_number)");

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'group_id'      => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'assessment_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'until_at'      => ['type' => 'DATETIME'],
            'reason'        => ['type' => 'VARCHAR', 'constraint' => 500],
            'created_by'    => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'created_at'    => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['group_id', 'assessment_id'], 'uq_group_extension');
        $this->forge->addKey('assessment_id');
        $this->forge->addForeignKey('group_id', 'student_groups', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('assessment_id', 'assessments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('group_extensions');
    }

    public function down()
    {
        $this->forge->dropTable('group_extensions', true);

        $t = db_connect()->prefixTable('assessment_submissions');
        $this->db->query("ALTER TABLE {$t} DROP FOREIGN KEY fk_submission_group, DROP FOREIGN KEY fk_submission_submitter");
        $this->db->query("ALTER TABLE {$t} DROP INDEX uq_submission_group_attempt, DROP COLUMN group_id, DROP COLUMN submitted_by_user_id");
    }
}
