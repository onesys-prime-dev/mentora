<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LearningResourcesSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            [
                'learning_item_id' => 1,
                'file_id' => null,
                'title' => 'Welcome Page',
                'resource_type' => 'page',
                'resource_url' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'learning_item_id' => 2,
                'file_id' => null,
                'title' => 'Orientation Video',
                'resource_type' => 'youtube',
                'resource_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'learning_item_id' => 4,
                'file_id' => 1,
                'title' => 'Development Checklist',
                'resource_type' => 'file',
                'resource_url' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'learning_item_id' => 5,
                'file_id' => 2,
                'title' => 'PHP Variables Handout',
                'resource_type' => 'file',
                'resource_url' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            [
                'learning_item_id' => 6,
                'file_id' => null,
                'title' => 'PHP Official Documentation',
                'resource_type' => 'link',
                'resource_url' => 'https://www.php.net/manual/en/',
                'created_at' => $now,
                'updated_at' => $now,
            ],

        ];

        $this->db
            ->table('learning_resources')
            ->insertBatch($rows);
    }
}