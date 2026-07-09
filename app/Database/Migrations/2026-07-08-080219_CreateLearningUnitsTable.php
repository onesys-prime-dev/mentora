<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLearningUnitsTable extends Migration
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

            'module_id' => [
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

        $this->forge->addKey('module_id');
        $this->forge->addKey('status');

        $this->forge->addUniqueKey(['module_id', 'order_index']);

        $this->forge->addForeignKey(
            'module_id',
            'course_modules',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('learning_units');
    }

    public function down()
    {
        $this->forge->dropTable('learning_units', true);
    }
}