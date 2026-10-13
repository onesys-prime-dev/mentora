<?php

declare(strict_types=1);

namespace App\Exceptions;

use CodeIgniter\HTTP\ResponsableInterface;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * Thrown deep inside an upload when the institution's plan has no room left.
 * It knows how to answer the request itself, so the many places that upload
 * need no handling of their own: a script gets a 403 with a JSON error, a
 * browser is sent back with the message.
 */
class PlanLimitExceeded extends RuntimeException implements ResponsableInterface
{
    public function __construct(string $message, private readonly string $limit = 'plan')
    {
        parent::__construct($message);
    }

    /** Which limit: seats, storage or ai. */
    public function limit(): string
    {
        return $this->limit;
    }

    public function getResponse(): ResponseInterface
    {
        $request = service('request');

        if (method_exists($request, 'isAJAX') && ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json'))) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => 'plan_limit_exceeded', 'limit' => $this->limit, 'message' => $this->getMessage()]);
        }

        return redirect()->back()->with('error', $this->getMessage());
    }
}
