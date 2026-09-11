<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class CourseModulesSeeder extends Seeder
{
    public function run()
    {
        $builder = $this->db->table('course_modules');

        $now = date('Y-m-d H:i:s');

        $builder->insertBatch([
            [
                'offering_id' => 1,
                'title' => 'Introduction to Python',
                'description' => 'Course orientation and overview.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'offering_id' => 1,
                'title' => 'Control Structures',
                'description' => 'Learn how to manage program flow using conditional statements and loops.',
                'order_index' => 2,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'offering_id' => 1,
                'title' => 'Data Structures',
                'description' => 'Classes and Objects.',
                'order_index' => 3,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}