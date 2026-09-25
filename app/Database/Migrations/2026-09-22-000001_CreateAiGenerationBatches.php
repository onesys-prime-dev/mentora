<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAiGenerationBatches extends Migration
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
                'null'       => true,
            ],

            'course_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'source_type' => [
                'type'       => 'ENUM',
                'constraint' => ['topic', 'text', 'file'],
            ],

            'source_file_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'requested_count' => [
                'type'    => 'INT',
                'default' => 10,
            ],

            'generated_count' => [
                'type'    => 'INT',
                'default' => 0,
            ],

            'points_per_question' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'null'       => true,
            ],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'generating', 'completed', 'failed'],
                'default'    => 'pending',
            ],

            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'created_by' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'created_at' => [
                'type' => 'DATETIME',
            ],

        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('assessment_id');
        $this->forge->addKey('course_id');
        $this->forge->addKey('source_file_id');
        $this->forge->addKey('created_by');

        $this->forge->createTable('ai_generation_batches');

        $this->db->query("
            ALTER TABLE ai_generation_batches
            ADD CONSTRAINT fk_ai_batch_assessment
            FOREIGN KEY (assessment_id)
            REFERENCES assessments(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE ai_generation_batches
            ADD CONSTRAINT fk_ai_batch_course
            FOREIGN KEY (course_id)
            REFERENCES courses(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE ai_generation_batches
            ADD CONSTRAINT fk_ai_batch_file
            FOREIGN KEY (source_file_id)
            REFERENCES files(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE ai_generation_batches
            ADD CONSTRAINT fk_ai_batch_created_by
            FOREIGN KEY (created_by)
            REFERENCES users(id)
            ON DELETE RESTRICT
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('ai_generation_batches', true);
    }
}
