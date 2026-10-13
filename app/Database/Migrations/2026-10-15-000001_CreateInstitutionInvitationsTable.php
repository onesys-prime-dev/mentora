<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Invitations to join an institution (SA4): the first institutional
 * administrator is invited by email and sets their own password through a link.
 *
 * The person has no account until they accept, so the invitation holds who
 * they are (name, email) and which role they will get. The link's secret is
 * never stored: only its SHA-256 hash is, so a copy of this table cannot be
 * used to accept an invitation. An invitation is single-use (`accepted_at`),
 * expires (`expires_at`), and is replaced rather than edited: sending a new
 * one revokes the open one (`revoked_at`).
 *
 * `sent_at` stays empty until the email was handed to the mail server, so an
 * invitation whose email failed can be told apart and re-sent.
 *
 * Removing an institution (a purge) removes its invitations with it.
 */
class CreateInstitutionInvitationsTable extends Migration
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

            // The Shield group the person gets when they accept.
            'role' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],

            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 254,
            ],

            'first_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],

            'last_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
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

            'accepted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'accepted_user_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'revoked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'invited_by_user_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'invited_by_label' => [
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
        $this->forge->addKey(['institution_id', 'role']);
        $this->forge->addKey('email');

        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('institution_invitations');
    }

    public function down()
    {
        $this->forge->dropTable('institution_invitations', true);
    }
}
