<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssessmentQuestionChoices extends Migration
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

            'questionId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'choiceText' => [
                'type' => 'TEXT',
            ],

            'isCorrect' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'orderIndex' => [
                'type'    => 'INT',
                'default' => 1,
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
        $this->forge->addKey('questionId');
        $this->forge->addKey(['questionId', 'orderIndex']);

        $this->forge->createTable('assessment_question_choices');

        $this->db->query("
            ALTER TABLE assessment_question_choices
            ADD CONSTRAINT fk_choice_question
            FOREIGN KEY (questionId)
            REFERENCES assessment_questions(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('assessment_question_choices', true);
    }
}