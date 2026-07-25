<?php

namespace App\Services\Storage;

class StorageService
{
    /**
     * Build a publicly accessible URL for a stored file.
     */
    public function getUrl(array $file): string
    {
        $baseUrl = rtrim(
            env('storage.baseURL'),
            '/'
        );

        $storageKey = ltrim(
            $file['storage_key'],
            '/'
        );

        return "{$baseUrl}/{$storageKey}";
    }
}