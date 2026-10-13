<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Institutions\InstitutionAccess;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route option for the role areas: `['filter' => 'readonly']`.
 *
 * When the signed-in person's institution is read-only, past or deleted, any
 * request that would change something (anything but GET, HEAD and OPTIONS) is
 * refused before a controller runs. Looking and exporting are all GETs and go
 * through. A script gets a 403 with a JSON error; a browser is sent back with
 * a message.
 */
class ReadOnlyFilter implements FilterInterface
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE, true)) {
            return null;
        }

        $user = auth()->user();

        if ($user === null) {
            return null; // not signed in: the login filter deals with that
        }

        $state = (new InstitutionAccess())->stateFor((int) $user->id);

        if ($state === InstitutionAccess::WRITABLE) {
            return null;
        }

        $message = $state === InstitutionAccess::READ_ONLY
            ? "Your institution's subscription has ended, so it is read-only for now. You can still look at and export your work, but nothing can be changed."
            : 'Your institution is no longer active. You can still look at and export your work, but nothing can be changed.';

        /** @var IncomingRequest $request */
        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => 'institution_read_only', 'message' => $message]);
        }

        return redirect()->back()->with('error', $message);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
