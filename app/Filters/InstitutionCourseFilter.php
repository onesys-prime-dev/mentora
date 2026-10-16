<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Courses\CourseReadAccess;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route option for the read-only course views: `['filter' => 'institutioncourse']`
 * for the institutional administrator and `['filter' => 'deancourse']` for the
 * dean (the same filter; CourseReadAccess decides by who is looking).
 *
 * The offering named in `.../courses/{id}/...` must be one the person may look
 * at (their institution's, or their department's); anything else is a 404
 * before a controller runs, so a course outside their reach cannot be reached
 * by guessing its number
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

        if ($user === null || ! (new CourseReadAccess())->canAccess($user, (int) $segments[$courses + 1])) {
            throw PageNotFoundException::forPageNotFound();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
