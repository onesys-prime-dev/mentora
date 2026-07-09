<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Models\UserModel;

class InstructorAssignmentsSeeder extends Seeder
{
    public function run()
    {
        $userModel = model(UserModel::class);

        $instructor1 = $userModel->findByCredentials([
            'email' => 'instructor1@mentora.test',
        ]);

        $instructor2 = $userModel->findByCredentials([
            'email' => 'instructor2@mentora.test',
        ]);

        if (! $instructor1 || ! $instructor2) {
            echo "Instructor demo users not found.\n";
            return;
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            [
                'offering_id' => 1,
                'instructor_user_id' => $instructor1->id,
                'assigned_at' => $now,
                'updated_at' => $now,
            ],
            [
                'offering_id' => 2,
                'instructor_user_id' => $instructor1->id,
                'assigned_at' => $now,
                'updated_at' => $now,
            ],
            [
                'offering_id' => 3,
                'instructor_user_id' => $instructor2->id,
                'assigned_at' => $now,
                'updated_at' => $now,
            ],
            [
                'offering_id' => 4,
                'instructor_user_id' => $instructor2->id,
                'assigned_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $this->db
            ->table('instructor_assignments')
            ->insertBatch($data);

        echo "Instructor assignments seeded.\n";
    }
}