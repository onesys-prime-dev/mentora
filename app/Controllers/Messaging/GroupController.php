<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\GroupService;
use App\Services\Messaging\RateLimitedException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Groups: find the people the creator may add, start the group and run it
 * (members, rename, close, leave). No decisions here; GroupService checks
 * every member and every change, and anyone it refuses (including everyone who
 * may not start or change a group) gets the shared 404.
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

    /** The settings dialog's data: who is in the group and whether this person may change it. */
    public function members(int $id): ResponseInterface
    {
        $group = (new GroupService())->members(auth()->user(), $id);

        return $group === null ? $this->notFound() : $this->response->setJSON($group);
    }

    /** Anything GroupService refuses, a group that is not theirs included, is the one shared 404. */
    public function add(int $id): ResponseInterface
    {
        $ids = $this->input()['member_ids'] ?? null;

        if (! is_array($ids) || $ids === [] || ! array_is_list($ids) || array_filter($ids, static fn ($v): bool => ! is_int($v) && ! (is_string($v) && ctype_digit($v))) !== []) {
            return $this->notFound();
        }

        $result = (new GroupService())->add(auth()->user(), $id, $ids);

        // Nobody added and someone refused: indistinguishable from a group that is not theirs.
        return $result['added'] === [] && $result['refused'] !== [] ? $this->notFound() : $this->response->setJSON($result);
    }

    public function removeMember(int $id, int $memberId): ResponseInterface
    {
        return $this->done((new GroupService())->remove(auth()->user(), $id, $memberId));
    }

    public function leave(int $id): ResponseInterface
    {
        return $this->done((new GroupService())->leave(auth()->user(), $id));
    }

    public function rename(int $id): ResponseInterface
    {
        $title = $this->input()['title'] ?? null;

        return $this->done(is_string($title) && (new GroupService())->rename(auth()->user(), $id, $title));
    }

    public function close(int $id): ResponseInterface
    {
        return $this->done((new GroupService())->close(auth()->user(), $id));
    }

    private function done(bool $ok): ResponseInterface
    {
        return $ok ? $this->response->setJSON(['ok' => true]) : $this->notFound();
    }
}
