<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LearningCompletionsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            // Student 1
            [
                'learning_item_id' => 1,
                'student_user_id' => 1,
                'status' => 'completed',
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'learning_item_id' => 2,
                'student_user_id' => 1,
                'status' => 'completed',
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'learning_item_id' => 3,
                'student_user_id' => 1, 
                'status' => 'in_progress',
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Student 2
            [
                'learning_item_id' => 1,
                'student_user_id' => 2,
                'status' => 'in_progress',
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'learning_item_id' => 2,
                'student_user_id' => 2,
                'status' => 'not_started',
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Student 3
            [
                'learning_item_id' => 1,
                'student_user_id' => 3,
                'status' => 'completed',
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'learning_item_id' => 2,
                'student_user_id' => 3,
                'status' => 'completed',
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'learning_item_id' => 3,
                'student_user_id' => 3,
                'status' => 'completed',
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],

        ];

        $this->db
            ->table('learning_completions')
            ->insertBatch($rows);
    }
}