<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\RecipientSearch;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The new-message picker: people the person may start a thread with, by name
 * (or visible email), a page at a time; `?after=` is the `next` of the page before.
 */
class RecipientController extends MessagingController
{
    public function index(): ResponseInterface
    {
        $search = new RecipientSearch();
        $after  = $this->request->getGet('after');
        $page   = $search->page(auth()->user(), (string) $this->request->getGet('q'), is_numeric($after) ? (int) $after : null);

        // Told only to the parent in question, about themselves (S2-i).
        if ($search->noLinkedChild(auth()->user())) {
            $page['reason'] = 'no_linked_child';
        }

        return $this->response->setJSON($page);
    }
}
