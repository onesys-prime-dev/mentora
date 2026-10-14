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

        $dean = $users->findByCredentials(['email' => 'dean2@mentora.test']);
        $subDepartment = $this->db->table('sub_departments sd')
            ->select('sd.id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where(['d.institution_id' => 1, 'd.code' => 'CBM', 'sd.code' => 'IT'])
            ->get()->getRowArray();

        if ($dean !== null && $subDepartment !== null && $this->db->table('chairperson_assignments')->where([
            'sub_department_id' => $subDepartment['id'],
            'chairperson_user_id' => $dean->id,
            'status' => 'active',
        ])->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $this->db->table('chairperson_assignments')->insert([
                'sub_department_id' => $subDepartment['id'],
                'chairperson_user_id' => $dean->id,
                'status' => 'active',
                'assigned_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}