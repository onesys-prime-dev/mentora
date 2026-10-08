<?php

namespace App\Database\Seeds;

use App\Services\Admin\SystemAdminService;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Models\UserModel;
use InvalidArgumentException;

/**
 * DEMO DATA ONLY — one system administrator for local development.
 * Real deployments create theirs with `php spark admin:create`.
 */
class SystemAdminSeeder extends Seeder
{
    public function run()
    {
        $email = 'admin@mentora.test';

        if (model(UserModel::class)->findByCredentials(['email' => $email]) !== null) {
            echo "[SKIPPED] {$email}" . PHP_EOL;

            return;
        }

        try {
            (new SystemAdminService())->create($email, 'admin.demo', 'Administrator123!');
            echo "[CREATED] admin ({$email})" . PHP_EOL;
        } catch (InvalidArgumentException $e) {
            echo "[SKIPPED] {$email}: {$e->getMessage()}" . PHP_EOL;
        }
    }
}
