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

        $data = [
            [
                'program_id' => 1,
                'chairperson_user_id' => $chairperson->id,
                'status' => 'active',
                'assigned_at' => date('Y-m-d H:i:s'),
            ],
            [
                'program_id' => 2,
                'chairperson_user_id' => $chairperson->id,
                'status' => 'active',
                'assigned_at' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->db
            ->table('chairperson_assignments')
            ->insertBatch($data);

        echo "Chairperson assignments seeded.\n";
    }
}