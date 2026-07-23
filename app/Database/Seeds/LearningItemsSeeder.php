<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LearningItemsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            // Unit 1
            [
                'learning_unit_id' => 1,
                'title' => 'Welcome to the Course',
                'item_type' => 'reading',
                'content' => 'Welcome to Mentora LMS.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'learning_unit_id' => 1,
                'title' => 'Course Orientation Video',
                'item_type' => 'video',
                'content' => null,
                'order_index' => 2,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Unit 2

            [
                'learning_unit_id' => 2,
                'title' => 'Install XAMPP',
                'item_type' => 'reading',
                'content' => 'Install the latest version of XAMPP.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'learning_unit_id' => 2,
                'title' => 'Development Environment Checklist',
                'item_type' => 'file',
                'content' => null,
                'order_index' => 2,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Unit 3

            [
                'learning_unit_id' => 3,
                'title' => 'PHP Variables',
                'item_type' => 'reading',
                'content' => 'Introduction to PHP variables.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'learning_unit_id' => 3,
                'title' => 'Practice Exercise',
                'item_type' => 'assignment',
                'content' => 'Complete the variable exercises.',
                'order_index' => 2,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $this->db->table('learning_items')->insertBatch($rows);
    }
}