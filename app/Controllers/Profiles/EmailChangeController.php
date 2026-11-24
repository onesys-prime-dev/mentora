<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Services\Profiles\EmailChangeService;
use App\Services\Profiles\ProfileEditException;

/**
 * The link sent to a new email address (D17). Public like reset-password: the token is the proof, and it also
 * works signed in (as its owner only). Opening the link only asks; the change happens on the POST, so a mail
 * scanner that follows links changes nothing.
 */
class EmailChangeController extends BaseController
{
    public const PROBLEM = 'This link has expired or was already used. Ask for a new correction from your profile.';

    public function show(string $token): string
    {
        $email = (new EmailChangeService())->peek($token);

        return $email === null ? $this->page('problem', self::PROBLEM, 410) : $this->page('ask', $email, 200, $token);
    }

    public function confirm(string $token): string
    {
        $service = new EmailChangeService();
        $email   = $service->peek($token);

        try {
            $done = $service->confirm($token, auth()->user()); // also on an expired link: it marks its request expired
        } catch (ProfileEditException $e) {
            return $this->page('problem', $e->getMessage(), 409);
        }

        return $done ? $this->page('done', (string) $email) : $this->page('problem', self::PROBLEM, 410);
    }

    private function page(string $state, string $text, int $status = 200, string $token = ''): string
    {
        $this->response->setStatusCode($status);

        return view('pages/auth/email-change', ['title' => 'Confirm new email', 'state' => $state, 'text' => $text, 'token' => $token, 'signedIn' => auth()->loggedIn()]);
    }
}
