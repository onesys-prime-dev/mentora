<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Parents\ParentAccessService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * For every route of the form `parent/children/{id}/...`: the child named in
 * the address must be one the signed-in parent is linked to, in their own
 * institution; and when the address goes on to name a course
 * (`.../courses/{offering}/...`), the child must be enrolled in it. Anything
 * else (another parent's child, an unlinked student, a course the child is not
 * in, an id that does not exist) is a 404 before a controller runs, which does
 * not confirm the student or the course exists.
 *
 * Applied as `parentchild`; the route group's `role:parent` filter has
 * already decided the area.
 */
class ParentChildFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $segments = $request->getUri()->getSegments();
        $at       = array_search('children', $segments, true);
        $id       = $at === false ? '' : ($segments[$at + 1] ?? '');

        if (! ctype_digit($id) || (int) $id < 1) {
            throw PageNotFoundException::forPageNotFound();
        }

        $user   = auth()->user();
        $access = new ParentAccessService();

        if ($user === null || ! $access->canViewChild($user, (int) $id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // A course route also names an offering: the child must be in it (any enrolment status).
        $courses = array_search('courses', $segments, true);

        if ($courses !== false) {
            $offering = $segments[$courses + 1] ?? '';

            if (! ctype_digit($offering) || ! $access->canViewOffering($user, (int) $id, (int) $offering)) {
                throw PageNotFoundException::forPageNotFound();
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
