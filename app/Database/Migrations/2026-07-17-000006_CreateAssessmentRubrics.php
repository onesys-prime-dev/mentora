<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssessmentRubrics extends Migration
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

            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'total_points' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0,
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
        $this->forge->addKey('question_id');
        $this->forge->addKey('created_by');
        $this->forge->addKey('updated_by');

        $this->forge->createTable('assessment_rubrics');

        $this->db->query("
            ALTER TABLE assessment_rubrics
            ADD CONSTRAINT fk_rubric_question
            FOREIGN KEY (question_id)
            REFERENCES assessment_questions(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_rubrics
            ADD CONSTRAINT fk_rubric_created_by
            FOREIGN KEY (created_by)
            REFERENCES users(id)
            ON DELETE RESTRICT
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_rubrics
            ADD CONSTRAINT fk_rubric_updated_by
            FOREIGN KEY (updated_by)
            REFERENCES users(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('assessment_rubrics', true);
    }
}
