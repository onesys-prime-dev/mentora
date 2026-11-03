<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\GroupService;
use App\Services\Messaging\RateLimitedException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Staff groups: find the people a chairperson or dean may add, and start the
 * group. No decisions here; GroupService checks every member against scope, and
 * anyone it refuses (including everyone who may not start groups) gets the
 * shared 404.
 */
class GroupController extends MessagingController
{
    public function candidates(): ResponseInterface
    {
        return $this->response->setJSON([
            'candidates' => (new GroupService())->candidates(auth()->user(), (string) $this->request->getGet('q')),
        ]);
    }

    public function create(): ResponseInterface
    {
        $input = $this->input();
        $title = $input['title'] ?? null;
        $ids   = $input['member_ids'] ?? null;

        if (! is_string($title) || ! is_array($ids) || ! array_is_list($ids) || array_filter($ids, static fn ($id): bool => ! is_int($id) && ! (is_string($id) && ctype_digit($id))) !== []) {
            return $this->notFound();
        }

        $user = auth()->user();

        try {
            $group = (new GroupService())->create($user, $title, $ids);
        } catch (RateLimitedException $e) {
            return $this->rateLimited($e);
        }

        if ($group === null) {
            return $this->notFound();
        }

        // Presented like a list row, so the page can show it at once.
        foreach ((new ConversationPresenter())->present($user, (new ConversationService())->listFor($user, 'group')) as $row) {
            if ((int) $row['id'] === (int) $group['id']) {
                $group = [...$group, ...$row];
            }
        }

        return $this->response->setStatusCode(201)->setJSON(['conversation' => $group]);
    }
}
