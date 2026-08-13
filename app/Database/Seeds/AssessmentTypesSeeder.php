<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AssessmentTypesSeeder extends Seeder
{
    public function run()
    {
        // Prevent duplicate records
        if ($this->db->table('assessment_types')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Use the first available user for audit fields
        $user = $this->db->table('users')
            ->select('id')
            ->orderBy('id', 'ASC')
            ->get(1)
            ->getRowArray();

        $createdBy = $user['id'] ?? null;

        $data = [
            [
                'name'        => 'Quiz',
                'description' => 'Short assessment used to evaluate student understanding.',
                'isSystem'    => true,
                'createdBy'   => $createdBy,
                'createdAt'   => $now,
                'updatedBy'   => null,
                'updatedAt'   => null,
                'status'      => 'Active',
            ],
            [
                'name'        => 'Assignment',
                'description' => 'Homework or take-home activity.',
                'isSystem'    => true,
                'createdBy'   => $createdBy,
                'createdAt'   => $now,
                'updatedBy'   => null,
                'updatedAt'   => null,
                'status'      => 'Active',
            ],
            [
                'name'        => 'Discussion',
                'description' => 'Discussion board participation.',
                'isSystem'    => true,
                'createdBy'   => $createdBy,
                'createdAt'   => $now,
                'updatedBy'   => null,
                'updatedAt'   => null,
                'status'      => 'Active',
            ],
            [
                'name'        => 'Essay',
                'description' => 'Essay or written assessment.',
                'isSystem'    => true,
                'createdBy'   => $createdBy,
                'createdAt'   => $now,
                'updatedBy'   => null,
                'updatedAt'   => null,
                'status'      => 'Active',
            ],
            [
                'name'        => 'File Upload',
                'description' => 'Submit a file as the answer.',
                'isSystem'    => true,
                'createdBy'   => $createdBy,
                'createdAt'   => $now,
                'updatedBy'   => null,
                'updatedAt'   => null,
                'status'      => 'Active',
            ],
            [
                'name'        => 'Laboratory Activity',
                'description' => 'Laboratory exercise or practical work.',
                'isSystem'    => true,
                'createdBy'   => $createdBy,
                'createdAt'   => $now,
                'updatedBy'   => null,
                'updatedAt'   => null,
                'status'      => 'Active',
            ],
            [
                'name'        => 'Project',
                'description' => 'Course project or capstone requirement.',
                'isSystem'    => true,
                'createdBy'   => $createdBy,
                'createdAt'   => $now,
                'updatedBy'   => null,
                'updatedAt'   => null,
                'status'      => 'Active',
            ],
            [
                'name'        => 'Practical Exam',
                'description' => 'Hands-on practical examination.',
                'isSystem'    => true,
                'createdBy'   => $createdBy,
                'createdAt'   => $now,
                'updatedBy'   => null,
                'updatedAt'   => null,
                'status'      => 'Active',
            ],
        ];

        $this->db->table('assessment_types')->insertBatch($data);
    }
}