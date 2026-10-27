<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Admin\ReauthService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The "confirm your password" step in front of dangerous actions, for every
 * administrative area. See ReauthFilter and ReauthService.
 *
 * The system administrator uses admin/reauth (and lands back in the admin
 * area); everyone else uses reauth. A script (the confirm dialog on a page)
 * posts the same form and gets JSON back.
 */
class ReauthController extends BaseController
{
    public function show(): string
    {
        $user = auth()->user();

        return view('pages/reauth', [
            'title'       => 'Confirm your password',
            'page'        => $this->isAdmin() ? 'admin' : 'institution',
            'pageTitle'   => 'Confirm your password',
            'user'        => $user,
            // The page is the confirmation: it does not also carry the dialog for it.
            'reauthDialog' => false,
            'reauthAdmin' => $this->isAdmin(),
            'reauthPost'  => site_url($this->path()),
        ]);
    }

    public function confirm(): ResponseInterface|RedirectResponse
    {
        $reauth = new ReauthService();
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');

        if ($reauth->tooManyFailures()) {
            return $this->endSession($wantsJson);
        }

        $password = (string) $this->request->getPost('password');

        if ($password !== '' && $reauth->confirm(auth()->user(), $password, time())) {
            return $wantsJson
                ? $this->response->setJSON(['ok' => true])
                : redirect()->to($reauth->pullReturn($this->home()));
        }

        if ($reauth->tooManyFailures()) {
            return $this->endSession($wantsJson);
        }

        if ($wantsJson) {
            return $this->response->setStatusCode(422)->setJSON(['errors' => ['password' => 'That password is not correct.']]);
        }

        return redirect()->to($this->path())->with('error', 'That password is not correct.');
    }

    /** Too many wrong passwords on an open session: end it. */
    private function endSession(bool $wantsJson): ResponseInterface|RedirectResponse
    {
        auth()->logout();

        if ($wantsJson) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'signed_out', 'message' => 'Too many incorrect passwords. Please sign in again.']);
        }

        return redirect()->to('/login')->with('error', 'Too many incorrect passwords. Please sign in again.');
    }

    private function isAdmin(): bool
    {
        return (bool) auth()->user()?->inGroup('admin');
    }

    /** The confirm page's own address for this person. */
    private function path(): string
    {
        return $this->isAdmin() ? 'admin/reauth' : 'reauth';
    }

    /** Where someone with nothing pending lands after confirming. */
    private function home(): string
    {
        if ($this->isAdmin()) {
            return 'admin/dashboard';
        }

        // A dean confirms their password for sign-off; the institution's administrator for their own actions.
        $user = auth()->user();

        return $user->inGroup('dean') && ! $user->inGroup('institutional_admin') ? 'dean/dashboard' : 'institution/dashboard';
    }
}
