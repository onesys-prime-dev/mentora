<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Notifications\NotificationService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * A signed-in person's own notifications, for every role: the list, and
 * marking them read. A person only ever sees and changes their own.
 */
class NotificationController extends BaseController
{
    public function index(): string
    {
        $user    = auth()->user();
        $isAdmin = $user->inGroup('admin');

        return view('pages/notifications/index', [
            'title'         => 'Notifications',
            'page'          => $isAdmin ? 'admin' : 'notifications',
            'user'          => $user,
            'noteRows'      => (new NotificationService())->listFor((int) $user->id),
            'noteIsAdmin'   => $isAdmin,
        ]);
    }

    public function read(): RedirectResponse
    {
        $id = $this->request->getPost('id');

        (new NotificationService())->markRead(
            (int) auth()->id(),
            is_string($id) && preg_match('/^\d{1,10}$/', $id) === 1 ? (int) $id : null,
        );

        return redirect()->to('notifications');
    }
}
