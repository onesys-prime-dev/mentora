<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Admin\Impersonation;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Viewing as another person, view-only (SA5). Starting is an admin route
 * (permission and password confirmation in front of it); stopping is a public
 * route, because while viewing the session is the other person's and must not
 * need the admin group to get out.
 */
class ImpersonationController extends BaseController
{
    public function start(int $userId): RedirectResponse
    {
        try {
            (new Impersonation())->start(auth()->user(), $userId, (string) $this->request->getPost('reason'));
        } catch (InvalidArgumentException $e) {
            return redirect()->to("admin/users/{$userId}")->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('/');
    }

    public function stop(): RedirectResponse
    {
        $target = (new Impersonation())->stop('stopped');

        if ($target === null) {
            return redirect()->to('/');
        }

        return redirect()->to("admin/users/{$target}")->with('message', 'You are back as yourself.');
    }
}
