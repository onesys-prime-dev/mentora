<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFilesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'owner_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            'original_filename' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'stored_filename' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'mime_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],

            'file_size' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            'storage_path' => [
                'type' => 'TEXT',
            ],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'uploading',
                    'active',
                    'archived',
                ],
                'default'    => 'uploading',
            ],

            'uploaded_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('owner_user_id');
        $this->forge->addKey('status');

        $this->forge->addForeignKey(
            'owner_user_id',
            'users',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('files');
    }

    public function down()
    {
        $this->forge->dropTable('files');
    }
}