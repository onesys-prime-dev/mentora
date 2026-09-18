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
                'is_system'   => true,
                'created_by'  => $createdBy,
                'created_at'  => $now,
                'updated_by'  => null,
                'updated_at'  => null,
                'status'      => 'Active',
            ],
            [
                'name'        => 'Assignment',
                'description' => 'Homework or take-home activity.',
                'is_system'   => true,
                'created_by'  => $createdBy,
                'created_at'  => $now,
                'updated_by'  => null,
                'updated_at'  => null,
                'status'      => 'Active',
            ],
        ];

        $this->db->table('assessment_types')->insertBatch($data);
    }
}
