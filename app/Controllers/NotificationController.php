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

        // The same page for every role: the administrator's copy keeps their menu, but has the same
        // background and lit bell as everyone else's.
        return view('pages/notifications/index', [
            'title'         => 'Notifications',
            'fullHeight'    => ! $isAdmin,
            'flat'          => false,
            'page'          => 'notifications',
            'user'          => $user,
            'noteRows'      => (new NotificationService())->listFor((int) $user->id),
            'noteIsAdmin'   => $isAdmin,
        ]);
    }

    /** The next batch of the bell panel's list, as ready-made rows. */
    public function feed(): ResponseInterface
    {
        $before = $this->request->getGet('before');
        $limit  = $this->request->getGet('limit');
        $limit  = is_string($limit) && ctype_digit($limit) ? max(1, min(10, (int) $limit)) : 7;

        $page = (new NotificationService())->page(
            (int) auth()->id(),
            is_string($before) && preg_match('/^\d{1,10}$/', $before) === 1 ? (int) $before : null,
            $limit,
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

    /**
     * For the toast beside the bell. Without `after`: the newest id (the baseline) and the unread count. With it:
     * up to 3 unread notifications newer than that id. Titles and bodies are the same words the panel shows.
     */
    public function latest(): ResponseInterface
    {
        $service = new NotificationService();
        $userId  = (int) auth()->id();
        $after   = $this->request->getGet('after');
        $rows    = $service->page($userId, null, 10)['rows'];
        $lastId  = $rows === [] ? 0 : (int) $rows[0]['id'];

        $items = [];

        if (is_string($after) && preg_match('/^\d{1,10}$/', $after) === 1) {
            foreach (array_reverse($rows) as $row) {
                if ((int) $row['id'] > (int) $after && $row['read_at'] === null && count($items) < 3) {
                    $items[] = [
                        'id'     => (int) $row['id'],
                        'title'  => (string) $row['title'],
                        'body'   => (string) $row['body'],
                        'link'   => $row['link'] === null ? null : site_url((string) $row['link']),
                        'urgent' => (int) $row['urgent'] === 1,
                    ];
                }
            }
        }

        return $this->response->setJSON(['last_id' => $lastId, 'unread' => $service->unreadCount($userId), 'items' => $items]);
    }

    /** Deletes one notification of the caller's own; anything else answers the same 404. */
    public function delete(): ResponseInterface
    {
        $id = $this->request->getPost('id');

        if (! (is_string($id) && preg_match('/^\d{1,10}$/', $id) === 1)
            || ! (new NotificationService())->delete((int) auth()->id(), (int) $id)) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }

        return $this->response->setJSON(['ok' => true]);
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
