<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AcademicTermsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            // San Beda University

            [
                'institution_id' => 1,
                'name' => '1st Semester',
                'academic_year' => '2026-2027',
                'start_date' => '2026-08-10',
                'end_date' => '2026-12-12',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'institution_id' => 1,
                'name' => '2nd Semester',
                'academic_year' => '2026-2027',
                'start_date' => '2027-01-12',
                'end_date' => '2027-05-15',
                'status' => 'upcoming',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // UP Diliman

            [
                'institution_id' => 2,
                'name' => '1st Semester',
                'academic_year' => '2026-2027',
                'start_date' => '2026-08-18',
                'end_date' => '2026-12-18',
                'status' => 'upcoming',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // DLSU

            [
                'institution_id' => 3,
                'name' => '1st Semester',
                'academic_year' => '2026-2027',
                'start_date' => '2026-09-01',
                'end_date' => '2026-12-20',
                'status' => 'upcoming',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // ADMU

            [
                'institution_id' => 4,
                'name' => '1st Semester',
                'academic_year' => '2026-2027',
                'start_date' => '2026-08-11',
                'end_date' => '2026-12-11',
                'status' => 'upcoming',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // UST

            [
                'institution_id' => 5,
                'name' => '1st Semester',
                'academic_year' => '2026-2027',
                'start_date' => '2026-08-05',
                'end_date' => '2026-12-10',
                'status' => 'upcoming',
                'created_at' => $now,
                'updated_at' => $now,
            ],

        ];

        $this->db
            ->table('academic_terms')
            ->insertBatch($rows);
    }
}