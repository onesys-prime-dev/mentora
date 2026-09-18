<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LearningResourcesSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [

            /*
            |--------------------------------------------------------------------------
            | Unit 1
            |--------------------------------------------------------------------------
            */

            [
                'learning_item_id' => 2,
                'file_id'          => 1,
                'title'            => 'Course Orientation Video',
                'resource_type'    => 'video',
                'resource_url'     => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

            /*
            |--------------------------------------------------------------------------
            | Unit 2
            |--------------------------------------------------------------------------
            */

            [
                'learning_item_id' => 4,
                'file_id'          => 2,
                'title'            => 'XAMPP Installation Guide',
                'resource_type'    => 'pdf',
                'resource_url'     => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

            /*
            |--------------------------------------------------------------------------
            | Unit 3
            |--------------------------------------------------------------------------
            */

            [
                'learning_item_id' => 6,
                'file_id'          => 3,
                'title'            => 'PHP Variables Reference',
                'resource_type'    => 'pdf',
                'resource_url'     => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

            [
                'learning_item_id' => 7,
                'file_id'          => null,
                'title'            => 'PHP Variables Explained (YouTube)',
                'resource_type'    => 'video',
                'resource_url'     => 'https://www.youtube.com/watch?v=OK_JCtrrv-c',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

        ];

        $this->db
            ->table('learning_resources')
            ->insertBatch($rows);

        echo "Learning resources seeded.\n";
    }
}