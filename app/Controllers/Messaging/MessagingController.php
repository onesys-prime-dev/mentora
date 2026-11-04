<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Controllers\BaseController;
use App\Filters\MessagesFilter;
use App\Services\Messaging\RateLimitedException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared by the Messages JSON controllers: reading input and the one 404 that
 * answers a conversation or message that is not the person's, so that it looks
 * exactly like one that does not exist.
 */
abstract class MessagingController extends BaseController
{
    /** @return array<string, mixed> */
    protected function input(): array
    {
        $json = $this->request->getJSON(true);

        return is_array($json) ? $json : (array) $this->request->getPost();
    }

    /** 429 with the friendly sentence; nothing was written. */
    protected function rateLimited(RateLimitedException $e): ResponseInterface
    {
        return $this->response->setStatusCode(429)->setJSON(['error' => 'rate_limited', 'message' => $e->getMessage()]);
    }

    protected function notFound(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setJSON(MessagesFilter::NOT_FOUND);
    }
}
