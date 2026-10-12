<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Admin\ReauthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route option for dangerous actions in an administrative area (the system
 * administrator's and the institutional administrator's): `['filter' => 'reauth']`.
 *
 * Lets the request through only if the person typed their password within the
 * last few minutes; otherwise sends them to the confirm page (or, for a
 * script, answers 403 with a JSON error) before any controller runs.
 * The system administrator confirms at admin/reauth, everyone else at reauth.
 */
class ReauthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = auth()->user();

        if ($user === null) {
            return redirect()->to('/login');
        }

        $reauth = new ReauthService();

        if ($reauth->isFresh($user, time())) {
            return null;
        }

        /** @var IncomingRequest $request */
        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => 'reauth_required', 'message' => 'Confirm your password to continue.']);
        }

        // A GET can be repeated after the confirm page; anything else would
        // not be, so those send the person back to the page they came from.
        $reauth->rememberReturn(strtoupper($request->getMethod()) === 'GET' ? $this->currentPath($request) : $this->refererPath($request));

        return redirect()->to($user->inGroup('admin') ? 'admin/reauth' : 'reauth');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function currentPath(IncomingRequest $request): string
    {
        $query = $request->getUri()->getQuery();

        return $request->getPath() . ($query !== '' ? '?' . $query : '');
    }

    private function refererPath(IncomingRequest $request): string
    {
        $referer = $request->getHeaderLine('Referer');

        // The address with the front controller in it (where the server shows index.php) first,
        // then without: both are this site.
        foreach ([site_url('/'), base_url()] as $base) {
            if (str_starts_with($referer, $base)) {
                return substr($referer, strlen($base));
            }
        }

        return '';
    }
}
