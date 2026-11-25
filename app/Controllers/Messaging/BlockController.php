<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\BlockService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Block and unblock someone, and who the person has blocked. No decisions
 * here; BlockService refuses whoever cannot be blocked, with the shared 404.
 */
class BlockController extends MessagingController
{
    public function index(): ResponseInterface
    {
        return $this->response->setJSON(['blocked' => (new BlockService())->blockedBy(auth()->user())]);
    }

    public function create(): ResponseInterface
    {
        $id = $this->input()['user_id'] ?? null;

        if (! is_numeric($id)) {
            return $this->notFound();
        }

        $blocks = new BlockService();

        if ($blocks->block(auth()->user(), (int) $id)) {
            return $this->response->setJSON(['ok' => true]);
        }

        $message = $blocks->refusal(auth()->user(), (int) $id);

        return $message === null ? $this->notFound() : $this->response->setStatusCode(422)->setJSON(['reason' => 'protected_link', 'message' => $message]);
    }

    public function remove(int $blockedId): ResponseInterface
    {
        return (new BlockService())->unblock(auth()->user(), $blockedId)
            ? $this->response->setJSON(['ok' => true])
            : $this->notFound();
    }
}
