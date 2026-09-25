<?php

namespace App\Controllers;

use App\Services\Storage\FileAccessService;
use App\Services\Storage\StorageService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * The only way uploaded files reach a browser: files/{id}. Files live
 * outside the web root; FileAccessService decides who may see each one.
 */
class FileController extends BaseController
{
    public function show(int $fileId)
    {
        $file = (new FileAccessService())->authorize(auth()->user(), $fileId);

        // 404 rather than 403, so file ids can't be probed for existence.
        if (! $file) {
            throw PageNotFoundException::forPageNotFound();
        }

        // A video is fetched as many Range requests while it plays; holding
        // the session lock that long would stall the student's other
        // requests (progress saves, navigation) until each chunk finishes.
        session()->close();

        return (new StorageService())->stream(
            $file,
            $this->request,
            $this->response,
            $this->request->getGet('download') === '1'
        );
    }
}
