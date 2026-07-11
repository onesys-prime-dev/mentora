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
        $user = auth()->user();

        return $this->response->setJSON(
            $this->discussionService->show(
                $user,
                $offeringId
            )
        );
    }
}