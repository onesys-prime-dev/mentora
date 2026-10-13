<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route option for the role areas: `['filter' => 'closedoffering']`.
 *
 * Once an offering is no longer active (its term was closed, or it was
 * archived) it is history: any request that would change something in it
 * (anything but GET, HEAD and OPTIONS) is refused before a controller runs.
 * Looking, downloading and exporting are GETs and go through. The offering is
 * the one named in `.../courses/{id}/...`, or `offering_id` in a JSON body
 * (the calendar posts announcements that way). A script gets a 403 with a
 * JSON error; a browser is sent back with a message.
 */
class ClosedOfferingFilter implements FilterInterface
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE, true)) {
            return null;
        }

        $offeringId = $this->offeringId($request);

        if ($offeringId === null) {
            return null;
        }

        $row = db_connect()->table('course_offerings')->select('status')->where('id', $offeringId)->get()->getRowArray();

        if ($row === null || $row['status'] === 'active') {
            return null;
        }

        $message = 'This course is closed, so nothing in it can be changed. You can still look at it and download what is there.';

        /** @var IncomingRequest $request */
        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')->setStatusCode(403)->setJSON(['error' => 'offering_closed', 'message' => $message]);
        }

        return redirect()->back()->with('error', $message);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function offeringId(RequestInterface $request): ?int
    {
        /** @var IncomingRequest $request */
        $segments = $request->getUri()->getSegments();
        $courses  = array_search('courses', $segments, true);

        if ($courses !== false && isset($segments[$courses + 1]) && ctype_digit($segments[$courses + 1])) {
            return (int) $segments[$courses + 1];
        }

        if (str_contains($request->getHeaderLine('Content-Type'), 'json')) {
            $body = $request->getJSON(true);

            if (is_array($body) && isset($body['offering_id']) && ctype_digit((string) $body['offering_id'])) {
                return (int) $body['offering_id'];
            }
        }

        return null;
    }
}
