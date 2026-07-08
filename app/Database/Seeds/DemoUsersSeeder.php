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

            // =====================================================
            // Students
            // =====================================================

            [
                'username'    => 'student.demo1',
                'email'       => 'student1@mentora.test',
                'password'    => 'Student123!',
                'group'       => 'student',
                'first_name'  => 'Juan',
                'middle_name' => 'Santos',
                'last_name'   => 'Dela Cruz',
                'suffix'      => null,
            ],

            [
                'username'    => 'student.demo2',
                'email'       => 'student2@mentora.test',
                'password'    => 'Student123!',
                'group'       => 'student',
                'first_name'  => 'Pedro',
                'middle_name' => 'Garcia',
                'last_name'   => 'Reyes',
                'suffix'      => null,
            ],

            [
                'username'    => 'student.demo3',
                'email'       => 'student3@mentora.test',
                'password'    => 'Student123!',
                'group'       => 'student',
                'first_name'  => 'Maria',
                'middle_name' => 'Lopez',
                'last_name'   => 'Santos',
                'suffix'      => null,
            ],

            [
                'username'    => 'student.demo4',
                'email'       => 'student4@mentora.test',
                'password'    => 'Student123!',
                'group'       => 'student',
                'first_name'  => 'Angela',
                'middle_name' => 'Ramos',
                'last_name'   => 'Cruz',
                'suffix'      => null,
            ],

            [
                'username'    => 'student.demo5',
                'email'       => 'student5@mentora.test',
                'password'    => 'Student123!',
                'group'       => 'student',
                'first_name'  => 'Michael',
                'middle_name' => 'Torres',
                'last_name'   => 'Villanueva',
                'suffix'      => null,
            ],

            // =====================================================
            // Instructors
            // =====================================================

            [
                'username'    => 'instructor.demo1',
                'email'       => 'instructor1@mentora.test',
                'password'    => 'Instructor123!',
                'group'       => 'instructor',
                'first_name'  => 'Maria',
                'middle_name' => 'Reyes',
                'last_name'   => 'Santos',
                'suffix'      => null,
            ],

            [
                'username'    => 'instructor.demo2',
                'email'       => 'instructor2@mentora.test',
                'password'    => 'Instructor123!',
                'group'       => 'instructor',
                'first_name'  => 'Joseph',
                'middle_name' => 'Lim',
                'last_name'   => 'Garcia',
                'suffix'      => null,
            ],

            // =====================================================
            // Chairperson
            // =====================================================

            [
                'username'    => 'chairperson.demo1',
                'email'       => 'chairperson1@mentora.test',
                'password'    => 'Chairperson123!',
                'group'       => 'chairperson',
                'first_name'  => 'Antonio',
                'middle_name' => 'Rivera',
                'last_name'   => 'Fernandez',
                'suffix'      => null,
            ],

            // =====================================================
            // Parent
            // =====================================================

            [
                'username'    => 'parent.demo1',
                'email'       => 'parent1@mentora.test',
                'password'    => 'Parent123!',
                'group'       => 'parent',
                'first_name'  => 'Roberto',
                'middle_name' => 'Diaz',
                'last_name'   => 'Cruz',
                'suffix'      => null,
            ],

        ];

        foreach ($accounts as $account) {

            $existing = $userModel->findByCredentials([
                'email' => $account['email'],
            ]);

            if ($existing !== null) {
                echo "[SKIPPED] {$account['email']}" . PHP_EOL;
                continue;
            }

            $user = new User([
                'username' => $account['username'],
                'email'    => $account['email'],
                'password' => $account['password'],
                'active'   => true,
            ]);

            $userModel->save($user);

            /** @var User $user */
            $user = $userModel->findById($userModel->getInsertID());

            $user->addGroup($account['group']);

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