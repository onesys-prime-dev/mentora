<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Notifications\NotificationService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

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

    /** The next batch of the bell panel's list, as ready-made rows. */
    public function feed(): ResponseInterface
    {
        $before = $this->request->getGet('before');

        $page = (new NotificationService())->page(
            (int) auth()->id(),
            is_string($before) && preg_match('/^\d{1,10}$/', $before) === 1 ? (int) $before : null,
            7,
        );

        $html = '';

        foreach ($page['rows'] as $row) {
            $html .= view('layouts/partials/notification-item', ['row' => $row], ['saveData' => false]);
        }

        return $this->response->setJSON([
            'html'        => $html,
            'count'       => count($page['rows']),
            'next_before' => $page['rows'] === [] ? null : (int) end($page['rows'])['id'],
            'has_more'    => $page['has_more'],
        ]);
    }

    public function read(): RedirectResponse
    {
        $id = $this->request->getPost('id');

        // No id is "Mark all as read"; an id that is there but not a notification's marks nothing (QA AMB-COM-02).
        if ($id !== null && ! (is_string($id) && preg_match('/^\d{1,10}$/', $id) === 1)) {
            return redirect()->to('notifications');
        }

        (new NotificationService())->markRead((int) auth()->id(), $id === null ? null : (int) $id);

        return redirect()->to('notifications');
    }
}
