<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLearningResourcesTable extends Migration
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

            'learning_item_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],

            'file_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],

            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'resource_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],

            'resource_url' => [
                'type' => 'TEXT',
                'null' => true,
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

        $this->forge->addKey('learning_item_id');
        $this->forge->addKey('file_id');
        $this->forge->addKey('resource_type');

        $this->forge->addForeignKey(
            'learning_item_id',
            'learning_items',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->addForeignKey(
            'file_id',
            'files',
            'id',
            'SET NULL',
            'CASCADE'
        );

        $this->forge->createTable('learning_resources');
    }

    public function down()
    {
        $this->forge->dropTable('learning_resources', true);
    }
}