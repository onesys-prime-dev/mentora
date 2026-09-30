<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\AnnouncementService;
use CodeIgniter\Exceptions\PageNotFoundException;

class AnnouncementController extends BaseController
{
    protected AnnouncementService $announcementService;

    public function __construct()
    {
        $this->announcementService = new AnnouncementService();
    }

    public function show(int $offeringId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->announcementService->show(
                $user,
                $offeringId
            )
        );
    }

    /** Posting from the Calendar's date-click menu (instructor only). */
    public function store()
    {
        $user = auth()->user();

        $data = $this->request->getJSON(true) ?? [];

        $result = $this->announcementService->create($user, $data);

        return $this->response
            ->setJSON($result)
            ->setStatusCode($result['success'] ? 201 : 422);
    }
}