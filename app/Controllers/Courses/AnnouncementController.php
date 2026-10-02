<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\AnnouncementService;

class AnnouncementController extends BaseController
{
    protected AnnouncementService $announcementService;

    public function __construct()
    {
        $this->announcementService = new AnnouncementService();
    }

    public function show(int $offeringId)
    {
        return $this->response->setJSON(
            $this->announcementService->show(auth()->user(), $offeringId, $this->filtersFromRequest())
        );
    }

    public function loadMore(int $offeringId)
    {
        return $this->response->setJSON(
            $this->announcementService->loadMore(auth()->user(), $offeringId, $this->filtersFromRequest())
        );
    }

    private function filtersFromRequest(): array
    {
        return [
            'search' => (string) $this->request->getGet('search'),
            'sort' => (string) $this->request->getGet('sort'),
            'pinned' => $this->request->getGet('pinned') === '1',
            'page' => (int) ($this->request->getGet('page') ?? 1),
            'focus' => (int) ($this->request->getGet('focus') ?? 0),
        ];
    }

    public function store(int $offeringId)
    {
        return $this->response->setJSON(
            $this->announcementService->create(auth()->user(), $offeringId, $this->request->getJSON(true) ?? [])
        );
    }

    /** Posting from the Calendar's date-click menu: plain title/body, offering chosen in the form. */
    public function storeFromCalendar()
    {
        helper('announcement');

        $data = $this->request->getJSON(true) ?? [];

        $result = $this->announcementService->create(auth()->user(), (int) ($data['offering_id'] ?? 0), [
            'subject' => $data['title'] ?? '',
            'message' => announcement_message_from_text((string) ($data['body'] ?? '')),
        ]);

        return $this->response
            ->setJSON($result)
            ->setStatusCode($result['success'] ? 201 : 422);
    }

    public function update(int $offeringId, int $announcementId)
    {
        return $this->response->setJSON(
            $this->announcementService->update(auth()->user(), $offeringId, $announcementId, $this->request->getJSON(true) ?? [])
        );
    }

    public function destroy(int $offeringId, int $announcementId)
    {
        return $this->response->setJSON(
            $this->announcementService->delete(auth()->user(), $offeringId, $announcementId)
        );
    }

    public function togglePin(int $offeringId, int $announcementId)
    {
        return $this->response->setJSON(
            $this->announcementService->togglePin(auth()->user(), $offeringId, $announcementId)
        );
    }

    public function uploadAttachment(int $offeringId)
    {
        $file = $this->request->getFile('file');

        if (!$file) {
            return $this->response->setJSON(['success' => false, 'message' => 'No file was sent.']);
        }

        return $this->response->setJSON(
            $this->announcementService->uploadAttachment(auth()->user(), $offeringId, $file)
        );
    }

    public function reply(int $offeringId, int $announcementId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->announcementService->reply(auth()->user(), $offeringId, $announcementId, (string) ($data['message'] ?? ''))
        );
    }

    public function deleteReply(int $offeringId, int $replyId)
    {
        return $this->response->setJSON(
            $this->announcementService->deleteReply(auth()->user(), $offeringId, $replyId)
        );
    }

    public function react(int $offeringId, int $announcementId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->announcementService->react(auth()->user(), $offeringId, $announcementId, (string) ($data['emoji'] ?? ''))
        );
    }
}
