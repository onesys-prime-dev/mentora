<?php

namespace App\Controllers\Calendar;

use App\Controllers\BaseController;
use App\Services\Users\ReminderService;

class ReminderController extends BaseController
{
    protected ReminderService $reminderService;

    public function __construct()
    {
        $this->reminderService = new ReminderService();
    }

    public function store()
    {
        $user = auth()->user();

        $data = $this->request->getJSON(true) ?? [];

        $result = $this->reminderService->create($user, $data);

        return $this->response
            ->setJSON($result)
            ->setStatusCode($result['success'] ? 201 : 422);
    }

    public function markDone(int $reminderId)
    {
        $user = auth()->user();

        $result = $this->reminderService->markDone($user, $reminderId);

        return $this->response
            ->setJSON($result)
            ->setStatusCode($result['success'] ? 200 : 404);
    }

    public function destroy(int $reminderId)
    {
        $user = auth()->user();

        $result = $this->reminderService->delete($user, $reminderId);

        return $this->response
            ->setJSON($result)
            ->setStatusCode($result['success'] ? 200 : 404);
    }
}
