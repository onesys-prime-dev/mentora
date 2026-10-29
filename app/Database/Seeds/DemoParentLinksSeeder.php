<?php

namespace App\Database\Seeds;

/**
 * Demo parents: parent1 follows student1 (IT101-A and IT102-A) and student5
 * (IT101-A and IT201-A); parent2 follows nobody, to show the "not linked" page.
 * Runs after the enrolments exist, and does nothing for a parent who already has links.
 *
 * The rows are inserted directly, like the other demo seeders do: the link
 * service audits through the signed-in session, and nobody is signed in here.
 */
class DemoParentLinksSeeder extends DemoSeeder
{
    public function run()
    {
        $parent = $this->userId('parent1@mentora.test');

        $institution = (int) ($this->db->table('institution_users')->select('institution_id')
            ->where('user_id', $parent)->get()->getRowArray()['institution_id'] ?? 0);

        if ($institution === 0) {
            throw new \RuntimeException('Demo parent1 belongs to no institution; run DemoUsersSeeder first.');
        }

        if ($this->db->table('parent_student_links')->where('parent_user_id', $parent)->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');

            foreach (['student1@mentora.test', 'student5@mentora.test'] as $email) {
                $this->db->table('parent_student_links')->insert([
                    'institution_id'     => $institution,
                    'parent_user_id'     => $parent,
                    'student_user_id'    => $this->userId($email),
                    'created_by_user_id' => $this->userId('institution.admin@mentora.test'),
                    'created_at'         => $now,
                ]);
            }
        }

        echo "Demo parent links seeded.\n";
    }
}
