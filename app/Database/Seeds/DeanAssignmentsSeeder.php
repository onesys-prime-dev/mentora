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
            $college = $this->db->table('colleges')->where([
                'institution_id' => 1, 'code' => $code,
            ])->get()->getRowArray();

            if ($user === null || $college === null) {
                echo "Dean assignment skipped for {$email}.\n";
                continue;
            }

            if ($this->db->table('dean_assignments')->where([
                'college_id' => $college['id'], 'status' => 'active',
            ])->countAllResults() === 0) {
                $service->assign(1, (int) $college['id'], (int) $user->id);
            }
        }
    }
}