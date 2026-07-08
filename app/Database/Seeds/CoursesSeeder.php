<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class CoursesSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            // BSIT (Program ID = 1)

            [
                'program_id' => 1,
                'course_code' => 'IT101',
                'course_title' => 'Introduction to Computing',
                'units' => 3.0,
                'description' => 'Fundamentals of computing.',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'program_id' => 1,
                'course_code' => 'IT102',
                'course_title' => 'Computer Programming 1',
                'units' => 3.0,
                'description' => 'Introduction to programming using PHP.',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'program_id' => 1,
                'course_code' => 'IT201',
                'course_title' => 'Database Systems',
                'units' => 3.0,
                'description' => 'Relational database concepts.',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // BSCS (Program ID = 2)

            [
                'program_id' => 2,
                'course_code' => 'CS101',
                'course_title' => 'Discrete Structures',
                'units' => 3.0,
                'description' => 'Logic and discrete mathematics.',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'program_id' => 2,
                'course_code' => 'CS202',
                'course_title' => 'Data Structures and Algorithms',
                'units' => 3.0,
                'description' => 'Algorithm design and analysis.',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

        ];

        $this->db
            ->table('courses')
            ->insertBatch($rows);
    }
}