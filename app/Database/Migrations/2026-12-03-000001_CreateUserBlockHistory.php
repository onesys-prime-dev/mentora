<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages: blocks that stopped binding are kept here, not deleted (see ResetNonBindingBlocks).
 * `blocked_at` is when the block was first made; `moved_at` when it left user_blocks; `reason` says why.
 */
class CreateUserBlockHistory extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'unsigned' => true],
            'blocker_id' => ['type' => 'INT', 'unsigned' => true],
            'blocked_id' => ['type' => 'INT', 'unsigned' => true],
            'blocked_at' => ['type' => 'DATETIME', 'null' => true],
            'moved_at' => ['type' => 'DATETIME', 'null' => true],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 30],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('blocker_id');
        $this->forge->addKey('institution_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('blocker_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('blocked_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_block_history');
    }

    public function down()
    {
        $this->forge->dropTable('user_block_history');
    }
}
