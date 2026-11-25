<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A new email address waiting for its confirmation link; only the token's hash is stored. */
class CreatePendingEmailChanges extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'request_id'   => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'new_email'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'token_hash'   => ['type' => 'CHAR', 'constraint' => 64],
            'expires_at'   => ['type' => 'DATETIME'],
            'confirmed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('request_id', 'profile_correction_requests', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('pending_email_changes');
    }

    public function down()
    {
        $this->forge->dropTable('pending_email_changes', true);
    }
}
