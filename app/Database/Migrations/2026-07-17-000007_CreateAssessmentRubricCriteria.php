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

            'rubric_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'criteria_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'max_points' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0,
            ],

            'order_index' => [
                'type'    => 'INT',
                'default' => 1,
            ],

            // Reserved for future rubric enhancements
            // e.g. Excellent, Good, Fair, Poor
            'performance_level' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
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
        $this->forge->addKey('rubric_id');
        $this->forge->addKey(['rubric_id', 'order_index']);

        $this->forge->createTable('assessment_rubric_criteria');

        $this->db->query("
            ALTER TABLE assessment_rubric_criteria
            ADD CONSTRAINT fk_rubric_criteria_rubric
            FOREIGN KEY (rubric_id)
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
