<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCoursesTable extends Migration
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

            'program_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'course_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],

            'course_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'units' => [
                'type'       => 'DECIMAL',
                'constraint' => '4,1',
            ],

            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'active',
            ],

            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('program_id');

        $this->forge->addKey('status');

        $this->forge->addUniqueKey([
            'program_id',
            'course_code',
        ]);

        $this->forge->addForeignKey(
            'program_id',
            'programs',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('courses');
    }

    public function down()
    {
        $this->forge->dropTable('courses');
    }
}