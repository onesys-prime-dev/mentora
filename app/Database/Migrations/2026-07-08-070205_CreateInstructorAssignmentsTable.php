<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInstructorAssignmentsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'offering_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            'instructor_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            'assigned_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],

            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('offering_id');
        $this->forge->addKey('instructor_user_id');

        $this->forge->addUniqueKey([
            'offering_id',
            'instructor_user_id',
        ]);

        $this->forge->addForeignKey(
            'offering_id',
            'course_offerings',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->addForeignKey(
            'instructor_user_id',
            'users',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('instructor_assignments');
    }

    public function down()
    {
        $this->forge->dropTable('instructor_assignments');
    }
}