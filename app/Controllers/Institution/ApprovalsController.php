<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\OverrideApprovalFilters;
use App\Services\Institutions\InstitutionScope;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institution administrator's Approvals (audit decision 1.1): the
 * grade-override requests of departments with no dean who may approve them,
 * to approve or reject (no send-back, 1.3), and the ones administrators
 * decided. The same page and dialog as the dean's; who may decide which
 * request is the service's check (never one's own, 1.5; the first decision
 * wins, 1.4).
 */
class ApprovalsController extends BaseController
{
    public function index(): string
    {
        $user        = auth()->user();
        $institution = (new InstitutionScope())->requireInstitutionId();
        $service     = new GradeOverrideRequestService();
        $filters     = OverrideApprovalFilters::fromInput($this->request->getGet());

        $loadError  = false;
        $requests   = [];
        $programs   = [];
        $requesters = [];
        $pending    = 0;

        try {
            $requests   = $service->listForInstitution($user, $filters);
            $programs   = $this->programs($institution);
            $requesters = $service->requestersForInstitution($user);
            $pending    = $filters->view === 'pending' && ! $filters->isFiltered() ? count($requests) : count($service->listForInstitution($user, new OverrideApprovalFilters()));

            // What a decision is judged by: how the student's grade is made up, for what still waits.
            if ($filters->view === 'pending') {
                foreach ($requests as &$row) {
                    $row['breakdown'] = $service->breakdown((int) $row['offering_id'], (int) $row['student_id']);
                }
                unset($row);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Institution approvals failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/dean/approvals', [
            'title'        => 'Approvals',
            'page'         => 'institution',
            'user'         => $user,
            'area'         => 'institution',
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

    /** @return list<array<string, mixed>> the institution's programs, for the filter */
    private function programs(int $institutionId): array
    {
        return db_connect()->table('programs p')
            ->select(['p.id', 'p.name', 'p.sub_department_id'])
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->orderBy('p.name', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * @param array{success: bool, message: string} $result
     */
    private function answer(array $result, string $field = 'comment'): ResponseInterface|RedirectResponse
    {
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back      = site_url('institution/approvals');

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
