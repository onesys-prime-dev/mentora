<?php

use App\Services\Storage\StorageService;

if (! function_exists('file_url')) {

    /**
     * Protected URL for an uploaded file (served by FileController, which
     * checks access). Pass $download to get an attachment instead of
     * displaying it inline.
     */
    function file_url(int $fileId, bool $download = false): string
    {
        return (new StorageService())->url($fileId, $download);
    }
}
