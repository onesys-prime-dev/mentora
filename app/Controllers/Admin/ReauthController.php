<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Admin\ReauthService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The "confirm your password" step in front of dangerous admin actions.
 * See ReauthFilter and ReauthService.
 */
class ReauthController extends BaseController
{
    public function show(): string
    {
        return view('pages/admin/reauth', [
            'title' => 'Confirm your password',
            'page'  => 'admin',
            'user'  => auth()->user(),
        ]);
    }

    public function confirm(): RedirectResponse
    {
        $reauth = new ReauthService();

        if ($reauth->tooManyFailures()) {
            return $this->endSession();
        }

        $password = (string) $this->request->getPost('password');

        if ($password !== '' && $reauth->confirm(auth()->user(), $password, time())) {
            return redirect()->to($reauth->pullReturn());
        }

        if ($reauth->tooManyFailures()) {
            return $this->endSession();
        }

        return redirect()->to('admin/reauth')->with('error', 'That password is not correct.');
    }

    /** Too many wrong passwords on an open session: end it. */
    private function endSession(): RedirectResponse
    {
        auth()->logout();

        return redirect()->to('/login')->with('error', 'Too many incorrect passwords. Please sign in again.');
    }
}
