<?php

namespace App\Database\Seeds;

class CourseOfferingsSeeder extends DemoSeeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $termId = $this->termId('1st Semester', '2026-2027');

        $offerings = [
            ['IT101', 'A', 40, 'An introduction to how computers work — hardware, software and how data is stored — followed by the fundamentals of programming and a first look at databases. No prior experience is needed.'],
            ['IT101', 'B', 40, 'Section B of Introduction to Computing. Same outline as section A, running one week behind.'],
            ['IT102', 'A', 35, 'Your first programming course, using PHP. Set up a local development environment, then learn variables, control flow and functions by building small programs.'],
            ['IT201', 'A', 40, 'Relational database concepts: tables and keys, entity-relationship modelling, normalization and SQL.'],
        ];

        foreach ($offerings as [$code, $section, $capacity, $description]) {
            $this->db->table('course_offerings')->insert([
                'course_id' => $this->courseId($code),
                'term_id' => $termId,
                'section_code' => $section,
                'capacity' => $capacity,
                'description' => $description,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
