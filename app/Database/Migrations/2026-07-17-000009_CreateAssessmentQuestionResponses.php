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

            'submissionId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'questionId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            // Multiple Choice
            'selectedChoiceId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            // Essay, Discussion, Short Answer
            'responseText' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],

            // File Upload
            'uploadedFileId' => [
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
            'isAutoGraded' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'gradedAt' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'createdAt' => [
                'type' => 'DATETIME',
            ],

            'updatedAt' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('submissionId');
        $this->forge->addKey('questionId');
        $this->forge->addKey('selectedChoiceId');
        $this->forge->addKey('uploadedFileId');
        $this->forge->addKey(['submissionId', 'questionId']);

        $this->forge->createTable('assessment_question_responses');

        $this->db->query("
            ALTER TABLE assessment_question_responses
            ADD CONSTRAINT fk_response_submission
            FOREIGN KEY (submissionId)
            REFERENCES assessment_submissions(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_question_responses
            ADD CONSTRAINT fk_response_question
            FOREIGN KEY (questionId)
            REFERENCES assessment_questions(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_question_responses
            ADD CONSTRAINT fk_response_choice
            FOREIGN KEY (selectedChoiceId)
            REFERENCES assessment_question_choices(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_question_responses
            ADD CONSTRAINT fk_response_file
            FOREIGN KEY (uploadedFileId)
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