<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInstitutionUsersTable extends Migration
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

            'user_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
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
        $this->forge->addKey('user_id');

        // Prevent duplicate memberships
        $this->forge->addUniqueKey(
            ['institution_id', 'user_id'],
            'institution_user_unique'
        );

        $this->forge->addForeignKey(
            'institution_id',
            'institutions',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->addForeignKey(
            'user_id',
            'users',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('institution_users');
    }

    public function down()
    {
        $this->forge->dropTable('institution_users');
    }
}