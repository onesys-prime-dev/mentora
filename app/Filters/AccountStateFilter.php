<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Users\AccountControl;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Runs before every request (Config\Filters, globals).
 *
 * If the person signed in has had their account deactivated, or an
 * administrator has ended their sessions since this one began, they are signed
 * out and sent to the login page with the reason, whatever page they asked for.
 * It lets anonymous visitors, and the sign-in and sign-out pages, through.
 *
 * It costs one primary-key lookup per signed-in request.
 */
class AccountStateFilter implements FilterInterface
{
    /** The session key set when sign-in completes (see AuditAuthListener). */
    public const SIGNED_IN_AT = 'signed_in_at';

    public function before(RequestInterface $request, $arguments = null)
    {
        // While an administrator views as someone the session is that person's, but it is the
        // administrator who is signed in: ImpersonationFilter checks their account instead.
        if (session('impersonator_id') !== null) {
            return null;
        }

        if (! auth()->loggedIn()) {
            return null;
        }

        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $signedInAt = session(self::SIGNED_IN_AT);
        $reason     = (new AccountControl())->blockReason((int) $user->id, is_numeric($signedInAt) ? (int) $signedInAt : null);

        if ($reason === null) {
            return null;
        }

        auth()->logout();

        $message = $reason === AccountControl::REASON_DEACTIVATED
            ? 'This account has been deactivated. Contact your administrator.'
            : 'You were signed out by an administrator. Please sign in again.';

        /** @var IncomingRequest $request */
        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')->setStatusCode(401)->setJSON(['error' => $reason, 'message' => $message]);
        }

        return redirect()->to('/login')->with('error', $message);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
