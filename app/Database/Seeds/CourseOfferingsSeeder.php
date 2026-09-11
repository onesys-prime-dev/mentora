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
                'description' => "This course introduces students 111 to Python, one of the world's most popular and versatile programming languages. We will cover foundational concepts including data types, control flow, functions, and object-oriented programming. By the end of the semester, students will be able to write robust scripts, analyze data using core libraries, and build simple web applications.",
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'course_id' => 1,
                'term_id' => 1,
                'section_code' => 'B',
                'capacity' => 40,
                'description' => "This course introduces students 222 to Python, one of the world's most popular and versatile programming languages. We will cover foundational concepts including data types, control flow, functions, and object-oriented programming. By the end of the semester, students will be able to write robust scripts, analyze data using core libraries, and build simple web applications.",
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'course_id' => 2,
                'term_id' => 1,
                'section_code' => 'A',
                'capacity' => 35,
                'description' => "This course introduces students 333 to Python, one of the world's most popular and versatile programming languages. We will cover foundational concepts including data types, control flow, functions, and object-oriented programming. By the end of the semester, students will be able to write robust scripts, analyze data using core libraries, and build simple web applications.",
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'course_id' => 3,
                'term_id' => 1,
                'section_code' => 'A',
                'capacity' => 40,
                'description' => "This course introduces students 444 to Python, one of the world's most popular and versatile programming languages. We will cover foundational concepts including data types, control flow, functions, and object-oriented programming. By the end of the semester, students will be able to write robust scripts, analyze data using core libraries, and build simple web applications.",
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