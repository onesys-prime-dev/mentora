<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Admin\Impersonation;
use App\Services\Users\AccountControl;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Runs before every request (Config\Filters, globals, ahead of accountstate).
 *
 * While a system administrator is viewing as someone it makes the view
 * strictly read-only and time-limited, on the server, whatever the page or a
 * script sends:
 *  - anything but GET, HEAD and OPTIONS is refused, except leaving the view;
 *  - when the time limit has passed the view is ended and the administrator
 *    is back in their own session;
 *  - signing out ends the view instead of signing the administrator out;
 *  - if the administrator's own account has been switched off, the view is
 *    ended and the normal account check takes over; if the viewed account is
 *    switched off, the view ends too.
 */
class ImpersonationFilter implements FilterInterface
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $state = Impersonation::current();

        if ($state === null) {
            return null;
        }

        $impersonation = new Impersonation();

        $signedInAt = session(AccountStateFilter::SIGNED_IN_AT);

        if ((new AccountControl())->blockReason($state['admin_id'], is_numeric($signedInAt) ? (int) $signedInAt : null) !== null) {
            $impersonation->stop('administrator account no longer allowed');

            return null; // the account check that follows deals with the administrator
        }

        $back = 'admin/users/' . $state['target_id'];

        if ((new AccountControl())->isDeactivated($state['target_id'])) {
            $impersonation->stop('account deactivated');

            return redirect()->to($back)->with('message', 'That account was deactivated, so the view was ended.');
        }

        if ($state['expires_at'] <= time()) {
            $impersonation->stop('expired');

            return redirect()->to($back)->with('message', 'The time limit for viewing as this person has passed, so the view was ended.');
        }

        /** @var IncomingRequest $request */
        $path = trim($request->getUri()->getPath(), '/');

        if (preg_match('#(^|/)logout$#', $path) === 1) {
            $impersonation->stop('stopped');

            return redirect()->to($back)->with('message', 'You are back as yourself.');
        }

        if (in_array(strtoupper($request->getMethod()), self::SAFE, true) || preg_match('#(^|/)impersonation/stop$#', $path) === 1) {
            return null;
        }

        $message = 'You are viewing as another person, which is view-only. Nothing can be changed. Exit the view to make changes as yourself.';

        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')->setStatusCode(403)->setJSON(['error' => 'impersonation_view_only', 'message' => $message]);
        }

        return redirect()->back()->with('error', $message);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
