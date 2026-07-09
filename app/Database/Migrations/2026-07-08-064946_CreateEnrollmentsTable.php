<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEnrollmentsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([

            'id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],

            'offering_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
            ],

            'student_user_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
            ],

            'status' => [
                'type' => 'ENUM',
                'constraint' => [
                    'enrolled',
                    'dropped',
                    'completed',
                    'withdrawn',
                ],
                'default' => 'enrolled',
            ],

            'enrolled_at' => [
                'type' => 'DATETIME',
            ],

            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('offering_id');

        $this->forge->addKey('student_user_id');

        $this->forge->addKey('status');

        $this->forge->addUniqueKey([
            'offering_id',
            'student_user_id',
        ]);

        $this->forge->addForeignKey(
            'offering_id',
            'course_offerings',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->addForeignKey(
            'student_user_id',
            'users',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('enrollments');
    }

    public function down()
    {
        $this->forge->dropTable('enrollments');
    }
}