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

        // A student number belongs to the institution that issued it (QA MISS-DB-01): the students'
        // numbers were written before they were linked here.
        $this->db->query('UPDATE ' . $this->db->prefixTable('user_profiles') . ' p JOIN ' . $this->db->prefixTable('institution_users') . ' iu ON iu.user_id = p.user_id SET p.student_number_institution_id = iu.institution_id WHERE p.student_number IS NOT NULL');
    }
}