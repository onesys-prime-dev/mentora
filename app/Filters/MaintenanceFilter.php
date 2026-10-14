<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Admin\PlatformSettings;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeZone;
use Throwable;

/**
 * Runs before every request (Config\Filters, globals).
 *
 * 1. Applies the platform's timezone setting for the rest of the request.
 * 2. While maintenance mode is on, turns everyone but system administrators
 *    away with a 503 and the maintenance message. Administrators (and an
 *    administrator viewing as someone) carry on, so they can switch it off.
 *    The sign-in pages, sign-out and /health stay reachable so an
 *    administrator can get in and uptime checks still work.
 */
class MaintenanceFilter implements FilterInterface
{
    private const OPEN_PATHS = ['login', 'logout', 'health', 'auth', 'impersonation/stop', 'reset-password', 'invitation', 'internal/ai'];

    public function before(RequestInterface $request, $arguments = null)
    {
        try {
            $settings = new PlatformSettings();

            $zone = (string) $settings->get('timezone');

            if ($zone !== '' && in_array($zone, DateTimeZone::listIdentifiers(), true)) {
                date_default_timezone_set($zone);
            }

            if (! (bool) $settings->get('maintenanceMode')) {
                return null;
            }
        } catch (Throwable $e) {
            // Settings unreadable (the database is down, say): do not turn the whole site away because of it.
            log_message('error', 'Maintenance filter could not read settings: ' . $e->getMessage());

            return null;
        }

        if (session('impersonator_id') !== null || (auth()->loggedIn() && auth()->user()->inGroup('admin'))) {
            return null;
        }

        /** @var IncomingRequest $request */
        $path = trim($request->getUri()->getPath(), '/');

        foreach (self::OPEN_PATHS as $open) {
            if (preg_match('#(^|/)' . preg_quote($open, '#') . '(/|$)#', $path) === 1) {
                return null;
            }
        }

        $message = (string) $settings->get('maintenanceMessage');

        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')->setStatusCode(503)->setHeader('Retry-After', '300')->setJSON(['error' => 'maintenance', 'message' => $message]);
        }

        return service('response')
            ->setStatusCode(503)
            ->setHeader('Retry-After', '300')
            ->setBody(view('errors/maintenance', [
                'title'   => (string) $settings->get('systemName') . ': maintenance',
                'message' => $message,
                'contact' => (string) $settings->get('supportContact'),
            ]));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
