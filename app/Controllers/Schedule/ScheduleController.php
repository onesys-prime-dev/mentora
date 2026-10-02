<?php

namespace App\Controllers\Schedule;

use App\Controllers\BaseController;
use App\Services\Courses\ScheduleService;

class ScheduleController extends BaseController
{
    protected ScheduleService $scheduleService;

    public function __construct()
    {
        $this->scheduleService = new ScheduleService();
    }

    public function index(): string
    {
        $user = auth()->user();

        return view('pages/schedule/index', [
            'title'        => 'Schedule',
            'page'         => 'schedule',
            'previousPage' => 'dashboard',
        ] + $this->scheduleService->forUser($user));
    }
}
