<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAiGeneratedQuestions extends Migration
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

            'batch_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'question_text' => [
                'type' => 'TEXT',
            ],

            'question_type' => [
                'type'       => 'ENUM',
                'constraint' => ['Multiple Choice', 'Short Answer', 'Essay', 'File Upload'],
            ],

            'points' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0,
            ],

            'is_required' => [
                'type'    => 'BOOLEAN',
                'default' => true,
            ],

            'choices' => [
                'type' => 'JSON',
                'null' => true,
                'comment' => 'Array of {text, is_correct} for Multiple Choice',
            ],

            'similarity_score' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,4',
                'null'       => true,
                'comment'    => 'Best-window overlap ratio vs source material, 0-1',
            ],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'accepted', 'discarded'],
                'default'    => 'pending',
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

        $this->forge->addKey('batch_id');
        $this->forge->addKey(['batch_id', 'status']);

        $this->forge->createTable('ai_generated_questions');

        $this->db->query("
            ALTER TABLE ai_generated_questions
            ADD CONSTRAINT fk_ai_generated_question_batch
            FOREIGN KEY (batch_id)
            REFERENCES ai_generation_batches(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('ai_generated_questions', true);
    }
}
