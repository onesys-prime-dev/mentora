<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class InstitutionsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            [
                'name'       => 'San Beda University',
                'code'       => 'SBU',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'name'       => 'University of the Philippines Diliman',
                'code'       => 'UPD',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'name'       => 'De La Salle University',
                'code'       => 'DLSU',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'name'       => 'Ateneo de Manila University',
                'code'       => 'ADMU',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'name'       => 'University of Santo Tomas',
                'code'       => 'UST',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

        ];

        $this->db
            ->table('institutions')
            ->insertBatch($rows);
    }
}