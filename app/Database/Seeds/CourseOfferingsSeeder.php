<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class CourseOfferingsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            [
                'course_id' => 1,
                'term_id' => 1,
                'section_code' => 'A',
                'capacity' => 40,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'course_id' => 1,
                'term_id' => 1,
                'section_code' => 'B',
                'capacity' => 40,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'course_id' => 2,
                'term_id' => 1,
                'section_code' => 'A',
                'capacity' => 35,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'course_id' => 3,
                'term_id' => 1,
                'section_code' => 'A',
                'capacity' => 40,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

        ];

        $this->db
            ->table('course_offerings')
            ->insertBatch($rows);
    }
}