<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Controllers\BaseController;
use App\Services\Messaging\MessagingContextResolver;

/**
 * The Messages page: an empty shell that resources/js/pages/messages fills from
 * the JSON routes. It carries no message text. The id in the address only says
 * which thread to open first; the API decides whether it is the person's.
 */
class MessagesPageController extends BaseController
{
    public function index(?string $conversationId = null): string
    {
        $resolver = new MessagingContextResolver();
        $roles    = $resolver->roles(auth()->user());

        return view('pages/messages/index', [
            'title'  => 'Messages',
            'page'   => 'messages',
            // The page itself stays put; only the list and the thread scroll.
            'fullHeight' => true,
            'user'   => auth()->user(),
            'openId' => $conversationId !== null && ctype_digit($conversationId) ? $conversationId : '',
            // Only the button: GroupService is what decides who may start a group.
            'canReview' => in_array('institutional_admin', $roles, true),
            // Staff and students (FR7); a parent-only person gets no button.
            'canStartGroups' => $resolver->party(auth()->user())->capacity(false) !== null,
        ]);
    }
}
