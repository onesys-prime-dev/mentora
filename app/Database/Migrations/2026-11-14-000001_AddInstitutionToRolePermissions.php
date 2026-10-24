<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Each institution's own permission settings (audit decision U1b), beside the platform's.
 *
 * `institution_id` 0 is the platform layer (every row so far); any other value is that institution's
 * changes on top of it. Not nullable: MySQL lets a unique key hold any number of NULLs, so the platform
 * layer would lose the one-row-per-box rule it has today.
 */
class AddInstitutionToRolePermissions extends Migration
{
    public function up(): void
    {
        $table = $this->db->prefixTable('role_permissions');

        $this->db->query("ALTER TABLE {$table} ADD COLUMN institution_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id");
        $this->db->query("ALTER TABLE {$table} DROP INDEX role_permission, ADD UNIQUE KEY role_permission (institution_id, role, permission)");
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('role_permissions');

        $this->db->query("DELETE FROM {$table} WHERE institution_id <> 0");
        $this->db->query("ALTER TABLE {$table} DROP INDEX role_permission, ADD UNIQUE KEY role_permission (role, permission)");
        $this->db->query("ALTER TABLE {$table} DROP COLUMN institution_id");
    }
}
