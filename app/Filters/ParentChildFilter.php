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
 * institution. Anything else (another parent's child, an unlinked student,
 * an id that does not exist) is a 404 before a controller runs, which does
 * not confirm the student exists.
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

        $user = auth()->user();

        if ($user === null || ! (new ParentAccessService())->canViewChild($user, (int) $id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
