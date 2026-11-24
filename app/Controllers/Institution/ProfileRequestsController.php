<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\PeopleService;
use App\Services\Profiles\AvatarData;
use App\Services\Profiles\CorrectionRequestService;
use App\Services\Profiles\ProfileEditException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Correction requests waiting for a decision (FR7, D22): the People → Requests tab for institutional admins, and
 * the decisions posted from it or from the platform Users screen (system admins, platform-routed requests). The
 * routes decide who may (people.manage or users.manage, not while read-only); CorrectionRequestService decides
 * which requests: one this admin may not decide is not found.
 */
class ProfileRequestsController extends BaseController
{
    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $admin         = auth()->user();
        $requests      = (new CorrectionRequestService())->pendingFor($admin);
        $writable      = (new InstitutionAccess())->canWrite((int) $admin->id);

        return view('pages/institution/people-requests', [
            'title'       => 'Requests',
            'page'        => 'people',
            'pageTitle'   => 'Requests',
            'user'        => $admin,
            'requests'    => $requests,
            'avatars'     => (new AvatarData())->forViewer($admin, array_column($requests, 'user_id')),
            'canDecide'   => $writable && $admin->can('people.manage'),
            'readOnly'    => ! $writable,
            'peopleOpen'  => (new PeopleService())->openInvitations($institutionId),
        ]);
    }

    /** Approve or reject one request; the note is required to reject. */
    public function decide(int $id, string $decision): ResponseInterface|RedirectResponse
    {
        $service   = new CorrectionRequestService();
        $admin     = auth()->user();
        $note      = (string) $this->request->getPost('note');
        $back      = site_url(str_starts_with(trim($this->request->getPath(), '/'), 'admin/') ? 'admin/users' : 'institution/people/requests');
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');

        try {
            $decision === 'approve' ? $service->approve($id, $admin, $note) : $service->reject($id, $admin, $note);
        } catch (ProfileEditException $e) {
            // Decided by someone else meanwhile: back to the list, which no longer shows it.
            if ($e->getMessage() !== CorrectionRequestService::ALREADY_DECIDED && $wantsJson) {
                return $this->response->setStatusCode(422)->setJSON(['errors' => $e->field !== '' ? [$e->field => $e->getMessage()] : [], 'message' => $e->getMessage()]);
            }

            session()->setFlashdata('error', $e->getMessage());

            return $wantsJson ? $this->response->setJSON(['redirect' => $back]) : redirect()->to($back);
        }

        $message = $decision === 'approve' ? 'Change approved' : 'Request rejected';
        session()->setFlashdata('message', $message);

        return $wantsJson ? $this->response->setJSON(['message' => $message, 'redirect' => $back]) : redirect()->to($back);
    }
}
