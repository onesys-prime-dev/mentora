<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * A student number is unique within the institution that issued it (QA MISS-DB-01); two institutions
 * may issue the same one. The profile keeps the issuer beside the number, and the pair is a unique key.
 *
 * Existing numbers take their student's institution as the issuer. Where one institution already has
 * the same number twice, the oldest account keeps the claim and the others keep their number with no
 * issuer, logged, for the institution to correct.
 */
class StudentNumberIsUniqueInAnInstitution extends Migration
{
    public function up(): void
    {
        $profiles = $this->connection()->prefixTable('user_profiles');

        $this->db->query("ALTER TABLE {$profiles} ADD COLUMN student_number_institution_id INT(20) UNSIGNED NULL AFTER student_number");

        $rows = $this->db->table('user_profiles p')
            ->select('p.user_id, p.student_number, iu.institution_id')
            ->join('institution_users iu', 'iu.user_id = p.user_id')
            ->where('p.student_number IS NOT NULL', null, false)
            ->where('p.student_number !=', '')
            ->orderBy('p.user_id')
            ->get()->getResultArray();

        $claimed = [];

        foreach ($rows as $row) {
            $key = $row['institution_id'] . ':' . $row['student_number'];

            if (isset($claimed[$key])) {
                log_message('warning', 'Student numbers: {number} is used twice in institution {institution}; user {user} keeps it unclaimed until it is corrected.', [
                    'number' => $row['student_number'], 'institution' => $row['institution_id'], 'user' => $row['user_id'],
                ]);

                continue;
            }

            $claimed[$key] = true;
            $this->db->table('user_profiles')->where('user_id', $row['user_id'])->update(['student_number_institution_id' => $row['institution_id']]);
        }

        $this->db->query("ALTER TABLE {$profiles} ADD UNIQUE KEY uq_profiles_student_number (student_number_institution_id, student_number)");
        $this->db->query("ALTER TABLE {$profiles} ADD CONSTRAINT fk_profiles_student_number_institution FOREIGN KEY (student_number_institution_id) REFERENCES " . $this->connection()->prefixTable('institutions') . '(id) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down(): void
    {
        $profiles = $this->connection()->prefixTable('user_profiles');

        $this->db->query("ALTER TABLE {$profiles} DROP FOREIGN KEY fk_profiles_student_number_institution");
        $this->db->query("ALTER TABLE {$profiles} DROP INDEX uq_profiles_student_number");
        $this->db->query("ALTER TABLE {$profiles} DROP COLUMN student_number_institution_id");
    }

    /** The migration's own connection, typed so its prefix and row counts can be read. */
    private function connection(): BaseConnection
    {
        return Database::connect($this->DBGroup);
    }
}
