<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Controllers\BaseController;
use App\Services\Auth\ActiveRoleService;

/**
 * The Messages page: an empty shell that resources/js/pages/messages fills from
 * the JSON routes. It carries no message text. The id in the address only says
 * which thread to open first; the API decides whether it is the person's.
 */
class MessagesPageController extends BaseController
{
    public function index(?string $conversationId = null): string
    {
        return view('pages/messages/index', [
            'title'  => 'Messages',
            'page'   => 'messages',
            'user'   => auth()->user(),
            'openId' => $conversationId !== null && ctype_digit($conversationId) ? $conversationId : '',
            // Only the button: GroupService is what decides who may start a group.
            'canReview' => (new ActiveRoleService())->resolve(auth()->user()) === 'institutional_admin',
            'canStartGroups' => in_array((new ActiveRoleService())->resolve(auth()->user()), ['chairperson', 'dean'], true),
        ]);
    }
}
