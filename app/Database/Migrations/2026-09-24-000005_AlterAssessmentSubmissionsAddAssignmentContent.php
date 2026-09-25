<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAssessmentSubmissionsAddAssignmentContent extends Migration
{
    public function up()
    {
        $this->forge->addColumn('assessment_submissions', [

            'submission_text' => [
                'type'  => 'LONGTEXT',
                'null'  => true,
                'after' => 'time_spent',
            ],

            'submission_link' => [
                'type'       => 'VARCHAR',
                'constraint' => 2048,
                'null'       => true,
                'after'      => 'submission_text',
            ],

            'submission_file_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'submission_link',
            ],

        ]);

        // SET NULL: a file row being cleaned up shouldn't delete the
        // student's attempt (or its grade) along with it.
        $this->db->query("
            ALTER TABLE assessment_submissions
            ADD CONSTRAINT fk_submission_file
            FOREIGN KEY (submission_file_id)
            REFERENCES files(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->db->query('ALTER TABLE assessment_submissions DROP FOREIGN KEY fk_submission_file');

        $this->forge->dropColumn('assessment_submissions', ['submission_text', 'submission_link', 'submission_file_id']);
    }
}
