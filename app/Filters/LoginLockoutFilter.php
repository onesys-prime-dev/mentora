<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Security\LoginLockout;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Runs before every request (Config\Filters, globals) and only acts on a
 * sign-in attempt (POST to /login): when that email is locked out (see
 * LoginLockout) the attempt is refused before the password is looked at.
 * The message does not say whether the account exists.
 */
class LoginLockoutFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        /** @var IncomingRequest $request */
        if (strtoupper($request->getMethod()) !== 'POST' || ! preg_match('#(^|/)login$#', trim($request->getUri()->getPath(), '/'))) {
            return null;
        }

        $until = (new LoginLockout())->lockedUntil((string) $request->getPost('email'));

        if ($until === null) {
            return null;
        }

        return redirect()->to('/login')->withInput()->with('error', 'Too many failed sign-in attempts. Try again after ' . $until->format('H:i') . '.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
