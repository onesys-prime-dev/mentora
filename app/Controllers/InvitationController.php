<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Institutions\InvitationService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * The page an invited person reaches from the emailed link: choose a password
 * and the account is made. Public on purpose (the person has no account yet);
 * the secret in the link is what proves it is them, and it works once.
 */
class InvitationController extends BaseController
{
    private InvitationService $invitations;

    public function __construct()
    {
        $this->invitations = new InvitationService();
    }

    public function show(string $token): string
    {
        try {
            $invitation = $this->invitations->open($token);
        } catch (InvalidArgumentException $e) {
            return $this->problem($e->getMessage());
        }

        return view('pages/auth/invitation', ['title' => 'Set your password', 'page' => 'invitation', 'inviteToken' => $token, 'invitation' => $invitation]);
    }

    /** @return RedirectResponse|string */
    public function accept(string $token)
    {
        $password = (string) $this->request->getPost('password');
        $back     = redirect()->to('invitation/' . $token);

        if ($password !== (string) $this->request->getPost('password_confirm')) {
            return $back->with('error', 'The two passwords are not the same.');
        }

        try {
            $this->invitations->accept($token, $password);
        } catch (InvalidArgumentException $e) {
            // A password that is not accepted leaves the link usable; any other
            // refusal means the link itself no longer works.
            try {
                $this->invitations->open($token);
            } catch (InvalidArgumentException $linkProblem) {
                return $this->problem($linkProblem->getMessage());
            }

            return $back->with('error', $e->getMessage());
        }

        return redirect()->to('login')->with('message', 'Your password is set and your account is ready. Sign in with your email address and the new password.');
    }

    private function problem(string $reason): string
    {
        // An unknown link is "not found"; one that existed but is spent, replaced or expired is "gone".
        $this->response->setStatusCode($reason === InvitationService::LINK_INVALID ? 404 : 410);

        return view('pages/auth/invitation-problem', ['title' => 'Invitation', 'page' => 'invitation', 'inviteProblem' => $reason]);
    }
}
