<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Remembers which Mentora person or course is which record in an
 * institution's SIS (`sis_links`), so a later sync updates the same row
 * instead of creating another. Also gives each run a place for its details
 * (rows that were not imported, and why). Removed with the institution.
 */
class CreateSisLinks extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'entity' => ['type' => 'ENUM', 'constraint' => ['person', 'course']],
            'external_id' => ['type' => 'VARCHAR', 'constraint' => 190],
            'local_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['institution_id', 'entity', 'external_id']);
        $this->forge->addKey(['entity', 'local_id']);
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sis_links');

        $this->forge->addColumn('sis_runs', [
            'details' => ['type' => 'MEDIUMTEXT', 'null' => true, 'after' => 'message'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('sis_runs', 'details');
        $this->forge->dropTable('sis_links', true);
    }
}
