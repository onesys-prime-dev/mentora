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
                $offeringId,
                [
                    'module' => $this->request->getGet('module'),
                    'unit' => $this->request->getGet('unit'),
                    'search' => $this->request->getGet('search'),
                    'sort' => $this->request->getGet('sort'),
                    'type' => $this->request->getGet('type'),
                    'view' => $this->request->getGet('view'),
                ]
            )
        );
    }
}