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

        $files = [
            [
                'owner_user_id'      => $instructor->id,
                'original_filename'  => 'Course Syllabus.pdf',
                'stored_filename'    => 'course-syllabus.pdf',
                'mime_type'          => 'application/pdf',
                'file_size'          => 254321,
                'storage_path'       => 'uploads/course-syllabus.pdf',
                'status'             => 'active',
                'uploaded_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'owner_user_id'      => $instructor->id,
                'original_filename'  => 'Introduction.pptx',
                'stored_filename'    => 'introduction.pptx',
                'mime_type'          => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'file_size'          => 1823312,
                'storage_path'       => 'uploads/introduction.pptx',
                'status'             => 'active',
                'uploaded_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'owner_user_id'      => $instructor->id,
                'original_filename'  => 'Week1-Handout.pdf',
                'stored_filename'    => 'week1-handout.pdf',
                'mime_type'          => 'application/pdf',
                'file_size'          => 983421,
                'storage_path'       => 'uploads/week1-handout.pdf',
                'status'             => 'active',
                'uploaded_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        $this->db
            ->table('files')
            ->insertBatch($files);

        echo "Files seeded.\n";
    }
}