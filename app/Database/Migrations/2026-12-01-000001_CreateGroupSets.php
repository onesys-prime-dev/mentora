<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A group set: a named way of dividing one offering's students into groups (manual, auto, self sign-up, proposed).
 */
class CreateGroupSets extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'offering_id'     => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 150],
            'formation_mode'  => ['type' => 'ENUM', 'constraint' => ['manual', 'auto', 'self_signup', 'proposed'], 'default' => 'manual'],
            'min_size'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 1],
            'max_size'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 30],
            'signup_opens_at' => ['type' => 'DATETIME', 'null' => true],
            'signup_closes_at' => ['type' => 'DATETIME', 'null' => true],
            'status'          => ['type' => 'ENUM', 'constraint' => ['active', 'archived'], 'default' => 'active'],
            'created_by'      => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['offering_id', 'status']);
        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('group_sets');
    }

    public function down()
    {
        $this->forge->dropTable('group_sets', true);
    }
}
