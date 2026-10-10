<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Full data exports of an institution (SA13): the offboarding export an
 * institutional administrator can take while the institution is read-only or
 * past. One row per export; the file (a ZIP of CSVs) sits in writable/exports
 * until `expires_at`, and `file_name` is a name, never a path.
 *
 * Removing an institution (a purge) removes its export rows with it.
 */
class CreateInstitutionExportsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['ready', 'failed'], 'default' => 'ready'],
            'file_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'size_bytes' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'row_counts' => ['type' => 'TEXT', 'null' => true],
            'message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'requested_by_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'requested_by_label' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'expires_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['institution_id', 'created_at']);
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('institution_exports');
    }

    public function down()
    {
        $this->forge->dropTable('institution_exports', true);
    }
}
