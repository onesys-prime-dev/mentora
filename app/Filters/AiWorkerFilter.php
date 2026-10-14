<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Guards /internal/ai/*, the endpoints the question-builder worker pulls its
 * jobs through. There is no session here: the caller must come from this
 * machine (loopback) and present the shared secret. With no secret configured
 * the endpoints answer 503 rather than staying open.
 */
class AiWorkerFilter implements FilterInterface
{
    public const HEADER = 'X-Worker-Secret';

    public function before(RequestInterface $request, $arguments = null)
    {
        $secret = (string) env('questionBuilder.workerSecret', '');

        if ($secret === '') {
            return service('response')->setStatusCode(503)->setJSON(['error' => 'The worker interface is not configured.']);
        }

        // The raw socket address, not a forwarded header a client can set.
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if (! in_array($remote, ['127.0.0.1', '::1'], true)) {
            return service('response')->setStatusCode(403)->setJSON(['error' => 'Not available from here.']);
        }

        if (! hash_equals($secret, $request->getHeaderLine(self::HEADER))) {
            return service('response')->setStatusCode(401)->setJSON(['error' => 'Unauthorized.']);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
