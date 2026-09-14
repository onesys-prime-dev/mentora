<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssessments extends Migration
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

            'course_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'module_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'learning_item_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'assessment_type_id' => [
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

            'instructions' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],

            'available_from' => [
                'type' => 'DATETIME',
            ],

            'due_date' => [
                'type' => 'DATETIME',
            ],

            'duration' => [
                'type' => 'INT',
                'null' => true,
                'comment' => 'Minutes'
            ],

            'total_points' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0,
            ],

            'weight_percentage' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0,
            ],

            'attempt_limit' => [
                'type'    => 'INT',
                'default' => 1,
            ],

            'passing_score' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'null'       => true,
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
                    'Draft',
                    'Published',
                    'Closed',
                    'Archived'
                ],
                'default' => 'Draft',
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('course_id');
        $this->forge->addKey('module_id');
        $this->forge->addKey('learning_item_id');
        $this->forge->addKey('assessment_type_id');
        $this->forge->addKey('created_by');
        $this->forge->addKey('updated_by');

        $this->forge->createTable('assessments');

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_module FOREIGN KEY(module_id) REFERENCES course_modules(id) ON DELETE SET NULL ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_learning_item FOREIGN KEY(learning_item_id) REFERENCES learning_items(id) ON DELETE SET NULL ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_type FOREIGN KEY(assessment_type_id) REFERENCES assessment_types(id) ON DELETE RESTRICT ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_updated_by FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE");
    }

    public function down()
    {
        $this->forge->dropTable('assessments', true);
    }
}
