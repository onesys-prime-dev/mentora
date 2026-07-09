<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCourseModulesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'offering_id' => [
                'type'       => 'INT',
                'constraint' => 11,
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

            'order_index' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'draft',
                    'published',
                    'inactive',
                ],
                'default'    => 'draft',
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
        $this->forge->addKey('offering_id');
        $this->forge->addKey('status');

        $this->forge->addUniqueKey(['offering_id', 'order_index']);

        $this->forge->addForeignKey(
            'offering_id',
            'course_offerings',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('course_modules');
    }

    public function down()
    {
        $this->forge->dropTable('course_modules');
    }
}