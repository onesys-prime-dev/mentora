<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ProgramsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        // [institution_id, department code, sub-department code, name, code]
        $programs = [

            // San Beda University
            [1, 'CBM', 'IT', 'Bachelor of Science in Information Technology', 'BSIT'],
            [1, 'CBM', 'CS', 'Bachelor of Science in Computer Science', 'BSCS'],
            [1, 'CBM', 'ACC', 'Bachelor of Science in Accountancy', 'BSA'],

            // UP Diliman
            [2, 'CS', 'CS', 'Bachelor of Science in Computer Science', 'BSCS'],
            [2, 'COE', 'CE', 'Bachelor of Science in Civil Engineering', 'BSCE'],

            // DLSU
            [3, 'CCS', 'IS', 'Bachelor of Science in Information Systems', 'BSIS'],

            // ADMU
            [4, 'SOSE', 'CS', 'Bachelor of Science in Computer Science', 'BSCS'],

            // UST
            [5, 'CICS', 'IT', 'Bachelor of Science in Information Technology', 'BSIT'],

        ];

        $rows = array_map(
            fn (array $program): array => [
                'sub_department_id' => $this->subDepartmentId($program[0], $program[1], $program[2]),
                'name'              => $program[3],
                'code'              => $program[4],
                'status'            => 'active',
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            $programs
        );

        $this->db
            ->table('programs')
            ->insertBatch($rows);
    }

    private function subDepartmentId(int $institutionId, string $departmentCode, string $code): int
    {
        return (int) $this->db->table('sub_departments')
            ->select('sub_departments.id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where([
                'departments.institution_id' => $institutionId,
                'departments.code'           => $departmentCode,
                'sub_departments.code'       => $code,
            ])
            ->get()
            ->getRowArray()['id'];
    }
}
