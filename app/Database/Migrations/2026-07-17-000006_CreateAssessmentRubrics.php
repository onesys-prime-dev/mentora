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

            'questionId' => [
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

            'totalPoints' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0,
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
        $this->forge->addKey('questionId');
        $this->forge->addKey('createdBy');
        $this->forge->addKey('updatedBy');

        $this->forge->createTable('assessment_rubrics');

        $this->db->query("
            ALTER TABLE assessment_rubrics
            ADD CONSTRAINT fk_rubric_question
            FOREIGN KEY (questionId)
            REFERENCES assessment_questions(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_rubrics
            ADD CONSTRAINT fk_rubric_created_by
            FOREIGN KEY (createdBy)
            REFERENCES users(id)
            ON DELETE RESTRICT
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_rubrics
            ADD CONSTRAINT fk_rubric_updated_by
            FOREIGN KEY (updatedBy)
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