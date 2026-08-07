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
                'content' => json_encode([
                    'version' => 1,
                    'blocks' => [
                        [
                            'id' => 'block_001',
                            'type' => 'heading',
                            'level' => 1,
                            'align' => 'center',
                            'content' => [
                                ['text' => 'Welcome to the Course']
                            ],
                        ],
                        [
                            'id' => 'block_002',
                            'type' => 'paragraph',
                            'align' => 'justify',
                            'content' => [
                                ['text' => 'Welcome to '],
                                ['text' => 'Mentora LMS', 'marks' => ['bold']],
                                ['text' => '. This course is designed to guide you through the fundamentals while helping you become familiar with the learning platform.'],
                            ],
                        ],
                        [
                            'id' => 'block_003',
                            'type' => 'heading',
                            'level' => 2,
                            'align' => 'left',
                            'content' => [
                                ['text' => 'Course Structure']
                            ],
                        ],
                        [
                            'id' => 'block_004',
                            'type' => 'paragraph',
                            'align' => 'justify',
                            'content' => [
                                ['text' => 'The course is organized into '],
                                ['text' => 'modules', 'marks' => ['bold']],
                                ['text' => ', '],
                                ['text' => 'learning units', 'marks' => ['italic']],
                                ['text' => ', and learning items. Complete every learning item to monitor your progress.'],
                            ],
                        ],
                        [
                            'id' => 'block_005',
                            'type' => 'unordered-list',
                            'align' => 'left',
                            'items' => [
                                [['text' => 'Read every lesson carefully']],
                                [['text' => 'Watch accompanying videos']],
                                [['text' => 'Complete assessments']],
                                [['text' => 'Track your overall progress']],
                            ],
                        ],
                        [
                            'id' => 'block_006',
                            'type' => 'heading',
                            'level' => 2,
                            'align' => 'center',
                            'content' => [
                                ['text' => 'Getting Started']
                            ],
                        ],
                        [
                            'id' => 'block_007',
                            'type' => 'paragraph',
                            'align' => 'justify',
                            'content' => [
                                ['text' => 'Before proceeding to the next learning item, review all provided resources and ensure that you understand the concepts discussed in each lesson.'],
                            ],
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
                'content' => json_encode([
                    'version' => 1,
                    'blocks' => [
                        [
                            'id' => 'block_001',
                            'type' => 'heading',
                            'level' => 1,
                            'align' => 'center',
                            'content' => [
                                ['text' => 'Installing XAMPP']
                            ],
                        ],
                        [
                            'id' => 'block_002',
                            'type' => 'paragraph',
                            'align' => 'justify',
                            'content' => [
                                ['text' => 'XAMPP provides an easy way to install '],
                                ['text' => 'Apache', 'marks' => ['bold']],
                                ['text' => ', MySQL, PHP, and other development tools on your computer.'],
                            ],
                        ],
                        [
                            'id' => 'block_003',
                            'type' => 'heading',
                            'level' => 2,
                            'align' => 'left',
                            'content' => [
                                ['text' => 'Installation Steps']
                            ],
                        ],
                        [
                            'id' => 'block_004',
                            'type' => 'ordered-list',
                            'align' => 'left',
                            'items' => [
                                [['text' => 'Download XAMPP from Apache Friends']],
                                [['text' => 'Run the installer']],
                                [['text' => 'Accept the default configuration']],
                                [['text' => 'Finish the installation']],
                            ],
                        ],
                        [
                            'id' => 'block_005',
                            'type' => 'heading',
                            'level' => 2,
                            'align' => 'left',
                            'content' => [
                                ['text' => 'Verify Installation']
                            ],
                        ],
                        [
                            'id' => 'block_006',
                            'type' => 'paragraph',
                            'align' => 'justify',
                            'content' => [
                                ['text' => 'Open the '],
                                ['text' => 'XAMPP Control Panel', 'marks' => ['bold']],
                                ['text' => ', start Apache and MySQL, then visit '],
                                ['text' => 'http://localhost', 'marks' => ['underline']],
                                ['text' => ' in your browser.'],
                            ],
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
                'content' => json_encode([
                    'version' => 1,
                    'blocks' => [
                        [
                            'id' => 'block_001',
                            'type' => 'heading',
                            'level' => 1,
                            'align' => 'center',
                            'content' => [
                                ['text' => 'Understanding PHP Variables']
                            ],
                        ],
                        [
                            'id' => 'block_002',
                            'type' => 'paragraph',
                            'align' => 'justify',
                            'content' => [
                                ['text' => 'Variables are named containers used to store values in a PHP program.'],
                            ],
                        ],
                        [
                            'id' => 'block_003',
                            'type' => 'heading',
                            'level' => 2,
                            'align' => 'left',
                            'content' => [
                                ['text' => 'Naming Rules']
                            ],
                        ],
                        [
                            'id' => 'block_004',
                            'type' => 'unordered-list',
                            'align' => 'left',
                            'items' => [
                                [['text' => 'Variables begin with a dollar sign ($).']],
                                [['text' => 'Names should be meaningful.']],
                                [['text' => 'Names cannot start with numbers.']],
                                [['text' => 'Use camelCase or snake_case consistently.']],
                            ],
                        ],
                        [
                            'id' => 'block_005',
                            'type' => 'heading',
                            'level' => 2,
                            'align' => 'left',
                            'content' => [
                                ['text' => 'Common Data Types']
                            ],
                        ],
                        [
                            'id' => 'block_006',
                            'type' => 'paragraph',
                            'align' => 'justify',
                            'content' => [
                                ['text' => 'PHP supports '],
                                ['text' => 'strings', 'marks' => ['bold']],
                                ['text' => ', integers, floats, booleans, arrays, objects, and many other data types.'],
                            ],
                        ],
                        [
                            'id' => 'block_007',
                            'type' => 'ordered-list',
                            'align' => 'left',
                            'items' => [
                                [['text' => 'Declare a variable']],
                                [['text' => 'Assign a value']],
                                [['text' => 'Use it throughout your program']],
                            ],
                        ],
                        [
                            'id' => 'block_008',
                            'type' => 'paragraph',
                            'align' => 'center',
                            'content' => [
                                ['text' => 'Good variable names improve code readability.', 'marks' => ['italic']],
                            ],
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