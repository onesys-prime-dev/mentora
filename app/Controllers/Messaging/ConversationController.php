<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\ConversationService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The person's conversations: list them, start a direct one, and the unread
 * count behind the badge. No decisions here; ConversationService asks the policy.
 */
class ConversationController extends MessagingController
{
    public function index(): ResponseInterface
    {
        $service = new ConversationService();
        $user    = auth()->user();
        $filter  = (string) $this->request->getGet('filter');

        return $this->response->setJSON([
            'conversations' => $service->listFor($user, in_array($filter, ['course', 'direct', 'group'], true) ? $filter : 'all', (string) $this->request->getGet('unread') === '1'),
            'unread'        => $service->unreadCount($user),
        ]);
    }

    public function start(): ResponseInterface
    {
        $input     = $this->input();
        $recipient = $input['recipient_id'] ?? null;
        $subject   = $input['subject_student_id'] ?? null;

        $conversation = is_numeric($recipient)
            ? (new ConversationService())->startDirect(auth()->user(), (int) $recipient, is_numeric($subject) ? (int) $subject : null)
            : null;

        if ($conversation === null) {
            return $this->notFound();
        }

        return $this->response->setJSON(['conversation' => $conversation]);
    }

    public function unread(): ResponseInterface
    {
        return $this->response->setJSON(['unread' => (new ConversationService())->unreadCount(auth()->user())]);
    }
}
