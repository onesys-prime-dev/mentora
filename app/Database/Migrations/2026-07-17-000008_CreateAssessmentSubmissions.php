<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssessmentSubmissions extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'assessment_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'student_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'attempt_number' => [
                'type'    => 'INT',
                'default' => 1,
            ],

            'started_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'submitted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'time_spent' => [
                'type'       => 'INT',
                'null'       => true,
                'comment'    => 'Time spent in seconds',
            ],

            'score' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0,
            ],

            'percentage' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0,
            ],

            'is_late_submission' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'is_graded' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'graded_by' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'graded_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'feedback' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'In Progress',
                    'Submitted',
                    'Graded',
                    'Returned',
                    'Cancelled'
                ],
                'default' => 'In Progress',
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('assessment_id');
        $this->forge->addKey('student_id');
        $this->forge->addKey('graded_by');
        $this->forge->addKey(['assessment_id', 'student_id']);
        $this->forge->addKey(['student_id', 'status']);

        $this->forge->createTable('assessment_submissions');

        $this->db->query("
            ALTER TABLE assessment_submissions
            ADD CONSTRAINT fk_submission_assessment
            FOREIGN KEY (assessment_id)
            REFERENCES assessments(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_submissions
            ADD CONSTRAINT fk_submission_student
            FOREIGN KEY (student_id)
            REFERENCES users(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_submissions
            ADD CONSTRAINT fk_submission_graded_by
            FOREIGN KEY (graded_by)
            REFERENCES users(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('assessment_submissions', true);
    }
}
