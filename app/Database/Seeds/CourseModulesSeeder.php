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
                'title' => 'Introduction to Programming',
                'description' => 'Course orientation and overview.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => $now,
            ],
            [
                'offering_id' => 1,
                'title' => 'Variables and Data Types',
                'description' => 'Primitive data types and variables.',
                'order_index' => 2,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => $now,
            ],
            [
                'offering_id' => 2,
                'title' => 'Object-Oriented Programming',
                'description' => 'Classes and Objects.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => $now,
            ],
            [
                'offering_id' => 3,
                'title' => 'Database Fundamentals',
                'description' => 'Introduction to relational databases.',
                'order_index' => 1,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => $now,
            ],
        ]);
    }
}