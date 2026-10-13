<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Users\PasswordResetService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * The page behind a password reset link: choose a new password. Public on
 * purpose (the person is locked out, which is why they asked); the secret in
 * the link is the proof, and it works once.
 */
class PasswordResetController extends BaseController
{
    private PasswordResetService $resets;

    public function __construct()
    {
        $this->resets = new PasswordResetService();
    }

    public function show(string $token): string
    {
        try {
            $reset = $this->resets->open($token);
        } catch (InvalidArgumentException $e) {
            return $this->problem($e->getMessage());
        }

        return view('pages/auth/password-reset', ['title' => 'Choose a new password', 'page' => 'password-reset', 'resetToken' => $token, 'reset' => $reset]);
    }

    /** @return RedirectResponse|string */
    public function complete(string $token)
    {
        $password = (string) $this->request->getPost('password');
        $back     = redirect()->to('reset-password/' . $token);

        if ($password !== (string) $this->request->getPost('password_confirm')) {
            return $back->with('error', 'The two passwords are not the same.');
        }

        try {
            $this->resets->complete($token, $password);
        } catch (InvalidArgumentException $e) {
            // A password that is not accepted leaves the link usable; any other
            // refusal means the link itself no longer works.
            try {
                $this->resets->open($token);
            } catch (InvalidArgumentException $linkProblem) {
                return $this->problem($linkProblem->getMessage());
            }

            return $back->with('error', $e->getMessage());
        }

        return redirect()->to('login')->with('message', 'Your new password is set. Sign in with your email address and the new password.');
    }

    private function problem(string $reason): string
    {
        $this->response->setStatusCode($reason === PasswordResetService::LINK_INVALID ? 404 : 410);

        return view('pages/auth/invitation-problem', ['title' => 'Password reset', 'page' => 'password-reset', 'inviteProblem' => $reason]);
    }
}
