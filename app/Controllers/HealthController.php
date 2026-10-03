<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public liveness check used by the deploy pipeline (and any uptime monitor).
 *
 * Answers 200 only when the app can reach its database, 503 otherwise. It
 * reports nothing beyond that: no versions, paths or error details (those go
 * to the log), so it is safe to leave unauthenticated.
 */
class HealthController extends BaseController
{
    public function index(): ResponseInterface
    {
        $databaseUp = true;

        try {
            db_connect()->query('SELECT 1');
        } catch (\Throwable $e) {
            $databaseUp = false;
            log_message('error', 'Health check: database unreachable: ' . $e->getMessage());
        }

        return $this->response
            ->setStatusCode($databaseUp ? 200 : 503)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON([
                'status' => $databaseUp ? 'ok' : 'degraded',
                'database' => $databaseUp ? 'up' : 'down',
            ]);
    }
}
