<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Admin\Impersonation;
use App\Services\Auth\ActiveRoleService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Applied as `messages` on every Messages route. For anyone it must not serve,
 * Messages simply does not exist (404, the same body everywhere):
 *  - while the `messages` flag is off for the person's institution;
 *  - for the system administrator, who has no access to Messages at all;
 *  - during an impersonation session, even for a GET, since reading a private
 *    thread as someone else is exactly what must not happen.
 * Someone not signed in is sent to the login page first.
 */
class MessagesFilter implements FilterInterface
{
    public const NOT_FOUND = ['error' => 'not_found'];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            return redirect()->to('/login');
        }

        if (self::closed()) {
            return service('response')->setStatusCode(404)->setJSON(self::NOT_FOUND);
        }

        return null;
    }

    /** Messages does not exist for the signed-in person right now (also asked by the profile's Message button). */
    public static function closed(): bool
    {
        helper('feature');

        return ! feature_enabled('messages') || Impersonation::active() || (new ActiveRoleService())->resolve(auth()->user()) === 'admin';
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
