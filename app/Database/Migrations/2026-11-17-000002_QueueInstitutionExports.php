<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * The institution export is prepared in the background (audit decisions 12.2, 12.3): a request is `queued`,
 * the job makes it `running`, then `ready` or `failed`. `open_marker` is 1 while it is queued or running and
 * NULL after, and a unique key on (institution_id, open_marker) keeps one open export per institution, as
 * `dean_assignments.active_marker` does for deans (MySQL lets a unique key hold any number of NULLs).
 */
class QueueInstitutionExports extends Migration
{
    public function up(): void
    {
        $table = $this->connection()->prefixTable('institution_exports');

        $this->db->query("ALTER TABLE {$table} MODIFY status ENUM('queued', 'running', 'ready', 'failed') NOT NULL DEFAULT 'queued'");
        $this->db->query("ALTER TABLE {$table} ADD COLUMN started_at DATETIME NULL AFTER created_at, ADD COLUMN open_marker TINYINT NULL AFTER status");
        $this->db->query("ALTER TABLE {$table} ADD UNIQUE KEY institution_exports_one_open (institution_id, open_marker)");
    }

    public function down(): void
    {
        $table = $this->connection()->prefixTable('institution_exports');

        $this->db->query("ALTER TABLE {$table} DROP INDEX institution_exports_one_open");
        $this->db->query("ALTER TABLE {$table} DROP COLUMN open_marker, DROP COLUMN started_at");
        $this->db->query("DELETE FROM {$table} WHERE status IN ('queued', 'running')");
        $this->db->query("ALTER TABLE {$table} MODIFY status ENUM('ready', 'failed') NOT NULL DEFAULT 'ready'");
    }

    /** The migration's own connection, typed so its prefix and row counts can be read. */
    private function connection(): BaseConnection
    {
        return Database::connect($this->DBGroup);
    }
}
