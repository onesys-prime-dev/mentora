<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Student information system (SIS) integration, per institution (SA10).
 *
 * `sis_connections`: at most one per institution. `credentials` holds the
 * connector's secrets sealed by CredentialVault (never plain text, never
 * shown back). `sis_runs`: the history of every test, inbound and outbound
 * run, with the counts the administrator wants to see.
 *
 * Removing an institution removes both with it.
 */
class CreateSisTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'connector' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'file'],
            'enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'credentials' => ['type' => 'TEXT', 'null' => true],
            'credentials_set_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_by_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('institution_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sis_connections');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'direction' => ['type' => 'ENUM', 'constraint' => ['inbound', 'outbound'], 'default' => 'inbound'],
            'trigger' => ['type' => 'ENUM', 'constraint' => ['manual', 'scheduled', 'test'], 'default' => 'manual'],
            'status' => ['type' => 'ENUM', 'constraint' => ['running', 'succeeded', 'failed'], 'default' => 'running'],
            'dry_run' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'updated_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'skipped_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'failed_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'message' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'started_by_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['institution_id', 'started_at']);
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sis_runs');
    }

    public function down()
    {
        $this->forge->dropTable('sis_runs', true);
        $this->forge->dropTable('sis_connections', true);
    }
}
