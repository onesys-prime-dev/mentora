<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Uploads used to be split: runtime uploads in writable/uploads, the seeded
 * demo files in public/uploads. Everything is now served from
 * writable/uploads through files/{id}, so copy any file whose row exists
 * but whose disk file is missing — from the old public folder if it's
 * still there, otherwise from the seed assets that replaced it. Also
 * corrects the recorded size, which the old seeder made up.
 */
class CopySeedUploadsToWritable extends Migration
{
    public function up()
    {
        $sources = [
            FCPATH . 'uploads/',
            APPPATH . 'Database/Seeds/assets/uploads/',
        ];

        $files = $this->db->table('files')->select(['id', 'storage_key'])->get()->getResultArray();

        foreach ($files as $file) {

            $key = ltrim($file['storage_key'], '/');

            $target = WRITEPATH . 'uploads/' . $key;

            if (! is_file($target)) {

                foreach ($sources as $root) {

                    if (is_file($root . $key)) {

                        if (! is_dir(dirname($target))) {
                            mkdir(dirname($target), 0777, true);
                        }

                        copy($root . $key, $target);

                        break;
                    }

                }

            }

            if (is_file($target)) {
                $this->db->table('files')->where('id', $file['id'])->update(['file_size' => filesize($target)]);
            }

        }
    }

    public function down()
    {
        // Nothing to undo: the copies are the files' only home now.
    }
}
