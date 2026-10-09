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

        $subDepartmentId = $this->db->table('sub_departments')
            ->select('sub_departments.id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where([
                'departments.institution_id' => 1,
                'departments.code'           => 'CBM',
                'sub_departments.code'       => 'IT',
            ])
            ->get()
            ->getRowArray()['id'] ?? null;

        if (! $subDepartmentId) {
            echo "Chairperson's sub-department not found.\n";
            return;
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            [
                'sub_department_id' => $subDepartmentId,
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