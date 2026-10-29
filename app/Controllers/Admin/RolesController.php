<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Admin\PermissionMatrix;
use CodeIgniter\HTTP\RedirectResponse;
use Config\AuthGroups;
use InvalidArgumentException;

/**
 * The role x permission matrix: who may do what. Viewing needs roles.view;
 * changing a cell needs roles.manage and a recently confirmed password (see
 * the routes). The rules about what may change live in PermissionMatrix.
 */
class RolesController extends BaseController
{
    public function index(): string
    {
        $matrix = new PermissionMatrix();
        $groups = config(AuthGroups::class)->groups;

        $platform = array_flip($matrix->defaults()[PermissionMatrix::LOCKED_ROLE]);

        $roles = [];
        foreach ($matrix->roles() as $role) {
            $roles[$role] = $groups[$role]['title'] ?? $role;
        }

        $permissions = $matrix->permissions();

        return view('pages/admin/roles', [
            'title'          => 'Roles and permissions',
            'page'           => 'admin',
            'user'           => auth()->user(),
            'roleTitles'     => $roles,
            'lockedRole'     => PermissionMatrix::LOCKED_ROLE,
            'platformPerms'  => array_intersect_key($permissions, $platform),
            'academicPerms'  => array_diff_key($permissions, $platform),
            'currentMatrix'  => $matrix->effective(),
            'defaultMatrix'  => $matrix->defaults(),
            'roleGaps'       => $this->gaps(),
        ]);
    }

    /**
     * Every institution where nobody may approve overrides or sign off grades in some department (U1f).
     *
     * @return list<array{institution: string, department: string, permission: string}>
     */
    private function gaps(): array
    {
        $gaps = [];

        foreach (db_connect()->table('institutions')->select('id, name')->where('deleted_at IS NULL', null, false)->orderBy('name')->get()->getResultArray() as $institution) {
            foreach (\App\Services\Courses\Grades\GradeAuthority::gaps((int) $institution['id']) as $gap) {
                $gaps[] = ['institution' => (string) $institution['name']] + $gap;
            }
        }

        return $gaps;
    }

    /** Grants or revokes one permission for one role. */
    public function toggle(): RedirectResponse
    {
        $role       = (string) $this->request->getPost('role');
        $permission = (string) $this->request->getPost('permission');
        $granted    = $this->request->getPost('granted');

        if ($granted !== '1' && $granted !== '0') {
            return redirect()->to('admin/roles')->with('error', 'Choose whether to grant or revoke the permission.');
        }

        try {
            $changed = (new PermissionMatrix())->set($role, $permission, $granted === '1');
        } catch (InvalidArgumentException $e) {
            return redirect()->to('admin/roles')->with('error', $e->getMessage());
        }

        $message = $changed
            ? sprintf('%s %s for %s.', $granted === '1' ? 'Granted' : 'Revoked', $permission, $role)
            : 'No change: that is already how it is.';

        return redirect()->to('admin/roles')->with('message', $message);
    }
}
