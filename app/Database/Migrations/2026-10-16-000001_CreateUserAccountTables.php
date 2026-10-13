<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What user administration (SA5) needs beyond Shield's own tables.
 *
 * `user_account_controls` holds, per user, the two things the access check
 * looks at on every request:
 *   deactivated_at       the account was switched off; its owner is sent away
 *                        at once, whatever session they have.
 *   sessions_valid_from  every session that signed in at or before this moment
 *                        is ended ("sign out everywhere", a password reset).
 * A user with no row here is simply in good standing, so nobody is locked out
 * by this migration. (Shield itself only looks at "banned" while signing in and
 * never again for a person who is already in, which is why this exists.)
 *
 * `password_resets` is one emailed link that lets a person choose a new
 * password. Like an invitation, only the SHA-256 hash of the link's secret is
 * stored; it is used once and it expires.
 *
 * Both tables go when their user is removed.
 */
class CreateUserAccountTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'deactivated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'sessions_valid_from' => [
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

        $this->forge->addKey('user_id', true);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_account_controls');

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'token_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'sent_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'used_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'revoked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'requested_by_user_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'requested_by_label' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
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
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('password_resets');
    }

    public function down()
    {
        $this->forge->dropTable('password_resets', true);
        $this->forge->dropTable('user_account_controls', true);
    }
}
