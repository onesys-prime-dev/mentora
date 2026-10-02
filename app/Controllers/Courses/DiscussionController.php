<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\DiscussionService;
use CodeIgniter\Exceptions\PageNotFoundException;

class DiscussionController extends BaseController
{
    protected DiscussionService $discussionService;

    public function __construct()
    {
        $this->discussionService = new DiscussionService();
    }

    public function show(int $offeringId)
    {
        return $this->response->setJSON(
            $this->discussionService->show(auth()->user(), $offeringId, $this->filtersFromRequest())
        );
    }

    /** Next page of thread rows for infinite scroll — rows only, not the whole screen. */
    public function loadMore(int $offeringId)
    {
        return $this->response->setJSON(
            $this->discussionService->loadMore(auth()->user(), $offeringId, $this->filtersFromRequest())
        );
    }

    public function thread(int $offeringId, int $threadId)
    {
        return $this->response->setJSON(
            $this->discussionService->showThread(auth()->user(), $offeringId, $threadId)
        );
    }

    public function store(int $offeringId)
    {
        return $this->response->setJSON(
            $this->discussionService->createThread(auth()->user(), $offeringId, $this->request->getJSON(true) ?? [])
        );
    }

    public function update(int $offeringId, int $threadId)
    {
        return $this->response->setJSON(
            $this->discussionService->updateThread(auth()->user(), $offeringId, $threadId, $this->request->getJSON(true) ?? [])
        );
    }

    public function destroy(int $offeringId, int $threadId)
    {
        return $this->response->setJSON(
            $this->discussionService->deleteThread(auth()->user(), $offeringId, $threadId)
        );
    }

    public function pin(int $offeringId, int $threadId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->discussionService->setPinned(auth()->user(), $offeringId, $threadId, !empty($data['value']))
        );
    }

    public function lock(int $offeringId, int $threadId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->discussionService->setLocked(auth()->user(), $offeringId, $threadId, !empty($data['value']))
        );
    }

    public function reply(int $offeringId, int $threadId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->discussionService->reply(
                auth()->user(),
                $offeringId,
                $threadId,
                (string) ($data['body'] ?? ''),
                (array) ($data['attachment_file_ids'] ?? [])
            )
        );
    }

    public function deleteReply(int $offeringId, int $replyId)
    {
        return $this->response->setJSON(
            $this->discussionService->deleteReply(auth()->user(), $offeringId, $replyId)
        );
    }

    public function react(int $offeringId, int $threadId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->discussionService->react(
                auth()->user(),
                $offeringId,
                $threadId,
                isset($data['reply_id']) ? (int) $data['reply_id'] : null,
                (string) ($data['emoji'] ?? '')
            )
        );
    }

    public function markAnswer(int $offeringId, int $threadId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->discussionService->markAnswer(auth()->user(), $offeringId, $threadId, (int) ($data['reply_id'] ?? 0))
        );
    }

    public function uploadAttachment(int $offeringId)
    {
        $file = $this->request->getFile('file');

        if (!$file) {
            return $this->response->setJSON(['success' => false, 'message' => 'No file was sent.']);
        }

        return $this->response->setJSON(
            $this->discussionService->uploadAttachment(auth()->user(), $offeringId, $file)
        );
    }

    private function filtersFromRequest(): array
    {
        return [
            'search' => (string) $this->request->getGet('search'),
            'sort' => (string) $this->request->getGet('sort'),
            'filter' => (string) $this->request->getGet('filter'),
            'page' => (int) ($this->request->getGet('page') ?? 1),
        ];
    }
}