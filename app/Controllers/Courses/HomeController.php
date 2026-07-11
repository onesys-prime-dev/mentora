<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\HomeService;
use CodeIgniter\Exceptions\PageNotFoundException;

class HomeController extends BaseController
{
    protected HomeService $homeService;

    public function __construct()
    {
        $this->homeService = new HomeService();
    }

    public function show(int $offeringId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->homeService->show(
                $user,
                $offeringId
            )
        );
    }
}