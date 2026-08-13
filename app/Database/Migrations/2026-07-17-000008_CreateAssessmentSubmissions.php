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

            'assessmentId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'studentId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'attemptNumber' => [
                'type'    => 'INT',
                'default' => 1,
            ],

            'startedAt' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'submittedAt' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'timeSpent' => [
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

            'isLateSubmission' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'isGraded' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'gradedBy' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'gradedAt' => [
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

        $this->forge->addKey('assessmentId');
        $this->forge->addKey('studentId');
        $this->forge->addKey('gradedBy');
        $this->forge->addKey(['assessmentId', 'studentId']);
        $this->forge->addKey(['studentId', 'status']);

        $this->forge->createTable('assessment_submissions');

        $this->db->query("
            ALTER TABLE assessment_submissions
            ADD CONSTRAINT fk_submission_assessment
            FOREIGN KEY (assessmentId)
            REFERENCES assessments(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_submissions
            ADD CONSTRAINT fk_submission_student
            FOREIGN KEY (studentId)
            REFERENCES users(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_submissions
            ADD CONSTRAINT fk_submission_graded_by
            FOREIGN KEY (gradedBy)
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