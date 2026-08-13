<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssessmentQuestions extends Migration
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

            'questionText' => [
                'type' => 'LONGTEXT',
            ],

            'questionType' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'Multiple Choice',
                    'Essay',
                    'Discussion',
                    'Short Answer',
                    'File Upload'
                ],
            ],

            'points' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 1,
            ],

            'isRequired' => [
                'type'    => 'BOOLEAN',
                'default' => true,
            ],

            'orderIndex' => [
                'type'    => 'INT',
                'default' => 1,
            ],

            'createdBy' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'createdAt' => [
                'type' => 'DATETIME',
            ],

            'updatedBy' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'updatedAt' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'Active',
                    'Archived'
                ],
                'default' => 'Active',
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('assessmentId');
        $this->forge->addKey('createdBy');
        $this->forge->addKey('updatedBy');
        $this->forge->addKey(['assessmentId', 'orderIndex']);

        $this->forge->createTable('assessment_questions');

        $this->db->query("
            ALTER TABLE assessment_questions
            ADD CONSTRAINT fk_question_assessment
            FOREIGN KEY (assessmentId)
            REFERENCES assessments(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_questions
            ADD CONSTRAINT fk_question_created_by
            FOREIGN KEY (createdBy)
            REFERENCES users(id)
            ON DELETE RESTRICT
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_questions
            ADD CONSTRAINT fk_question_updated_by
            FOREIGN KEY (updatedBy)
            REFERENCES users(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('assessment_questions', true);
    }
}