<?php

namespace App\Database\Seeds;

class InstructorAssignmentsSeeder extends DemoSeeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $assignments = [
            ['IT101', 'A', 'instructor1@mentora.test'],
            ['IT101', 'B', 'instructor1@mentora.test'],
            ['IT102', 'A', 'instructor2@mentora.test'],
            ['IT201', 'A', 'instructor2@mentora.test'],
        ];

        foreach ($assignments as [$code, $section, $email]) {
            $this->db->table('instructor_assignments')->insert([
                'offering_id' => $this->offeringId($code, $section),
                'instructor_user_id' => $this->userId($email),
                'assigned_at' => $now,
                'updated_at' => $now,
            ]);
        }

        echo "Instructor assignments seeded.\n";
    }
}
