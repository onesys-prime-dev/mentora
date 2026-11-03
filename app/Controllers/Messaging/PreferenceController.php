<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\MessagePreferences;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The person's own Messages preferences: email alerts for everyone, quiet hours
 * for the roles that are offered them. A value that is not acceptable is 422.
 */
class PreferenceController extends MessagingController
{
    public function show(): ResponseInterface
    {
        return $this->response->setJSON($this->payload());
    }

    public function save(): ResponseInterface
    {
        $input = $this->input();
        $start = $input['quiet_start'] ?? null;
        $end   = $input['quiet_end'] ?? null;
        $email = $input['email_alerts'] ?? true;

        if ((! is_string($start) && $start !== null) || (! is_string($end) && $end !== null) || ! is_bool($email)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'invalid']);
        }

        if (! (new MessagePreferences())->save(auth()->user(), $start, $end, $email)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'invalid']);
        }

        return $this->response->setJSON($this->payload());
    }

    /** @return array{preferences: array<string, mixed>, quiet_hours_allowed: bool} */
    private function payload(): array
    {
        $prefs = new MessagePreferences();
        $user  = auth()->user();

        return ['preferences' => $prefs->get((int) $user->id), 'quiet_hours_allowed' => $prefs->quietHoursAllowed($user)];
    }
}
