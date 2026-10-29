<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Whether each good backup was copied off the server (audit U3): `copied`, `failed` (with why), or null when no
 * off-site command is configured (staging) or the backup was not good.
 */
class AddOffsiteToBackups extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('backups', [
            'offsite_status'  => ['type' => 'ENUM', 'constraint' => ['copied', 'failed'], 'null' => true, 'after' => 'message'],
            'offsite_message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'offsite_status'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('backups', ['offsite_status', 'offsite_message']);
    }
}
