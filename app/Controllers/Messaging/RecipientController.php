<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\RecipientSearch;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The new-message picker: people the person may start a thread with, by name.
 */
class RecipientController extends MessagingController
{
    public function index(): ResponseInterface
    {
        return $this->response->setJSON([
            'recipients' => (new RecipientSearch())->search(auth()->user(), (string) $this->request->getGet('q')),
        ]);
    }
}
