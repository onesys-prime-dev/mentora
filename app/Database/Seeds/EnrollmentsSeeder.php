<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class EnrollmentsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            [
                'offering_id' => 1,
                'student_user_id' => 2,
                'status' => 'enrolled',
                'enrolled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'offering_id' => 1,
                'student_user_id' => 3,
                'status' => 'enrolled',
                'enrolled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'offering_id' => 2,
                'student_user_id' => 2,
                'status' => 'enrolled',
                'enrolled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'offering_id' => 3,
                'student_user_id' => 4,
                'status' => 'enrolled',
                'enrolled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],

        ];

        $this->db
            ->table('enrollments')
            ->insertBatch($rows);
    }
}