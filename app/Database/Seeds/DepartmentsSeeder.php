<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Colleges, one level under the institution. Insert order is fixed: the
 * sub-departments seeder and the programs seeder find these by code.
 */
class DepartmentsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        // [institution_id, code, name]
        $rows = [
            [1, 'CBM', 'College of Business and Management'],
            [1, 'GEN', 'College of General Subjects'],
            [2, 'CS', 'College of Science'],
            [2, 'COE', 'College of Engineering'],
            [3, 'CCS', 'College of Computer Studies'],
            [4, 'SOSE', 'School of Science and Engineering'],
            [5, 'CICS', 'College of Information and Computing Sciences'],
        ];

        $this->db->table('departments')->insertBatch(array_map(
            static fn (array $row): array => [
                'institution_id'   => $row[0],
                'code'             => $row[1],
                'name'             => $row[2],
                'location'         => null,
                'established_year' => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            $rows
        ));
    }
}
