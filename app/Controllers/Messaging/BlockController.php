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

        return is_numeric($id) && (new BlockService())->block(auth()->user(), (int) $id)
            ? $this->response->setJSON(['ok' => true])
            : $this->notFound();
    }

    public function remove(int $blockedId): ResponseInterface
    {
        return (new BlockService())->unblock(auth()->user(), $blockedId)
            ? $this->response->setJSON(['ok' => true])
            : $this->notFound();
    }
}
