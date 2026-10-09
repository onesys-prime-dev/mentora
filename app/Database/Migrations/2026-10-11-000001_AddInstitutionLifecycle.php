<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Gives institutions the lifecycle a customer goes through (SA4, SA13):
 *
 *   pending   created, setup not finished: nobody can use it yet
 *   active    in good standing (includes the warning and grace periods)
 *   read_only subscription lapsed: sign in, view and export only
 *   past      subscription ended or suspended: kept, read-only, can be renewed
 *
 * plus deleted_at, so a deleted institution is hidden but can be restored for
 * 90 days before it is purged.
 *
 * The old `inactive` and `archived` values both meant "no longer running", so
 * both become `past`. Going back, `past` becomes `inactive`, an institution
 * that was deleted becomes `archived` (the closest the old schema has), and
 * `pending` / `read_only` fall back to the nearest old value.
 */
class AddInstitutionLifecycle extends Migration
{
    public function up()
    {
        // Widen first so the old values can be rewritten, then narrow to the new set.
        $this->db->query("ALTER TABLE institutions MODIFY status ENUM('pending','active','read_only','past','inactive','archived') NOT NULL DEFAULT 'active'");
        $this->db->query("UPDATE institutions SET status = 'past' WHERE status IN ('inactive', 'archived')");
        $this->db->query("ALTER TABLE institutions MODIFY status ENUM('pending','active','read_only','past') NOT NULL DEFAULT 'active'");

        $this->forge->addColumn('institutions', [
            'deleted_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'updated_at',
            ],
        ]);

        $this->db->query('ALTER TABLE institutions ADD INDEX deleted_at (deleted_at)');
    }

    public function down()
    {
        $this->db->query("ALTER TABLE institutions MODIFY status ENUM('pending','active','read_only','past','inactive','archived') NOT NULL DEFAULT 'active'");

        // Deleted ones first, so their status is not overwritten by the mapping below.
        $this->db->query("UPDATE institutions SET status = 'archived' WHERE deleted_at IS NOT NULL");
        $this->db->query("UPDATE institutions SET status = 'inactive' WHERE deleted_at IS NULL AND status IN ('past', 'pending')");
        $this->db->query("UPDATE institutions SET status = 'active' WHERE status = 'read_only'");

        $this->db->query("ALTER TABLE institutions MODIFY status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active'");

        $this->db->query('ALTER TABLE institutions DROP INDEX deleted_at');
        $this->forge->dropColumn('institutions', 'deleted_at');
    }
}
