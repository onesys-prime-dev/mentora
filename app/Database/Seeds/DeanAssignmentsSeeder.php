<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Services\Institutions\DeanAssignmentService;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Models\UserModel;

class DeanAssignmentsSeeder extends Seeder
{
    public function run()
    {
        $users = model(UserModel::class);
        $service = new DeanAssignmentService();

        foreach ([['dean1@mentora.test', 'GEN'], ['dean2@mentora.test', 'CBM']] as [$email, $code]) {
            $user = $users->findByCredentials(['email' => $email]);
            $department = $this->db->table('departments')->where([
                'institution_id' => 1, 'code' => $code,
            ])->get()->getRowArray();

            if ($user === null || $department === null) {
                echo "Dean assignment skipped for {$email}.\n";
                continue;
            }

            if ($this->db->table('dean_assignments')->where([
                'department_id' => $department['id'], 'status' => 'active',
            ])->countAllResults() === 0) {
                $service->assign(1, (int) $department['id'], (int) $user->id);
            }
        }
    }
}