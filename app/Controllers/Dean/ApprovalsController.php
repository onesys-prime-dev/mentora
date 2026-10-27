<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\DeanCourseSearch;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\OverrideApprovalFilters;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The dean's Approvals: grade-override requests of their department that the
 * chairperson endorsed (or that skipped the chairperson), to approve or reject,
 * and the ones already decided. Approving changes the grade through the grading
 * engine; rejecting changes nothing. The decisions answer JSON for the dialog
 * that sends them, and redirect with a message without JavaScript. Who may
 * decide which request is the service's check.
 */
class ApprovalsController extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();

        if ((new DeanAccessService())->departmentFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Approvals', 'page' => 'approvals', 'user' => $user]);
        }

        $service = new GradeOverrideRequestService();
        $filters = OverrideApprovalFilters::fromInput($this->request->getGet());

        $loadError  = false;
        $requests   = [];
        $programs   = [];
        $requesters = [];
        $pending    = 0;

        try {
            $requests   = $service->listForDean($user, $filters);
            $programs   = (new DeanCourseSearch())->programs($user);
            $requesters = $service->requestersForDean($user);
            $pending    = $filters->view === 'pending' && ! $filters->isFiltered() ? count($requests) : $service->pendingCountForDean($user);

            // What a decision is judged by: how the student's grade is made up, for what still waits.
            if ($filters->view === 'pending') {
                foreach ($requests as &$row) {
                    $row['breakdown'] = $service->breakdown((int) $row['offering_id'], (int) $row['student_id']);
                }
                unset($row);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Dean approvals failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/dean/approvals', [
            'title'        => 'Approvals',
            'area'         => 'dean',
            'page'         => 'approvals',
            'user'         => $user,
            'filters'      => $filters,
            'requests'     => $requests,
            'programs'     => $programs,
            'requesters'   => $requesters,
            'pending'      => $pending,
            'loadError'    => $loadError,
            'previousPage' => 'dashboard',
        ]);
    }

    public function approve(int $requestId): ResponseInterface|RedirectResponse
    {
        return $this->answer((new GradeOverrideRequestService())->approve(auth()->user(), $requestId, (string) $this->request->getPost('comment')));
    }

    public function reject(int $requestId): ResponseInterface|RedirectResponse
    {
        return $this->answer((new GradeOverrideRequestService())->reject(auth()->user(), $requestId, (string) $this->request->getPost('reason')), 'reason');
    }

    /**
     * @param array{success: bool, message: string} $result
     */
    private function answer(array $result, string $field = 'comment'): ResponseInterface|RedirectResponse
    {
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back      = site_url('dean/approvals');

        if (! $result['success']) {
            return $wantsJson
                ? $this->response->setStatusCode(422)->setJSON(['errors' => [$field => $result['message']], 'message' => $result['message']])
                : redirect()->to($back)->with('error', $result['message']);
        }

        return $wantsJson
            ? $this->response->setJSON(['message' => $result['message'], 'redirect' => $back])
            : redirect()->to($back)->with('message', $result['message']);
    }
}
