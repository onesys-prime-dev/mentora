<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\ResourceService;
use CodeIgniter\Exceptions\PageNotFoundException;

class ResourceController extends BaseController
{
    protected ResourceService $resourceService;

    public function __construct()
    {
        $this->resourceService = new ResourceService();
    }

    public function show(int $offeringId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->resourceService->show(
                $user,
                $offeringId
            )
        );
    }
}