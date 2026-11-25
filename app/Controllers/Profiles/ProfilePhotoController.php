<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Exceptions\PlanLimitExceeded;
use App\Services\Profiles\ProfileEditException;
use App\Services\Profiles\ProfileModeration;
use App\Services\Profiles\ProfileContextResolver;
use App\Services\Profiles\ProfilePhotoService;
use App\Services\Profiles\ProfilePolicy;
use App\Services\Storage\StorageService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
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

        // no-cache: the browser may keep it but asks every time, so photos turning off or access ending applies at once.
        $etag = '"' . (int) $file['id'] . '-' . (int) $size . '"';
        $this->response->removeHeader('Cache-Control')->setHeader('Cache-Control', 'private, no-cache')->setHeader('ETag', $etag)->setHeader('X-Content-Type-Options', 'nosniff');

        if ($this->request->getHeaderLine('If-None-Match') === $etag) {
            return $this->response->setStatusCode(304)->setBody('');
        }

        // Small (at most a few tens of KB), so sent whole.
        return $this->response
            ->setContentType('image/jpeg', '')
            ->setBody((string) file_get_contents((new StorageService())->path($file['storage_key'])));
    }

    /** The signed-in person's own photo, from the crop dialog's file. */
    public function upload(): ResponseInterface|RedirectResponse
    {
        $user = auth()->user();
        ProfileController::refuseSystemAdmin();

        return $this->answer('profile', 'Photo saved', fn () => (new ProfilePhotoService())->upload((int) $user->id, $this->request->getFile('photo') ?? throw new ProfileEditException('Choose an image file.', 'photo'), $user));
    }

    public function remove(): ResponseInterface|RedirectResponse
    {
        $user = auth()->user();
        ProfileController::refuseSystemAdmin();

        return $this->answer('profile', 'Photo removed', fn () => (new ProfilePhotoService())->remove((int) $user->id, $user));
    }

    /** An institution administrator adds a photo for someone in their institution (people.manage; another institution is a 404). */
    public function adminUpload(int $id): ResponseInterface|RedirectResponse
    {
        return $this->answer($this->returnTo($id), 'Photo saved', fn () => (new ProfileModeration())->uploadPhoto($id, $this->request->getFile('photo'), auth()->user()));
    }

    public function adminRemove(int $id): ResponseInterface|RedirectResponse
    {
        return $this->answer($this->returnTo($id), 'Photo removed', fn () => (new ProfileModeration())->removePhoto($id, auth()->user()));
    }

    /** Back to the People list when the request came from it, else the person's page; no other posted path is followed. */
    private function returnTo(int $id): string
    {
        return $this->request->getPost('return') === 'institution/people' ? 'institution/people' : "people/{$id}";
    }

    /** Runs a photo change and answers the dialog's JSON (or a redirect without JavaScript). */
    private function answer(string $back, string $done, callable $do): ResponseInterface|RedirectResponse
    {
        $json = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back = site_url($back);

        try {
            $do();
        } catch (ProfileEditException $e) {
            $problem = $e->getMessage();
        } catch (PlanLimitExceeded) {
            $problem = "Your school's storage is full, so the photo could not be saved.";
        }

        if (isset($problem)) {
            return $json
                ? $this->response->setStatusCode(422)->setJSON(['errors' => ['photo' => $problem], 'message' => ''])
                : redirect()->to($back)->with('error', $problem);
        }

        if ($json) {
            session()->setFlashdata('message', $done);

            return $this->response->setJSON(['message' => $done, 'redirect' => $back]);
        }

        return redirect()->to($back)->with('message', $done);
    }
}
