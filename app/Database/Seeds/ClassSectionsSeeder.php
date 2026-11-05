<?php

namespace App\Database\Seeds;

/**
 * DEMO DATA ONLY — the block sections (class_sections) of the demo institution, and who is in them.
 *
 * A block section is a cohort that takes a set of courses together in a term. Its name is the section_code of every
 * offering it owns (CourseOfferingsSeeder links them), and its members are enrolled in every one of those offerings
 * (DemoActivitySeeder does that, the way the Sections page does). The names are the short ones the rest of the demo
 * already uses (IT101-A, IT101-B), so every screen and test that names an offering by course and section still does.
 *
 *   A  BSIT year 1   IT101-A, IT102-A   students 1-3 are members.
 *                    Students 4 and 5 also take IT101-A but are not members: second-years sitting in (an
 *                    irregular student is enrolled in one offering, on its roster).
 *   B  BSIT year 1   IT101-B            no students yet — an instructor's empty section.
 *
 * IT201-A is deliberately in no block: an offering made before sections existed, or outside any cohort, stays a
 * standalone section.
 */
class ClassSectionsSeeder extends DemoSeeder
{
    public function run()
    {
        $institutionId = 1;
        $termId        = $this->termId('1st Semester', '2026-2027', $institutionId);
        $now           = $this->at();

        // [name, program code, year level, capacity, members]
        $sections = [
            ['A', 'BSIT', 1, 40, ['student1', 'student2', 'student3']],
            ['B', 'BSIT', 1, 40, []],
        ];

        foreach ($sections as [$code, $program, $year, $capacity, $members]) {
            $this->db->table('class_sections')->insert([
                'institution_id' => $institutionId,
                'term_id'        => $termId,
                'program_id'     => $this->programId($program, $institutionId),
                'year_level'     => $year,
                'code'           => $code,
                'capacity'       => $capacity,
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            $sectionId = (int) $this->db->insertID();

            foreach ($members as $student) {
                $this->db->table('class_section_students')->insert([
                    'class_section_id' => $sectionId,
                    'student_user_id'  => $this->userId("{$student}@mentora.test"),
                    'created_at'       => $this->at('-40 days'),
                ]);
            }
        }

        echo "Demo sections seeded.\n";
    }
}
