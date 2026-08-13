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

            'courseId' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            'studentId' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            // System calculated grade
            'calculatedFinalGrade' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0,
            ],

            // Instructor override
            'overriddenFinalGrade' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
            ],

            'isOverrideFinalGrade' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
            ],

            // Percentage before conversion
            'overallPercentage' => [
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

            'instructorRemarks' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'overrideReason' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'lastCalculatedAt' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'createdBy' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],

            'createdAt' => [
                'type' => 'DATETIME',
            ],

            'updatedBy' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
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
            ]
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addUniqueKey([
            'courseId',
            'studentId'
        ]);

        $this->forge->addForeignKey(
            'courseId',
            'courses',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->addForeignKey(
            'studentId',
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