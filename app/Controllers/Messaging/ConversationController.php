<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\RateLimitedException;
use App\Services\Messaging\RestrictionService;
use App\Services\Profiles\AvatarData;
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

        $rows = $service->listFor($user, in_array($filter, ['course', 'direct', 'group'], true) ? $filter : 'all', (string) $this->request->getGet('unread') === '1', (string) $this->request->getGet('archived') === '1');

        // Only the viewer's own restriction, never who set it or why beyond its source.
        $active = (new RestrictionService())->active((int) $user->id);

        return $this->response->setJSON([
            'restriction'   => $active === null ? null : ['kind' => $active['kind'], 'ends_at' => $active['ends_at'] === null ? null : (new \DateTimeImmutable($active['ends_at']))->format(DATE_ATOM), 'source' => $active['source']],
            'conversations' => (new ConversationPresenter())->present($user, $rows),
            'unread'        => $service->unreadCount($user),
            'archived_count' => $service->archivedCount($user),
        ]);
    }

    /** Opening a person: the draft, nothing stored until the first message is sent. */
    public function start(): ResponseInterface
    {
        $input   = $this->input();
        $subject = $input['subject_student_id'] ?? null;
        $draft   = is_numeric($input['recipient_id'] ?? null)
            ? (new ConversationService())->draftFor(auth()->user(), (int) $input['recipient_id'], is_numeric($subject) ? (int) $subject : null)
            : null;

        if ($draft === null) {
            return $this->notFound();
        }

        // The header needs names before any thread exists: the same gated names and avatar the list uses. The child a
        // thread is about was already checked by draftFor.
        $recipient = (int) $draft['recipient_id'];
        $child     = $draft['subject_student_id'] === null ? null : (int) $draft['subject_student_id'];
        $people    = (new AvatarData())->forViewerJson(auth()->user(), array_values(array_filter([$recipient, $child])));
        $avatar    = $people[$recipient] ?? null;

        return $this->response->setJSON(['draft' => $draft + [
            'name'         => $avatar['name'] ?? 'Someone',
            'avatar'       => $avatar,
            'subject_name' => $child === null ? null : ($people[$child]['name'] ?? null),
        ]]);
    }

    /** The first message: creates the conversation and the message together. */
    public function sendFirst(): ResponseInterface
    {
        $input   = $this->input();
        $subject = $input['subject_student_id'] ?? null;
        $user    = auth()->user();

        if (! is_numeric($input['recipient_id'] ?? null)) {
            return $this->notFound();
        }

        try {
            $result = (new MessageService())->sendFirst($user, (int) $input['recipient_id'], is_numeric($subject) ? (int) $subject : null, (string) ($input['body'] ?? ''), (string) ($input['client_uuid'] ?? ''));
        } catch (RateLimitedException $e) {
            return $this->rateLimited($e);
        }

        if (! $result['ok']) {
            $status = ['invalid_body' => 422, 'not_allowed' => 403, 'unavailable' => 503][$result['reason'] ?? ''] ?? null;

            return $status === null ? $this->notFound() : $this->response->setStatusCode($status)->setJSON(['error' => $result['reason']]);
        }

        // Presented like a list row, so the page can show it at once.
        $conversation = ['id' => $result['conversation_id']];

        $mine = array_values(array_filter((new ConversationService())->listFor($user), static fn (array $r): bool => (int) $r['id'] === $result['conversation_id']));

        if ($mine !== []) {
            $conversation = (new ConversationPresenter())->present($user, $mine)[0];
        }

        return $this->response->setStatusCode(201)->setJSON(['message' => $result['message'], 'conversation' => $conversation]);
    }

    public function lock(int $conversationId): ResponseInterface
    {
        return $this->setLocked($conversationId, true);
    }

    public function unlock(int $conversationId): ResponseInterface
    {
        return $this->setLocked($conversationId, false);
    }

    private function setLocked(int $conversationId, bool $locked): ResponseInterface
    {
        $result = (new ConversationService())->setLocked(auth()->user(), $conversationId, $locked);

        return $result === null ? $this->notFound() : $this->response->setJSON(['conversation' => $result]);
    }

    public function unread(): ResponseInterface
    {
        return $this->response->setJSON(['unread' => (new ConversationService())->unreadCount(auth()->user())]);
    }
}
