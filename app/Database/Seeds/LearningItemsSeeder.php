<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LearningItemsSeeder extends Seeder
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
                'learning_unit_id' => 1,
                'title'            => 'Welcome to the Course',
                'item_type'        => 'reading',
                'content'          => json_encode([
                    'groups' => [
                        [
                            'heading' => 'Welcome',
                            'text'    => 'Welcome to Mentora LMS. This course is designed to guide you through the fundamentals while helping you become familiar with the learning platform.',
                        ],
                        [
                            'heading' => 'Course Structure',
                            'text'    => 'The course is organized into modules, learning units, and learning items. Complete each learning item to track your overall progress.',
                        ],
                        [
                            'heading' => 'Getting Started',
                            'text'    => 'Read each lesson carefully, watch the accompanying videos, and review the provided resources before moving to the next learning item.',
                        ],
                    ],
                ]),
                'order_index'      => 1,
                'status'           => 'published',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

            [
                'learning_unit_id' => 1,
                'title'            => 'Course Orientation',
                'item_type'        => 'video',
                'content'          => json_encode([
                    'autoplay'      => false,
                    'show_controls' => true,
                ]),
                'order_index'      => 2,
                'status'           => 'published',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

            /*
            |--------------------------------------------------------------------------
            | Unit 2
            |--------------------------------------------------------------------------
            */

            [
                'learning_unit_id' => 2,
                'title'            => 'Installing XAMPP',
                'item_type'        => 'reading',
                'content'          => json_encode([
                    'groups' => [
                        [
                            'heading' => 'Downloading XAMPP',
                            'text'    => 'Download the latest version of XAMPP from the official Apache Friends website that matches your operating system.',
                        ],
                        [
                            'heading' => 'Installing XAMPP',
                            'text'    => 'Run the installer and follow the installation wizard. The default installation settings are sufficient for this course.',
                        ],
                        [
                            'heading' => 'Verify the Installation',
                            'text'    => 'Open the XAMPP Control Panel and start Apache and MySQL. Visit http://localhost in your browser to verify that the installation was successful.',
                        ],
                    ],
                ]),
                'order_index'      => 1,
                'status'           => 'published',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

            [
                'learning_unit_id' => 2,
                'title'            => 'XAMPP Installation Guide',
                'item_type'        => 'pdf',
                'content'          => json_encode([
                    'display_mode'   => 'embed',
                    'allow_download' => true,
                ]),
                'order_index'      => 2,
                'status'           => 'published',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

            /*
            |--------------------------------------------------------------------------
            | Unit 3
            |--------------------------------------------------------------------------
            */

            [
                'learning_unit_id' => 3,
                'title'            => 'Understanding PHP Variables',
                'item_type'        => 'reading',
                'content'          => json_encode([
                    'groups' => [
                        [
                            'heading' => 'What is a Variable?',
                            'text'    => 'A variable is a named container used to store data that can be accessed and modified throughout a PHP program.',
                        ],
                        [
                            'heading' => 'Variable Naming Rules',
                            'text'    => 'Variables begin with a dollar sign followed by a valid identifier. Choose meaningful names to improve the readability and maintainability of your code.',
                        ],
                        [
                            'heading' => 'Common Data Types',
                            'text'    => 'PHP variables can store strings, integers, floats, booleans, arrays, objects, and many other types of data.',
                        ],
                    ],
                ]),
                'order_index'      => 1,
                'status'           => 'published',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

            [
                'learning_unit_id' => 3,
                'title'            => 'PHP Variables Reference',
                'item_type'        => 'pdf',
                'content'          => json_encode([
                    'display_mode'   => 'embed',
                    'allow_download' => true,
                ]),
                'order_index'      => 2,
                'status'           => 'published',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

            [
                'learning_unit_id' => 3,
                'title'            => 'PHP Variables Explained',
                'item_type'        => 'video',
                'content'          => json_encode([
                    'autoplay'      => false,
                    'show_controls' => true,
                ]),
                'order_index'      => 3,
                'status'           => 'published',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],

        ];

        $this->db
            ->table('learning_items')
            ->insertBatch($rows);

        echo "Learning items seeded.\n";
    }
}