<?php

namespace App\Services\Storage;

use CodeIgniter\Files\File;

class StorageService
{
    /*
    |--------------------------------------------------------------------------
    | Upload File
    |--------------------------------------------------------------------------
    */

    public function upload(
        File $file,
        string $directory
    ): array {

        $filename =

            $file->getRandomName();

        $path =

            rtrim(
                $directory,
                '/'
            );

        $file->move(

            WRITEPATH . "uploads/{$path}",

            $filename

        );

        return [

            'storage_key' =>
                "{$path}/{$filename}",

            'original_name' =>
                $file->getClientName(),

            'filename' =>
                $filename,

            'mime_type' =>
                $file->getMimeType(),

            'extension' =>
                $file->getExtension(),

            'size' =>
                $file->getSize(),

        ];

    }

    /*
    |--------------------------------------------------------------------------
    | Delete File
    |--------------------------------------------------------------------------
    */

    public function delete(
        string $storageKey
    ): void {

        $path =

            WRITEPATH .
            "uploads/" .
            ltrim(
                $storageKey,
                '/'
            );

        if (

            is_file($path)

        ) {

            unlink($path);

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Build Public URL
    |--------------------------------------------------------------------------
    */

    public function getUrl(
        array $file
    ): string {

        $baseUrl =

            rtrim(

                env(
                    'storage.baseURL'
                ),

                '/'

            );

        $storageKey =

            ltrim(

                $file['storage_key'],

                '/'

            );

        return

            "{$baseUrl}/{$storageKey}";

    }

}