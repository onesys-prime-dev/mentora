<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Security\SecuritySettings;
use App\Services\Users\UserFilters;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Security settings for system administrators: password length, sign-out after
 * inactivity, lockout after failed sign-ins, and which roles need an emailed
 * code. The routes decide who may look (settings.view) and change
 * (settings.manage plus the password again); the rules are in SecuritySettings.
 */
class SecuritySettingsController extends BaseController
{
    public function index(): string
    {
        $values = (new SecuritySettings())->all();

        return view('pages/admin/settings/security', [
            'title'         => 'Security settings',
            'page'          => 'admin',
            'user'          => auth()->user(),
            'secValues'     => $values,
            'secChoices'    => SecuritySettings::TWO_FACTOR_CHOICES,
            'secRoleLabels' => UserFilters::ROLE_LABELS,
        ]);
    }

    public function update(): RedirectResponse
    {
        $input = $this->request->getPost();

        try {
            (new SecuritySettings())->save($input);
        } catch (InvalidArgumentException $e) {
            return redirect()->to('admin/settings/security')->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/settings/security')->with('message', 'Security settings saved. They apply from the next request.');
    }
}
