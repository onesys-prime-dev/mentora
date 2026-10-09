<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Subscription plans (SA13): what a customer is sold. A plan grants a set of
 * features (feature flag keys, kept as a JSON list) and may cap student
 * seats, storage and AI generations. A cap left empty means unlimited.
 *
 * A plan is retired, never deleted: institutions already on it keep it.
 */
class CreatePlansTable extends Migration
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

            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],

            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            // JSON list of feature flag keys the plan grants, e.g. ["sis.sync"].
            'features' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            // Caps. NULL = unlimited.
            'seat_limit' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],

            'storage_limit_mb' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],

            // AI question generations per academic term.
            'ai_generation_limit' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'retired'],
                'default'    => 'active',
            ],

            'retired_at' => [
                'type' => 'DATETIME',
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
        $this->forge->addUniqueKey('name');
        $this->forge->addKey('status');

        $this->forge->createTable('plans');
    }

    public function down()
    {
        $this->forge->dropTable('plans');
    }
}
