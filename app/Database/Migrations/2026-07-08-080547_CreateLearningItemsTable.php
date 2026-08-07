<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLearningItemsTable extends Migration
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

            'learning_unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],

            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'item_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],

            'content' => [
                'type' => 'LONGTEXT',
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

        $this->forge->addKey('learning_unit_id');
        $this->forge->addKey('item_type');
        $this->forge->addKey('status');

        $this->forge->addKey([
            'learning_unit_id',
            'order_index',
        ]);

        $this->forge->addForeignKey(
            'learning_unit_id',
            'learning_units',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('learning_items');
    }

    public function down()
    {
        $this->forge->dropTable('learning_items', true);
    }
}