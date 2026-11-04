<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\ChatStateService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The person's own state on a chat: pin, mute, archive, mark unread, delete for me.
 * Each changes only the caller's row; a chat that is not theirs answers the shared 404.
 */
class ChatStateController extends MessagingController
{
    public function pin(int $conversationId): ResponseInterface
    {
        return $this->respond((new ChatStateService())->pin(auth()->user(), $conversationId));
    }

    public function unpin(int $conversationId): ResponseInterface
    {
        return $this->respond((new ChatStateService())->unpin(auth()->user(), $conversationId));
    }

    public function mute(int $conversationId): ResponseInterface
    {
        return $this->respond((new ChatStateService())->setMuted(auth()->user(), $conversationId, true));
    }

    public function unmute(int $conversationId): ResponseInterface
    {
        return $this->respond((new ChatStateService())->setMuted(auth()->user(), $conversationId, false));
    }

    public function archive(int $conversationId): ResponseInterface
    {
        return $this->respond((new ChatStateService())->setArchived(auth()->user(), $conversationId, true));
    }

    public function unarchive(int $conversationId): ResponseInterface
    {
        return $this->respond((new ChatStateService())->setArchived(auth()->user(), $conversationId, false));
    }

    public function unread(int $conversationId): ResponseInterface
    {
        return $this->respond((new ChatStateService())->markUnread(auth()->user(), $conversationId));
    }

    public function read(int $conversationId): ResponseInterface
    {
        return $this->respond((new ChatStateService())->markRead(auth()->user(), $conversationId));
    }

    public function delete(int $conversationId): ResponseInterface
    {
        return $this->respond((new ChatStateService())->deleteForMe(auth()->user(), $conversationId));
    }

    /** @param array{ok: bool, reason?: string} $result */
    private function respond(array $result): ResponseInterface
    {
        if ($result['ok']) {
            return $this->response->setJSON(['ok' => true]);
        }

        return match ($result['reason'] ?? 'not_found') {
            'not_allowed' => $this->response->setStatusCode(403)->setJSON(['error' => 'not_allowed']),
            'limit'       => $this->response->setStatusCode(409)->setJSON(['error' => 'limit', 'max' => ChatStateService::MAX_PINNED]),
            default       => $this->notFound(),
        };
    }
}
