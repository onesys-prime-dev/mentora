<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateChairpersonAssignmentsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'program_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            'chairperson_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],

            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'active',
            ],

            'assigned_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],

            'created_at DATETIME NULL',
            'updated_at DATETIME NULL',
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('program_id');
        $this->forge->addKey('chairperson_user_id');
        $this->forge->addKey('status');

        $this->forge->addUniqueKey([
            'program_id',
            'chairperson_user_id',
        ]);

        $this->forge->addForeignKey(
            'program_id',
            'programs',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->addForeignKey(
            'chairperson_user_id',
            'users',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('chairperson_assignments');
    }

    public function down()
    {
        $this->forge->dropTable('chairperson_assignments');
    }
}