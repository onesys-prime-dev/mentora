<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Models\UserModel;

class FilesSeeder extends Seeder
{
    public function run()
    {
        $userModel = model(UserModel::class);

        $instructor = $userModel->findByCredentials([
            'email' => 'instructor1@mentora.test',
        ]);

        if (! $instructor) {
            echo "Instructor demo user not found.\n";
            return;
        }

        $now = date('Y-m-d H:i:s');

        $rows = [

            /*
            |--------------------------------------------------------------------------
            | Course Videos
            |--------------------------------------------------------------------------
            */

            [
                'owner_user_id'     => $instructor->id,
                'original_filename' => 'Course Orientation.mp4',
                'stored_filename'   => 'course-orientation.mp4',
                'mime_type'         => 'video/mp4',
                'file_size'         => 48765432,
                'storage_key'      => 'videos/course-orientation.mp4',
                'status'            => 'active',
                'uploaded_at'       => $now,
                'updated_at'        => $now,
            ],

            /*
            |--------------------------------------------------------------------------
            | Course Documents
            |--------------------------------------------------------------------------
            */

            [
                'owner_user_id'     => $instructor->id,
                'original_filename' => 'XAMPP Installation Guide.pdf',
                'stored_filename'   => 'xampp-installation-guide.pdf',
                'mime_type'         => 'application/pdf',
                'file_size'         => 1254876,
                'storage_key'      => 'documents/xampp-installation-guide.pdf',
                'status'            => 'active',
                'uploaded_at'       => $now,
                'updated_at'        => $now,
            ],

            [
                'owner_user_id'     => $instructor->id,
                'original_filename' => 'PHP Variables Reference.pdf',
                'stored_filename'   => 'php-variables-reference.pdf',
                'mime_type'         => 'application/pdf',
                'file_size'         => 987245,
                'storage_key'      => 'documents/php-variables-reference.pdf',
                'status'            => 'active',
                'uploaded_at'       => $now,
                'updated_at'        => $now,
            ],

        ];

        $this->db
            ->table('files')
            ->insertBatch($rows);

        echo "Files seeded.\n";
    }
}