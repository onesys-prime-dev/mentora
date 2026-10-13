<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * For a page that posts its forms from a script (shared/dialog.js): a
 * redirect answered to such a request is handed back as JSON `{redirect}`
 * instead of being followed by the browser.
 *
 * Following it would show the script the next page's HTML and use up the
 * flash message that was set for that page. Handed back as JSON, the script
 * goes there itself, and the message is waiting when it arrives. A page's own
 * forms, with no script, still get the plain redirect.
 */
class AjaxRedirectFilter implements FilterInterface
{
    private const REDIRECTS = [301, 302, 303, 307, 308];

    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        /** @var IncomingRequest $request */
        if (! $request->isAJAX() || ! in_array($response->getStatusCode(), self::REDIRECTS, true)) {
            return null;
        }

        $location = $response->getHeaderLine('Location');

        if ($location === '') {
            return null;
        }

        $response->removeHeader('Location');

        return $response->setStatusCode(200)->setJSON(['redirect' => $location]);
    }
}
