<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Changes a system administrator has made to the default role/permission
 * matrix (Config\AuthGroups::DEFAULT_MATRIX). Only differences from the
 * defaults are stored: a row with granted = 1 adds a permission a role does
 * not have by default, granted = 0 takes one away. Everything else follows
 * the defaults, so a permission added in a later release reaches every role
 * that has not been customised.
 */
class CreateRolePermissionsTable extends Migration
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

            'role' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],

            'permission' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],

            'granted' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
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
        $this->forge->addUniqueKey(['role', 'permission']);

        $this->forge->createTable('role_permissions');
    }

    public function down()
    {
        $this->forge->dropTable('role_permissions');
    }
}
