<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Admin\PlatformSettings;
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

    /** The session key holding the time of the person's last request. */
    public const LAST_SEEN_AT = 'last_seen_at';

    public const REASON_IDLE = 'idle';

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

        if ($reason === null && self::idleFor() > (int) (new \App\Services\Institutions\InstitutionSecurity())->forUser($user)['sessionTimeoutMinutes'] * 60) {
            $reason = self::REASON_IDLE;
        }

        if ($reason === null) {
            session()->set(self::LAST_SEEN_AT, time());

            return null;
        }

        auth()->logout();

        $message = match ($reason) {
            AccountControl::REASON_DEACTIVATED => 'This account has been deactivated. Contact your administrator.',
            self::REASON_IDLE                  => 'You were signed out because you were inactive for a while. Please sign in again.',
            default                            => 'You were signed out by an administrator. Please sign in again.',
        };

        /** @var IncomingRequest $request */
        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')->setStatusCode(401)->setJSON(['error' => $reason, 'message' => $message]);
        }

        return redirect()->to('/login')->with('error', $message);
    }

    /** Seconds since the person's last request, or 0 when this session has not recorded one. */
    private static function idleFor(): int
    {
        $seen = session(self::LAST_SEEN_AT);

        return is_numeric($seen) ? max(0, time() - (int) $seen) : 0;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
