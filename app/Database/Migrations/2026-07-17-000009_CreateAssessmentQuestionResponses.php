<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssessmentQuestionResponses extends Migration
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

            'submission_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'question_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            // Multiple Choice
            'selected_choice_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            // Essay, Discussion, Short Answer
            'response_text' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],

            // File Upload
            'uploaded_file_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            // Score for this specific question
            'score' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0,
            ],

            // Instructor feedback for this question
            'feedback' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            // Auto-graded?
            'is_auto_graded' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'graded_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'created_at' => [
                'type' => 'DATETIME',
            ],

            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('submission_id');
        $this->forge->addKey('question_id');
        $this->forge->addKey('selected_choice_id');
        $this->forge->addKey('uploaded_file_id');
        $this->forge->addKey(['submission_id', 'question_id']);

        $this->forge->createTable('assessment_question_responses');

        $this->db->query("
            ALTER TABLE assessment_question_responses
            ADD CONSTRAINT fk_response_submission
            FOREIGN KEY (submission_id)
            REFERENCES assessment_submissions(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_question_responses
            ADD CONSTRAINT fk_response_question
            FOREIGN KEY (question_id)
            REFERENCES assessment_questions(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_question_responses
            ADD CONSTRAINT fk_response_choice
            FOREIGN KEY (selected_choice_id)
            REFERENCES assessment_question_choices(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_question_responses
            ADD CONSTRAINT fk_response_file
            FOREIGN KEY (uploaded_file_id)
            REFERENCES files(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('assessment_question_responses', true);
    }
}
