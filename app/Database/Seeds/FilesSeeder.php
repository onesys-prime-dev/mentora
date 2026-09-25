<?php

namespace App\Database\Seeds;

use App\Services\Storage\StorageService;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Models\UserModel;

class FilesSeeder extends Seeder
{
    /** Demo files that ship with the seeders, laid out by storage key. */
    public const ASSET_ROOT = APPPATH . 'Database/Seeds/assets/uploads/';

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

        /*
        |--------------------------------------------------------------------------
        | Copy the demo files into storage
        |--------------------------------------------------------------------------
        |
        | Uploads live in writable/uploads (served through files/{id}), not
        | in public/. The demo files ship with the seeders and are copied into
        | place here; the recorded size is taken from the real file.
        |
        */

        $storage = new StorageService();

        foreach ($rows as &$row) {

            $source = self::ASSET_ROOT . $row['storage_key'];

            $target = $storage->path($row['storage_key']);

            if (is_file($source)) {

                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0777, true);
                }

                if (! is_file($target)) {
                    copy($source, $target);
                }

                $row['file_size'] = filesize($source);

            }

        }
        unset($row);

        $this->db
            ->table('files')
            ->insertBatch($rows);

        echo "Files seeded.\n";
    }
}