<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionService;
use App\Services\Institutions\InvitationService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * An institution's people, for system administrators: who belongs to it, and
 * the invitations to administer it (invite someone, send a link again).
 * The routes decide who may reach what; the rules live in InvitationService.
 */
class InstitutionPeopleController extends BaseController
{
    private InstitutionService $institutions;

    private InvitationService $invitations;

    public function __construct()
    {
        $this->institutions = new InstitutionService();
        $this->invitations  = new InvitationService();
    }

    public function index(int $institutionId): string
    {
        $institution = $this->institutions->find($institutionId) ?? throw PageNotFoundException::forPageNotFound();

        return view('pages/admin/institutions/users', [
            'title'             => 'Users: ' . $institution['name'],
            'page'              => 'admin',
            'user'              => auth()->user(),
            'peopleInstitution' => $institution,
            'peopleMembers'     => $this->institutions->members($institutionId),
            'peopleInvitations' => $this->invitations->forInstitution($institutionId),
            'peopleValues'      => [
                'first_name' => old('first_name', ''),
                'last_name'  => old('last_name', ''),
                'email'      => old('email', ''),
            ],
        ]);
    }

    public function invite(int $institutionId): RedirectResponse
    {
        $back = redirect()->to("admin/institutions/{$institutionId}/users");

        try {
            $result = $this->invitations->invite($institutionId, $this->request->getPost());
        } catch (InvalidArgumentException $e) {
            return $back->withInput()->with('error', $e->getMessage());
        }

        return $this->outcome($back, $result);
    }

    public function resend(int $institutionId, int $invitationId): RedirectResponse
    {
        $back = redirect()->to("admin/institutions/{$institutionId}/users");

        try {
            $result = $this->invitations->resend($institutionId, $invitationId);
        } catch (InvalidArgumentException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $this->outcome($back, $result);
    }

    /**
     * @param array{email: string, expires_at: string, email_error: ?string} $result
     */
    private function outcome(RedirectResponse $back, array $result): RedirectResponse
    {
        if ($result['email_error'] !== null) {
            return $back->with('message', "An invitation for {$result['email']} was saved.")->with('error', $result['email_error']);
        }

        return $back->with('message', "An invitation was emailed to {$result['email']}. The link works until " . substr($result['expires_at'], 0, 16) . '.');
    }
}
