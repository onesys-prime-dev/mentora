<?php

namespace App\Database\Seeds;

/**
 * The demo offerings. An offering that belongs to a block section (ClassSectionsSeeder) is linked to it
 * (course_offerings.class_section_id) and carries its name as its section_code; one that does not is a standalone
 * section, as every offering was before sections existed.
 */
class CourseOfferingsSeeder extends DemoSeeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $termId = $this->termId('1st Semester', '2026-2027');

        // [course, section, capacity, in a block section of that name?, description]
        $offerings = [
            ['IT101', 'A', 40, true, 'An introduction to how computers work — hardware, software and how data is stored — followed by the fundamentals of programming and a first look at databases. No prior experience is needed.'],
            ['IT101', 'B', 40, true, 'Section B of Introduction to Computing. Same outline as section A, running one week behind.'],
            ['IT102', 'A', 35, true, 'Your first programming course, using PHP. Set up a local development environment, then learn variables, control flow and functions by building small programs.'],
            // In no block: a standalone section, outside any cohort.
            ['IT201', 'A', 40, false, 'Relational database concepts: tables and keys, entity-relationship modelling, normalization and SQL.'],
        ];

        foreach ($offerings as [$code, $section, $capacity, $inBlock, $description]) {
            $this->db->table('course_offerings')->insert([
                'course_id' => $this->courseId($code),
                'term_id' => $termId,
                'section_code' => $section,
                'class_section_id' => $inBlock ? $this->classSectionId($section) : null,
                'capacity' => $capacity,
                'description' => $description,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
