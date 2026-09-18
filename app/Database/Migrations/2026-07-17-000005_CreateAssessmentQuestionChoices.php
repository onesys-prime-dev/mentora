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

            'question_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'choice_text' => [
                'type' => 'TEXT',
            ],

            'is_correct' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'order_index' => [
                'type'    => 'INT',
                'default' => 1,
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
        $this->forge->addKey('question_id');
        $this->forge->addKey(['question_id', 'order_index']);

        $this->forge->createTable('assessment_question_choices');

        $this->db->query("
            ALTER TABLE assessment_question_choices
            ADD CONSTRAINT fk_choice_question
            FOREIGN KEY (question_id)
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
