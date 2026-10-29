<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Parents\ParentPortal;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * For the parent's course routes: while the parent portal is switched off
 * (by Mentora or by the institution), nothing is read. A page answers with the
 * portal-off page and an API call with a 403 and a plain message, both before
 * any controller runs.
 *
 * Applied as `parentportal`; the group's `role:parent` filter has already
 * decided the area.
 */
class ParentPortalFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = auth()->user();

        if ($user === null || (new ParentPortal())->enabled((new PlanLimits())->institutionOf((int) $user->id))) {
            return null;
        }

        $response = service('response');

        if (in_array('api', $request->getUri()->getSegments(), true)) {
            return $response->setStatusCode(403)->setJSON(['success' => false, 'message' => 'The parent portal is switched off.']);
        }

        return $response->setBody(view('pages/parent/portal-off', ['title' => 'Parent', 'page' => 'dashboard', 'user' => $user]));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
