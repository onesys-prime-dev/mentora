<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The audit log: one row per thing that happened, written once and never
 * changed (see AuditLogModel). There are deliberately no foreign keys, so an
 * entry outlives the user, institution or record it is about; the actor is
 * also kept as a label for the same reason.
 */
class CreateAuditLogsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([

            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            // Who did it. Null for a failed sign-in (nobody is signed in) and for the system itself.
            'actor_user_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            // Email or username at the time, or the address tried in a failed sign-in.
            'actor_label' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],

            // The role they were acting as (admin, chairperson, instructor, ...).
            'acting_role' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],

            // Set when a system administrator was viewing as the actor.
            'impersonator_user_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            // Dotted name, e.g. auth.login, auth.login_failed, institution.deleted.
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],

            'target_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],

            'target_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],

            'institution_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            // Always recorded; whether it is shown is a setting (auditShowSourceIp).
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],

            // JSON, with secrets already redacted.
            'old_values' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'new_values' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'created_at' => [
                'type' => 'DATETIME',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('created_at');
        $this->forge->addKey('actor_user_id');
        $this->forge->addKey('action');
        $this->forge->addKey('institution_id');
        $this->forge->addKey(['target_type', 'target_id']);

        $this->forge->createTable('audit_logs');
    }

    public function down()
    {
        $this->forge->dropTable('audit_logs');
    }
}
