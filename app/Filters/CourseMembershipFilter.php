<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Courses\CourseMembership;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * For every course route of the student, instructor and chairperson areas:
 * the person must belong to the offering named in the address, as the role
 * of the area they are in. Anything else is a 404 before a controller runs,
 * which does not confirm the offering exists.
 *
 * Applied as `coursemember:<role>`; the route's own `role:` filter has
 * already decided the area. Runs ahead of the filters that look inside the
 * offering (assessmentscope, closedoffering, chairpersoncourseaudit).
 */
class CourseMembershipFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $role = is_array($arguments) ? (string) ($arguments[0] ?? '') : '';

        $segments = $request->getUri()->getSegments();
        $courses  = array_search('courses', $segments, true);

        if ($courses === false) {
            return null;
        }

        $offering = $segments[$courses + 1] ?? '';

        // A list of courses (no offering named) is each area's own page, scoped by its controller.
        if ($offering === '') {
            return null;
        }

        $user = auth()->user();

        if ($user === null || ! ctype_digit($offering) || ! (new CourseMembership())->canAccessAs($user, $role, (int) $offering)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
