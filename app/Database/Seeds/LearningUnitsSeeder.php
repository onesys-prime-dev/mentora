<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LearningUnitsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $data = [

            // Module 1
            [
                'module_id' => 1,
                'title' => 'Introduction to the Course',
                'description' => 'Course overview and expectations.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'module_id' => 1,
                'title' => 'Installing Development Tools',
                'description' => 'Setup environment.',
                'order_index' => 2,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Module 2
            [
                'module_id' => 2,
                'title' => 'PHP Syntax Review',
                'description' => 'Refresh PHP basics.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'module_id' => 2,
                'title' => 'Variables and Data Types',
                'description' => 'Core PHP concepts.',
                'order_index' => 2,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Module 3
            [
                'module_id' => 3,
                'title' => 'MVC Architecture',
                'description' => 'Understanding MVC.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'module_id' => 3,
                'title' => 'Routing Fundamentals',
                'description' => 'Routing in CodeIgniter.',
                'order_index' => 2,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $this->db->table('learning_units')->insertBatch($data);
    }
}