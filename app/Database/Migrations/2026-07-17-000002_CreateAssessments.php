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

            'courseId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'moduleId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'learningItemId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'assessmentTypeId' => [
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

            'availableFrom' => [
                'type' => 'DATETIME',
            ],

            'dueDate' => [
                'type' => 'DATETIME',
            ],

            'duration' => [
                'type' => 'INT',
                'null' => true,
                'comment' => 'Minutes'
            ],

            'totalPoints' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0,
            ],

            'weightPercentage' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0,
            ],

            'attemptLimit' => [
                'type'    => 'INT',
                'default' => 1,
            ],

            'passingScore' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'null'       => true,
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
                    'Draft',
                    'Published',
                    'Closed',
                    'Archived'
                ],
                'default' => 'Draft',
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('courseId');
        $this->forge->addKey('moduleId');
        $this->forge->addKey('learningItemId');
        $this->forge->addKey('assessmentTypeId');
        $this->forge->addKey('createdBy');
        $this->forge->addKey('updatedBy');

        $this->forge->createTable('assessments');

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_course FOREIGN KEY(courseId) REFERENCES courses(id) ON DELETE CASCADE ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_module FOREIGN KEY(moduleId) REFERENCES course_modules(id) ON DELETE SET NULL ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_learning_item FOREIGN KEY(learningItemId) REFERENCES learning_items(id) ON DELETE SET NULL ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_type FOREIGN KEY(assessmentTypeId) REFERENCES assessment_types(id) ON DELETE RESTRICT ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_created_by FOREIGN KEY(createdBy) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE");

        $this->db->query("ALTER TABLE assessments ADD CONSTRAINT fk_assessment_updated_by FOREIGN KEY(updatedBy) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE");
    }

    public function down()
    {
        $this->forge->dropTable('assessments', true);
    }
}