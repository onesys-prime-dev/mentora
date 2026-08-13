<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssessmentRubricCriteria extends Migration
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

            'rubricId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'criteriaTitle' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'maxPoints' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0,
            ],

            'orderIndex' => [
                'type'    => 'INT',
                'default' => 1,
            ],

            // Reserved for future rubric enhancements
            // e.g. Excellent, Good, Fair, Poor
            'performanceLevel' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
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
        $this->forge->addKey('rubricId');
        $this->forge->addKey(['rubricId', 'orderIndex']);

        $this->forge->createTable('assessment_rubric_criteria');

        $this->db->query("
            ALTER TABLE assessment_rubric_criteria
            ADD CONSTRAINT fk_rubric_criteria_rubric
            FOREIGN KEY (rubricId)
            REFERENCES assessment_rubrics(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('assessment_rubric_criteria', true);
    }
}