<?php

namespace App\Database\Seeds;

use App\Models\Users\UserProfileModel;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

class DemoUsersSeeder extends Seeder
{
    public function run()
    {
        /** @var UserModel $userModel */
        $userModel = model(UserModel::class);

        $profileModel = new UserProfileModel();

        $accounts = [
            [
                'username'    => 'student.demo',
                'email'       => 'student@mentora.test',
                'password'    => 'Student123!',
                'group'       => 'student',
                'first_name'  => 'Juan',
                'middle_name' => 'Santos',
                'last_name'   => 'Dela Cruz',
                'suffix'      => null,
            ],
            [
                'username'    => 'instructor.demo',
                'email'       => 'instructor@mentora.test',
                'password'    => 'Instructor123!',
                'group'       => 'instructor',
                'first_name'  => 'Maria',
                'middle_name' => 'Reyes',
                'last_name'   => 'Santos',
                'suffix'      => null,
            ],
        ];

        foreach ($accounts as $account) {

            // Skip if user already exists
            $existing = $userModel->findByCredentials([
                'email' => $account['email'],
            ]);

            if ($existing !== null) {
                echo "[SKIPPED] {$account['email']}" . PHP_EOL;
                continue;
            }

            // Create Shield user
            $user = new User([
                'username' => $account['username'],
                'email'    => $account['email'],
                'password' => $account['password'],
                'active'   => true,
            ]);

            $userModel->save($user);

            /** @var User $user */
            $user = $userModel->findById($userModel->getInsertID());

            // Assign Shield group
            $user->addGroup($account['group']);

            // Create user profile
            $profileModel->insert([
                'user_id'     => $user->id,
                'first_name'  => $account['first_name'],
                'middle_name' => $account['middle_name'],
                'last_name'   => $account['last_name'],
                'suffix'      => $account['suffix'],
            ]);

            echo "[CREATED] {$account['group']} ({$account['email']})" . PHP_EOL;
        }
    }
}