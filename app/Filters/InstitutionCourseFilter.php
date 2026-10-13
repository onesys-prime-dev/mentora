<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Institutions\InstitutionCourseAccess;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route option for the institutional administrator's read-only course view:
 * `['filter' => 'institutioncourse']`.
 *
 * The offering named in `.../courses/{id}/...` must be one of the person's
 * own institution; anything else is a 404 before a controller runs, so a
 * course of another institution cannot be reached by guessing its number
 * (the course services decide access for their own roles and are not asked
 * about this one).
 */
class InstitutionCourseFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $segments = $request->getUri()->getSegments();
        $courses  = array_search('courses', $segments, true);

        if ($courses === false || ! isset($segments[$courses + 1]) || ! ctype_digit($segments[$courses + 1])) {
            return null;
        }

        $user = auth()->user();

        if ($user === null || ! (new InstitutionCourseAccess())->canAccess($user, (int) $segments[$courses + 1])) {
            throw PageNotFoundException::forPageNotFound();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
