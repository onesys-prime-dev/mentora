<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Models\UserModel;

class ChairpersonAssignmentsSeeder extends Seeder
{
    public function run()
    {
        $userModel = model(UserModel::class);

        $chairperson = $userModel->findByCredentials([
            'email' => 'chairperson1@mentora.test',
        ]);

        if (! $chairperson) {
            echo "Chairperson demo user not found.\n";
            return;
        }

        $departmentId = $this->db->table('departments')
            ->select('departments.id')
            ->join('colleges', 'colleges.id = departments.college_id')
            ->where([
                'colleges.institution_id' => 1,
                'colleges.code'           => 'CBM',
                'departments.code'       => 'IT',
            ])
            ->get()
            ->getRowArray()['id'] ?? null;

        if (! $departmentId) {
            echo "Chairperson's department not found.\n";
            return;
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            [
                'department_id' => $departmentId,
                'chairperson_user_id' => $chairperson->id,
                'status' => 'active',
                'assigned_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $this->db
            ->table('chairperson_assignments')
            ->insertBatch($data);

        echo "Chairperson assignments seeded.\n";
    }
}