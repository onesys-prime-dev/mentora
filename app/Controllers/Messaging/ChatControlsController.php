<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\ChatControls;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A manager flips one switch on a chat. A chat that is not theirs, or that they may
 * not manage, answers the shared 404.
 */
class ChatControlsController extends MessagingController
{
    /** The administrator's picker: every course chat and staff group of their institution, switches only. */
    public function index(): ResponseInterface
    {
        return $this->response->setJSON(['chats' => (new ChatControls())->chatsFor(auth()->user())]);
    }

    public function update(int $conversationId): ResponseInterface
    {
        $input  = $this->input();
        $switch = is_string($input['switch'] ?? null) ? $input['switch'] : '';

        if (! is_bool($input['on'] ?? null)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'invalid']);
        }

        $result = (new ChatControls())->set(auth()->user(), $conversationId, $switch, $input['on']);

        if ($result === null) {
            return $this->notFound();
        }

        if (! $result['ok']) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'invalid']);
        }

        return $this->response->setJSON(['ok' => true, 'controls' => $result['controls']]);
    }
}
