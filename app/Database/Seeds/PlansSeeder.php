<?php

namespace App\Database\Seeds;

use App\Models\Subscriptions\PlanModel;
use CodeIgniter\Database\Seeder;

/**
 * DEMO DATA ONLY — one sample plan so the plans page and, later, the
 * subscription forms have something to choose. Real plans are created by the
 * system administrator in the app. Safe to run twice.
 */
class PlansSeeder extends Seeder
{
    public function run()
    {
        $name  = 'Standard';
        $model = new PlanModel();

        if ($model->where('name', $name)->countAllResults() > 0) {
            echo "[SKIPPED] plan {$name}" . PHP_EOL;

            return;
        }

        $model->insert([
            'name'                => $name,
            'description'         => 'A sample plan: 500 student seats, 20 GB of storage and 200 AI question generations per term.',
            'features'            => json_encode([]),
            'seat_limit'          => 500,
            'storage_limit_mb'    => 20480,
            'ai_generation_limit' => 200,
            'status'              => PlanModel::ACTIVE,
        ]);

        echo "[CREATED] plan {$name}" . PHP_EOL;
    }
}
