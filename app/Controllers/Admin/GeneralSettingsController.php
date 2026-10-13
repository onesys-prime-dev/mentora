<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Admin\GeneralSettings;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeZone;
use InvalidArgumentException;

/**
 * General settings for system administrators: name, support contact,
 * timezone, maintenance mode, the announcement, the default expiry timeline
 * and the backup alert age. The routes decide who may look (settings.view)
 * and change (settings.manage plus the password again).
 */
class GeneralSettingsController extends BaseController
{
    public function index(): string
    {
        return view('pages/admin/settings/general', [
            'title'     => 'General settings',
            'page'      => 'admin',
            'user'      => auth()->user(),
            'genValues' => (new GeneralSettings())->all(),
            'genZones'  => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(): RedirectResponse
    {
        try {
            (new GeneralSettings())->save($this->request->getPost());
        } catch (InvalidArgumentException $e) {
            return redirect()->to('admin/settings/general')->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/settings/general')->with('message', 'General settings saved.');
    }
}
