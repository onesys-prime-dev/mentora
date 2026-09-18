<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateGradebookRecords extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'course_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            'student_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            // System calculated grade
            'calculated_final_grade' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0,
            ],

            // Instructor override
            'overridden_final_grade' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
            ],

            'is_override_final_grade' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
            ],

            // Percentage before conversion
            'overall_percentage' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0,
            ],

            'remarks' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'Passed',
                    'Failed',
                    'Incomplete',
                    'Withdrawn'
                ],
                'default' => 'Incomplete',
            ],

            'instructor_remarks' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'override_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'last_calculated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'created_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],

            'created_at' => [
                'type' => 'DATETIME',
            ],

            'updated_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
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
            ]
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addUniqueKey([
            'course_id',
            'student_id'
        ]);

        $this->forge->addForeignKey(
            'course_id',
            'courses',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->addForeignKey(
            'student_id',
            'users',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('gradebook_records');
    }

    public function down()
    {
        $this->forge->dropTable('gradebook_records');
    }
}
