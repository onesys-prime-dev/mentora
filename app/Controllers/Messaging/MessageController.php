<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\MessageService;
use App\Services\Messaging\RateLimitedException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The messages of one conversation: a page of them, send, mark read, hide for me.
 * A conversation or message that is not the person's answers the shared 404.
 */
class MessageController extends MessagingController
{
    private const FAILURES = ['invalid_body' => 422, 'not_allowed' => 403, 'unavailable' => 503];

    public function page(int $conversationId): ResponseInterface
    {
        $page = (new MessageService())->page(
            auth()->user(),
            $conversationId,
            $this->intOrNull($this->request->getGet('before')),
            $this->intOrNull($this->request->getGet('after')),
            (int) ($this->request->getGet('limit') ?: MessageService::PAGE),
        );

        return $page === null ? $this->notFound() : $this->response->setJSON($page);
    }

    public function send(int $conversationId): ResponseInterface
    {
        $input  = $this->input();

        try {
            $result = (new MessageService())->send(auth()->user(), $conversationId, (string) ($input['body'] ?? ''), (string) ($input['client_uuid'] ?? ''));
        } catch (RateLimitedException $e) {
            return $this->rateLimited($e);
        }

        if ($result['ok']) {
            return $this->response->setStatusCode(201)->setJSON(['message' => $result['message']]);
        }

        $reason = $result['reason'] ?? 'not_found';

        if (! isset(self::FAILURES[$reason])) {
            return $this->notFound();
        }

        return $this->response->setStatusCode(self::FAILURES[$reason])->setJSON(['error' => $reason]);
    }

    public function read(int $conversationId): ResponseInterface
    {
        $messageId = $this->input()['message_id'] ?? null;
        $ok        = is_numeric($messageId) && (new MessageService())->markRead(auth()->user(), $conversationId, (int) $messageId);

        return $ok ? $this->response->setJSON(['ok' => true]) : $this->notFound();
    }

    public function hide(int $conversationId, int $messageId): ResponseInterface
    {
        return (new MessageService())->hideForMe(auth()->user(), $conversationId, $messageId)
            ? $this->response->setJSON(['ok' => true])
            : $this->notFound();
    }

    public function remove(int $conversationId, int $messageId): ResponseInterface
    {
        return (new MessageService())->remove(auth()->user(), $conversationId, $messageId)
            ? $this->response->setJSON(['ok' => true])
            : $this->notFound();
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
