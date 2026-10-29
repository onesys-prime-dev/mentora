<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * The CSRF check, answered the Mentora way (audit finding 4).
 *
 * A request that changes something without the page's token is refused the
 * same way in every environment: a script gets a 403 with a JSON error it can
 * show; a browser is sent back to the form with what was typed and a plain
 * message. The token is one per session (Config\Security), so it goes stale
 * when the session ended: someone who is no longer signed in is sent to the
 * sign-in page with the message, unless the form was one for signed-out
 * people (signing in, a password reset, an invitation), which they get back.
 *
 * It checks the token itself rather than through CodeIgniter's CSRF filter,
 * because that filter answers a browser on its own in production
 * (Config\Security::$redirect) and drops what was typed.
 */
class CsrfFilter implements FilterInterface
{
    public const MESSAGE = 'This page was open too long. Reload it and try again.';

    /** The forms people use while signed out (Shield's and the app's own). */
    private const SIGNED_OUT_FORMS = '#^(login|register|auth/a/|invitation/|reset-password/)#';

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $body = $request->getBody();

        try {
            service('security')->verify($request);
        } catch (SecurityException) {
            return $this->refuse($request);
        }

        // After the check the framework strips the token from the body, and rewrites a JSON body that is not an
        // object (`[]`, `[1,2]`) into something that is no longer JSON. A token sent in the header was never in
        // the body, so the body goes back exactly as it came.
        if ($body !== null && $request->getHeaderLine('X-CSRF-TOKEN') !== '') {
            $request->setBody($body);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function refuse(IncomingRequest $request): ResponseInterface
    {
        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => 'csrf_expired', 'message' => self::MESSAGE]);
        }

        $path = trim($request->getPath(), '/');

        if (! auth()->loggedIn() && preg_match(self::SIGNED_OUT_FORMS, $path) !== 1) {
            return redirect()->to(site_url('login'))->with('error', self::MESSAGE);
        }

        return redirect()->to($this->formPage($request))->withInput()->with('error', self::MESSAGE);
    }

    /** The page the form was on: the Referer when it is this site, else the last page seen, else home. */
    private function formPage(IncomingRequest $request): string
    {
        $referer = $request->getHeaderLine('Referer');

        if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === parse_url(site_url(), PHP_URL_HOST)) {
            return $referer;
        }

        return previous_url();
    }
}
