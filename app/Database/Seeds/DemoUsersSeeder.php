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
                'student_number' => '2026-02000',
                'program_id'  => 1,
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
                'student_number' => '2026-03001',
                'program_id'  => 1,
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
                'student_number' => '2026-04002',
                'program_id'  => 1,
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
                'student_number' => '2026-05003',
                'program_id'  => 1,
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
                'student_number' => '2026-06004',
                'program_id'  => 1,
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
                'student_number' => null,
                'program_id'  => 1,
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
                'student_number' => null,
                'program_id'  => 1,
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
                'student_number' => null,
                'program_id'  => 1,
            ],

            // Dual-role: chairs one sub-department, also teaches — exercises
            // role-switching and the "defaults to chairperson" first-login rule.
            [
                'username'    => 'chairperson.demo2',
                'email'       => 'chairperson2@mentora.test',
                'password'    => 'Chairperson123!',
                'group'       => ['chairperson', 'instructor'],
                'first_name'  => 'Isabel',
                'middle_name' => 'Torres',
                'last_name'   => 'Mendoza',
                'suffix'      => null,
                'student_number' => null,
                'program_id'  => 1,
            ],

            // Dean-only: oversees the general department.
            [
                'username'       => 'dean.demo1',
                'email'          => 'dean1@mentora.test',
                'password'       => 'Dean123!',
                'group'          => 'dean',
                'first_name'     => 'Daniel',
                'middle_name'    => 'Santos',
                'last_name'      => 'Reyes',
                'suffix'         => null,
                'student_number' => null,
                'program_id'     => null,
                'institution_id' => 1,
            ],

            // Dual-role: oversees CBM, chairs its IT sub-department, and teaches.
            [
                'username'       => 'dean.demo2',
                'email'          => 'dean2@mentora.test',
                'password'       => 'Dean123!',
                'group'          => ['dean', 'chairperson', 'instructor'],
                'first_name'     => 'Diana',
                'middle_name'    => 'Torres',
                'last_name'      => 'Mendoza',
                'suffix'         => null,
                'student_number' => null,
                'program_id'     => 1,
                'institution_id' => 1,
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
                'student_number' => null,
                'program_id'  => null,
                'institution_id' => 1,
            ],

            // =====================================================
            // Institutional administrator (of institution 1)
            // =====================================================

            [
                'username'       => 'institution.admin',
                'email'          => 'institution.admin@mentora.test',
                'password'       => 'Institution123!',
                'group'          => 'institutional_admin',
                'first_name'     => 'Ines',
                'middle_name'    => null,
                'last_name'      => 'Administrador',
                'suffix'         => null,
                'student_number' => null,
                'program_id'     => null,
                'institution_id' => 1,
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

            foreach ((array) $account['group'] as $group) {
                $user->addGroup($group);
            }

            $profileModel->insert([
                'user_id'     => $user->id,
                'first_name'  => $account['first_name'],
                'middle_name' => $account['middle_name'],
                'last_name'   => $account['last_name'],
                'suffix'      => $account['suffix'],
                'student_number' => $account['student_number'],
                'program_id'  => $account['program_id'],
            ]);

            // Accounts that belong to an institution straight away (the others are linked by
            // InstitutionUsersSeeder, by id).
            if (isset($account['institution_id'])) {
                $now = date('Y-m-d H:i:s');

                $this->db->table('institution_users')->insert([
                    'institution_id' => $account['institution_id'],
                    'user_id'        => $user->id,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }

            echo '[CREATED] ' . implode('+', (array) $account['group']) . " ({$account['email']})" . PHP_EOL;
        }
    }
}