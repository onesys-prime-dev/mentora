<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Auth\RolePermissions;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionRoles;
use App\Services\Institutions\InstitutionScope;
use CodeIgniter\HTTP\RedirectResponse;
use Config\AuthGroups;
use InvalidArgumentException;

/**
 * The institution's "Roles and permissions" page (audit decisions U1a–U1d):
 * the academic permissions of its five roles, on top of the platform's.
 * Changing a box needs institution.roles and a recently confirmed password
 * (see the routes); the rules live in InstitutionRoles.
 */
class RolesController extends BaseController
{
    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $groups        = config(AuthGroups::class)->groups;
        $permissions   = config(AuthGroups::class)->permissions;
        $matrices      = new RolePermissions();

        $roles = [];

        foreach (InstitutionRoles::ROLES as $role) {
            $roles[$role] = $groups[$role]['title'] ?? $role;
        }

        return view('pages/institution/roles', [
            'title'         => 'Roles and permissions',
            'page'          => 'institution',
            'pageTitle'     => 'Roles and permissions',
            'user'          => auth()->user(),
            'roleTitles'    => $roles,
            'rolePerms'     => array_intersect_key($permissions, array_flip(InstitutionRoles::ACADEMIC)),
            'currentMatrix' => $matrices->matrixFor($institutionId),
            'defaultMatrix' => $matrices->matrixFor(0),
            'rolesCanEdit'  => (new InstitutionAccess())->canWrite((int) auth()->id()),
            'flashToasts'   => true,
            // Where nobody may approve or sign off: requests and sign-offs there wait (U1f).
            'roleGaps'      => \App\Services\Courses\Grades\GradeAuthority::gaps($institutionId),
        ]);
    }

    /** Grants or revokes one permission for one role, in this institution. */
    public function toggle(): RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $role          = $this->request->getPost('role');
        $permission    = $this->request->getPost('permission');
        $granted       = $this->request->getPost('granted');

        // A hand-made form can send a list where one value belongs; it is told, not answered with an error page.
        if (! is_string($role) || ! is_string($permission)) {
            return redirect()->to('institution/roles')->with('error', 'Choose a role and a permission.');
        }

        if ($granted !== '1' && $granted !== '0') {
            return redirect()->to('institution/roles')->with('error', 'Choose whether to grant or revoke the permission.');
        }

        try {
            $changed = (new InstitutionRoles())->set($institutionId, $role, $permission, $granted === '1', auth()->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->to('institution/roles')->with('error', $e->getMessage());
        }

        $message = $changed
            ? sprintf('%s %s for %s.', $granted === '1' ? 'Granted' : 'Revoked', $permission, $role)
            : 'No change: that is already how it is.';

        return redirect()->to('institution/roles')->with('message', $message);
    }
}
