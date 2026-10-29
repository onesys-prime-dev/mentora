<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Guards POST internal/scheduler-check, which the uptime workflow calls from
 * GitHub every hour (audit U2). There is no session: the caller presents the
 * shared secret (`scheduler.checkSecret` in .env). Unlike the AI worker's, it
 * is not limited to this machine, since GitHub calls it. With no secret
 * configured it answers 503 rather than staying open.
 */
class SchedulerCheckFilter implements FilterInterface
{
    public const HEADER = 'X-Scheduler-Secret';

    public function before(RequestInterface $request, $arguments = null)
    {
        $secret = (string) env('scheduler.checkSecret', '');

        if ($secret === '') {
            return service('response')->setStatusCode(503)->setJSON(['error' => 'The scheduler check is not configured.']);
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
