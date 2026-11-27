<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Institutions\InstitutionAccess;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route option for the role areas: `['filter' => 'readonly']`.
 *
 * When the signed-in person's institution is read-only, past or deleted, any
 * request that would change something (anything but GET, HEAD and OPTIONS) is
 * refused before a controller runs. Looking and exporting are all GETs and go
 * through. A script gets a 403 with a JSON error; a browser is sent back with
 * a message.
 */
class ReadOnlyFilter implements FilterInterface
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * A person's own things stay theirs in a read-only institution (decision 8.2): their
     * reminders, and the parent's two old account forms (they now only redirect to Settings).
     * Settings, notification read state and the role switch are outside the read-only areas already.
     */
    public const OWN_ACCOUNT = [
        '#^(student|instructor|chairperson)/api/calendar/reminders(/\d+(/done)?)?$#',
        '#^parent/profile/(notifications|password-link)$#',
    ];

    /**
     * Messages safety and personal actions stay open in a read-only institution, never in a past one (FR11):
     * mute, archive, mark read or unread, report, block and unblock. Opening a person (the draft, and the profile's
     * Message button) stores nothing and reaches only a thread that exists there, since reading stays open.
     */
    public const READ_ONLY_MESSAGES = [
        '#^messages/api/conversations$#',
        '#^people/\d+/message$#',
        '#^messages/api/conversations/\d+/(mute|unmute|archive|unarchive|unread|read)$#',
        '#^messages/api/conversations/\d+/messages/\d+/report$#',
        '#^messages/api/blocks(/\d+/remove)?$#',
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE, true)) {
            return null;
        }

        /** @var IncomingRequest $request */
        $path = trim($request->getPath(), '/');

        foreach (self::OWN_ACCOUNT as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return null;
            }
        }

        $user = auth()->user();

        if ($user === null) {
            return null; // not signed in: the login filter deals with that
        }

        $state = (new InstitutionAccess())->stateFor((int) $user->id);

        if ($state === InstitutionAccess::WRITABLE) {
            return null;
        }

        if ($state === InstitutionAccess::READ_ONLY) {
            foreach (self::READ_ONLY_MESSAGES as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return null;
                }
            }
        }

        $message = $state === InstitutionAccess::READ_ONLY
            ? "Your institution's subscription has ended, so it is read-only for now. You can still look at and export your work, but nothing can be changed."
            : 'Your institution is no longer active. You can still look at and export your work, but nothing can be changed.';

        /** @var IncomingRequest $request */
        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => 'institution_read_only', 'message' => $message]);
        }

        return redirect()->back()->with('error', $message);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
