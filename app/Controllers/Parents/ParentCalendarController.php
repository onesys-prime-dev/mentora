<?php

declare(strict_types=1);

namespace App\Controllers\Parents;

use App\Controllers\BaseController;
use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentCalendarEvents;
use App\Services\Parents\ParentPortal;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A child's calendar, for the parent: the page, its JSON, and the menu entry
 * that opens the first child's. Read-only, and nothing private to the child
 * (their reminders) is ever in it. The route filter has already answered 404
 * for a child the parent is not linked to; a switched-off portal shows the
 * portal-off page and gives the JSON nothing.
 */
class ParentCalendarController extends BaseController
{
    /** The menu entry: the first child's calendar, or the explanation when there is no child. */
    public function index(): string|RedirectResponse
    {
        if (! $this->portalOn()) {
            return view('pages/parent/portal-off', $this->shell());
        }

        $children = (new ParentAccessService())->children(auth()->user());

        if ($children === []) {
            return view('pages/parent/not-linked', $this->shell());
        }

        return redirect()->to(site_url('parent/children/' . $children[0]['student_id'] . '/calendar'));
    }

    public function page(int $studentId): string
    {
        if (! $this->portalOn()) {
            return view('pages/parent/portal-off', $this->shell());
        }

        $child = $this->child($studentId);
        $name  = $this->nameOf($studentId);
        $today = date('Y-m-d');

        $data = (new ParentCalendarEvents())->forRange($child, date('Y-m-01', strtotime($today)) . ' 00:00:00', date('Y-m-t', strtotime($today)) . ' 23:59:59');

        return view('pages/calendar/index', [
            'title'                  => 'Calendar',
            'page'                   => 'calendar',
            'user'                   => auth()->user(),
            'today'                  => $today,
            'events'                 => $data['events'],
            'courses'                => [],
            'assessmentTypes'        => [],
            'previousPage'           => 'dashboard',
            'calendarRole'           => 'parent',
            'calendarApiBase'        => site_url('parent/children/' . $studentId . '/api/calendar'),
            'calendarCoursesApiBase' => '',
            'calendarHeading'        => $name . '\'s calendar',
            'calendarBackUrl'        => site_url('parent/children/' . $studentId),
        ]);
    }

    public function events(int $studentId): ResponseInterface
    {
        if (! $this->portalOn()) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => 'The parent portal is switched off.']);
        }

        $from = (string) $this->request->getGet('from');
        $to   = (string) $this->request->getGet('to');

        if (! strtotime($from) || ! strtotime($to)) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'Invalid date range.']);
        }

        $data = (new ParentCalendarEvents())->forRange($this->child($studentId), $from, $to);

        return $this->response->setJSON(['success' => true, 'events' => $data['events']]);
    }

    private function portalOn(): bool
    {
        return (new ParentPortal())->enabled((new PlanLimits())->institutionOf((int) auth()->id()));
    }

    /** @return array<string, mixed> */
    private function shell(): array
    {
        return ['title' => 'Calendar', 'page' => 'calendar', 'user' => auth()->user()];
    }

    private function child(int $studentId): \CodeIgniter\Shield\Entities\User
    {
        return (new ParentAccessService())->childUser(auth()->user(), $studentId) ?? throw PageNotFoundException::forPageNotFound();
    }

    private function nameOf(int $studentId): string
    {
        $names = array_column((new ParentAccessService())->children(auth()->user()), 'name', 'student_id');

        return (string) ($names[$studentId] ?? '');
    }
}
