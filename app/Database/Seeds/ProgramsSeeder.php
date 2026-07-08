<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ProgramsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            // San Beda University (institution_id = 1)

            [
                'institution_id' => 1,
                'name'           => 'Bachelor of Science in Information Technology',
                'code'           => 'BSIT',
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],

            [
                'institution_id' => 1,
                'name'           => 'Bachelor of Science in Computer Science',
                'code'           => 'BSCS',
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],

            [
                'institution_id' => 1,
                'name'           => 'Bachelor of Science in Accountancy',
                'code'           => 'BSA',
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],

            // UP Diliman (institution_id = 2)

            [
                'institution_id' => 2,
                'name'           => 'Bachelor of Science in Computer Science',
                'code'           => 'BSCS',
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],

            [
                'institution_id' => 2,
                'name'           => 'Bachelor of Science in Civil Engineering',
                'code'           => 'BSCE',
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],

            // DLSU (institution_id = 3)

            [
                'institution_id' => 3,
                'name'           => 'Bachelor of Science in Information Systems',
                'code'           => 'BSIS',
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],

            // ADMU (institution_id = 4)

            [
                'institution_id' => 4,
                'name'           => 'Bachelor of Science in Computer Science',
                'code'           => 'BSCS',
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],

            // UST (institution_id = 5)

            [
                'institution_id' => 5,
                'name'           => 'Bachelor of Science in Information Technology',
                'code'           => 'BSIT',
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],

        ];

        $this->db
            ->table('programs')
            ->insertBatch($rows);
    }
}