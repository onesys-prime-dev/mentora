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

            'assessment_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'question_text' => [
                'type' => 'LONGTEXT',
            ],

            'question_type' => [
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

            'is_required' => [
                'type'    => 'BOOLEAN',
                'default' => true,
            ],

            'order_index' => [
                'type'    => 'INT',
                'default' => 1,
            ],

            'created_by' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'created_at' => [
                'type' => 'DATETIME',
            ],

            'updated_by' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'updated_at' => [
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

        $this->forge->addKey('assessment_id');
        $this->forge->addKey('created_by');
        $this->forge->addKey('updated_by');
        $this->forge->addKey(['assessment_id', 'order_index']);

        $this->forge->createTable('assessment_questions');

        $this->db->query("
            ALTER TABLE assessment_questions
            ADD CONSTRAINT fk_question_assessment
            FOREIGN KEY (assessment_id)
            REFERENCES assessments(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_questions
            ADD CONSTRAINT fk_question_created_by
            FOREIGN KEY (created_by)
            REFERENCES users(id)
            ON DELETE RESTRICT
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_questions
            ADD CONSTRAINT fk_question_updated_by
            FOREIGN KEY (updated_by)
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
