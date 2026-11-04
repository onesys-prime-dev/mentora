<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\MessageActions;
use App\Services\Messaging\RateLimitedException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Actions on one message: react, and who reacted. A conversation or message that is not
 * the person's answers the shared 404; a refusal for someone who is in the chat is 403/422.
 */
class MessageActionController extends MessagingController
{
    private const FAILURES = ['not_allowed' => 403, 'switched_off' => 403, 'parent_thread' => 403, 'too_late' => 403, 'invalid' => 422, 'limit' => 409, 'unavailable' => 503];

    public function react(int $conversationId, int $messageId): ResponseInterface
    {
        $emoji = $this->input()['emoji'] ?? null;

        if ($emoji !== null && ! is_string($emoji)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'invalid']);
        }

        try {
            $result = (new MessageActions())->react(auth()->user(), $conversationId, $messageId, $emoji);
        } catch (RateLimitedException $e) {
            return $this->rateLimited($e);
        }

        if ($result['ok']) {
            return $this->response->setJSON(['reactions' => $result['reactions'] ?? []]);
        }

        $reason = $result['reason'] ?? 'not_found';

        return isset(self::FAILURES[$reason])
            ? $this->response->setStatusCode(self::FAILURES[$reason])->setJSON(['error' => $reason])
            : $this->notFound();
    }

    public function deleteEveryone(int $conversationId, int $messageId): ResponseInterface
    {
        $result = (new MessageActions())->deleteForEveryone(auth()->user(), $conversationId, $messageId);

        if ($result['ok']) {
            return $this->response->setJSON(['ok' => true]);
        }

        $reason = $result['reason'] ?? 'not_found';

        return isset(self::FAILURES[$reason])
            ? $this->response->setStatusCode(self::FAILURES[$reason])->setJSON(['error' => $reason])
            : $this->notFound();
    }

    public function bump(int $conversationId, int $messageId): ResponseInterface
    {
        try {
            $result = (new MessageActions())->bump(auth()->user(), $conversationId, $messageId);
        } catch (RateLimitedException $e) {
            return $this->rateLimited($e);
        }

        if ($result['ok']) {
            return $this->response->setJSON(['message' => $result['message'] ?? null]);
        }

        $reason = $result['reason'] ?? 'not_found';

        return isset(self::FAILURES[$reason])
            ? $this->response->setStatusCode(self::FAILURES[$reason])->setJSON(['error' => $reason])
            : $this->notFound();
    }

    public function forward(int $conversationId, int $messageId): ResponseInterface
    {
        $targets = $this->input()['targets'] ?? null;

        if (! is_array($targets) || ! array_is_list($targets) || array_filter($targets, static fn ($t): bool => ! is_int($t)) !== []) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'invalid']);
        }

        $result = (new MessageActions())->forward(auth()->user(), $conversationId, $messageId, $targets);

        if ($result['ok']) {
            return $this->response->setJSON(['results' => $result['results'] ?? []]);
        }

        $reason = $result['reason'] ?? 'not_found';

        if ($reason === 'too_many') {
            return $this->response->setStatusCode(422)->setJSON(['error' => $reason]);
        }

        return isset(self::FAILURES[$reason])
            ? $this->response->setStatusCode(self::FAILURES[$reason])->setJSON(['error' => $reason])
            : $this->notFound();
    }

    public function reactors(int $conversationId, int $messageId): ResponseInterface
    {
        $reactors = (new MessageActions())->reactors(auth()->user(), $conversationId, $messageId);

        return $reactors === null ? $this->notFound() : $this->response->setJSON(['reactors' => (object) $reactors]);
    }
}
