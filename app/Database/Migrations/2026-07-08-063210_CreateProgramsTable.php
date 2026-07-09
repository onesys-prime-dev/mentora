<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProgramsTable extends Migration
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

            'institution_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],

            'status' => [
                'type' => 'ENUM',
                'constraint' => [
                    'active',
                    'inactive',
                    'archived',
                ],
                'default' => 'active',
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

        $this->forge->addKey('institution_id');

        $this->forge->addKey('status');

        $this->forge->addUniqueKey([
            'institution_id',
            'code',
        ]);

        $this->forge->addForeignKey(
            'institution_id',
            'institutions',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('programs');
    }

    public function down()
    {
        $this->forge->dropTable('programs');
    }
}