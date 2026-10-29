<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\AuthGroups;

/**
 * Removes stored permission changes that can no longer do anything (audit decisions D2 and 7.7): a box a
 * role is not offered any more, and `monitoring.manage`, which nothing ever checked. They are already
 * ignored when the matrix is built; this only keeps the table honest.
 */
class RemoveInapplicablePermissionRows extends Migration
{
    public function up(): void
    {
        $this->db->table('role_permissions')->where('permission', 'monitoring.manage')->delete();

        foreach ($this->db->table('role_permissions')->select('id, role, permission')->get()->getResultArray() as $row) {
            if (! in_array($row['permission'], AuthGroups::APPLICABLE[$row['role']] ?? [], true)) {
                $this->db->table('role_permissions')->where('id', $row['id'])->delete();
            }
        }
    }

    public function down(): void
    {
        // Nothing to bring back: the rows did nothing.
    }
}
