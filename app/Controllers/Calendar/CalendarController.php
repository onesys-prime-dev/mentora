<?php

namespace App\Controllers\Calendar;

use App\Controllers\BaseController;
use App\Services\Calendar\CalendarEventService;
use App\Models\Assessments\AssessmentTypeModel;

class CalendarController extends BaseController
{
    protected CalendarEventService $calendarEventService;

    protected AssessmentTypeModel $assessmentTypeModel;

    public function __construct()
    {
        $this->calendarEventService = new CalendarEventService();
        $this->assessmentTypeModel = new AssessmentTypeModel();
    }

    /**
     * The Calendar page itself. Renders month view for the current month;
     * switching view or navigating months is client-side, backed by
     * events() below.
     */
    public function index(): string
    {
        $user = auth()->user();

        $today = date('Y-m-d');

        $from = date('Y-m-01', strtotime($today));

        $to = date('Y-m-t', strtotime($today));

        $data = $this->calendarEventService->forRange($user, $from . ' 00:00:00', $to . ' 23:59:59');

        return view('pages/calendar/index', [
            'title'          => 'Calendar',
            'page'           => 'calendar',
            'user'           => $user,
            'today'          => $today,
            'events'         => $data['events'],
            'courses'        => $data['courses'],
            'assessmentTypes' => $this->assessmentTypeModel->where('status', 'Active')->orderBy('name')->findAll(),
            'previousPage'   => 'dashboard',
        ]);
    }

    /**
     * JSON events for a date range — what month/day/year navigation on the
     * client re-fetches instead of reloading the page.
     */
    public function events()
    {
        $user = auth()->user();

        $from = (string) $this->request->getGet('from');

        $to = (string) $this->request->getGet('to');

        if (! strtotime($from) || ! strtotime($to)) {
            return $this->response->setJSON(['success' => false, 'message' => 'Invalid date range.'])->setStatusCode(400);
        }

        $data = $this->calendarEventService->forRange($user, $from, $to);

        return $this->response->setJSON(['success' => true] + $data);
    }
}
