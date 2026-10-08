<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * The departments inside each college. Programs hang off these.
 */
class SubDepartmentsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        // [institution_id, department code, code, name]
        $rows = [
            [1, 'CBM', 'IT', 'Department of Information Technology'],
            [1, 'CBM', 'CS', 'Department of Computer Science'],
            [1, 'CBM', 'ACC', 'Department of Accountancy'],
            [1, 'CBM', 'HM', 'Department of Hospitality Management'],
            [1, 'GEN', 'GE', 'General Subjects'],
            [2, 'CS', 'CS', 'Department of Computer Science'],
            [2, 'COE', 'CE', 'Department of Civil Engineering'],
            [3, 'CCS', 'IS', 'Department of Information Systems'],
            [4, 'SOSE', 'CS', 'Department of Computer Science'],
            [5, 'CICS', 'IT', 'Department of Information Technology'],
        ];

        $this->db->table('sub_departments')->insertBatch(array_map(
            fn (array $row): array => [
                'department_id' => $this->departmentId($row[0], $row[1]),
                'code'          => $row[2],
                'name'          => $row[3],
                'is_active'     => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            $rows
        ));
    }

    private function departmentId(int $institutionId, string $code): int
    {
        return (int) $this->db->table('departments')
            ->where(['institution_id' => $institutionId, 'code' => $code])
            ->get()
            ->getRowArray()['id'];
    }
}
