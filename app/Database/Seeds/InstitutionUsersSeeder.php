<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class InstitutionUsersSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            // San Beda University

            [
                'institution_id' => 1,
                'user_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'institution_id' => 1,
                'user_id' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'institution_id' => 1,
                'user_id' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'institution_id' => 1,
                'user_id' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'institution_id' => 1,
                'user_id' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'institution_id' => 1,
                'user_id' => 6,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'institution_id' => 1,
                'user_id' => 7,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'institution_id' => 1,
                'user_id' => 8,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'institution_id' => 1,
                'user_id' => 9,
                'created_at' => $now,
                'updated_at' => $now,
            ],

        ];

        $this->db
            ->table('institution_users')
            ->insertBatch($rows);
    }
}