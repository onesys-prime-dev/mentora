<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * One chairperson per sub-department (audit decision 16.0).
 *
 * `active_marker` is 1 on the one active assignment of a sub-department and NULL otherwise, and a unique key
 * on (sub_department_id, active_marker) keeps it so (MySQL lets a unique key hold any number of NULLs), as
 * `dean_assignments` already does for deans. Where a sub-department has several active chairs, the most
 * recently assigned is kept and the others are revoked, their rows kept as history (16.0d).
 */
class OneChairpersonPerSubDepartment extends Migration
{
    public function up(): void
    {
        $table = $this->connection()->prefixTable('chairperson_assignments');

        $this->db->query("ALTER TABLE {$table} ADD COLUMN active_marker TINYINT NULL AFTER status");

        $active = $this->db->table('chairperson_assignments')->select('id, sub_department_id, assigned_at')->where('status', 'active')->get()->getResultArray();
        $revoke = self::assignmentsToRevoke(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'sub_department_id' => (int) $r['sub_department_id'], 'assigned_at' => (string) $r['assigned_at'],
        ], $active));

        if ($revoke !== []) {
            $this->db->table('chairperson_assignments')->whereIn('id', $revoke)->update(['status' => 'revoked', 'updated_at' => date('Y-m-d H:i:s')]);
        }

        $this->db->table('chairperson_assignments')->where('status', 'active')->update(['active_marker' => 1]);
        $this->db->query("ALTER TABLE {$table} ADD UNIQUE KEY chairperson_assignments_one_active (sub_department_id, active_marker)");
    }

    public function down(): void
    {
        $table = $this->connection()->prefixTable('chairperson_assignments');

        $this->db->query("ALTER TABLE {$table} DROP INDEX chairperson_assignments_one_active");
        $this->db->query("ALTER TABLE {$table} DROP COLUMN active_marker");
    }

    /**
     * The active assignments to revoke so each sub-department keeps one: its most recently assigned (on a tie,
     * the newer row).
     *
     * @param list<array{id: int, sub_department_id: int, assigned_at: string}> $rows
     *
     * @return list<int>
     */
    public static function assignmentsToRevoke(array $rows): array
    {
        $bySub = [];

        foreach ($rows as $row) {
            $bySub[$row['sub_department_id']][] = $row;
        }

        $revoke = [];

        foreach ($bySub as $assignments) {
            usort($assignments, static fn (array $a, array $b): int => [$b['assigned_at'], $b['id']] <=> [$a['assigned_at'], $a['id']]);

            foreach (array_slice($assignments, 1) as $extra) {
                $revoke[] = $extra['id'];
            }
        }

        sort($revoke);

        return $revoke;
    }

    /** The migration's own connection, typed so its prefix and row counts can be read. */
    private function connection(): BaseConnection
    {
        return Database::connect($this->DBGroup);
    }
}
