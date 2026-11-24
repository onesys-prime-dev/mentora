<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Services\Profiles\ProfileContextResolver;
use App\Services\Profiles\ProfilePhotoService;
use App\Services\Profiles\ProfilePolicy;
use App\Services\Storage\StorageService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * /people/{id}/photo/{64|256} (D6): the photo only for viewers ProfilePolicy gives `photo`, while the
 * subject's institution has photos on and the photo exists. Anything else is a 404, so ids cannot be probed.
 */
class ProfilePhotoController extends BaseController
{
    public function show(int $id, string $size): ResponseInterface
    {
        $context = (new ProfileContextResolver())->for(auth()->user(), $id);
        if ($context === null || ! in_array('photo', (new ProfilePolicy())->fields($context), true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $file = (new ProfilePhotoService())->file($id, (int) $size) ?? throw PageNotFoundException::forPageNotFound();

        // Small (at most a few tens of KB), so sent whole; ?v= changes on replace, so a day's private cache is safe.
        return $this->response
            ->setContentType('image/jpeg', '')
            ->setHeader('Cache-Control', 'private, max-age=86400')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody((string) file_get_contents((new StorageService())->path($file['storage_key'])));
    }
}
