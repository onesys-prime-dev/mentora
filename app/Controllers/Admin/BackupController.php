<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Backup\BackupService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Backups for system administrators: the history, the alert, and "Back up
 * now", which only asks the server's job (php spark backup:run) to take one.
 * Restoring is a documented manual procedure (docs/backup.md), not a button.
 */
class BackupController extends BaseController
{
    public function index(): string
    {
        $service = new BackupService();

        return view('pages/admin/backups', [
            'title'      => 'Backups',
            'page'       => 'admin',
            'user'       => auth()->user(),
            'backupRows' => $service->history(),
            'backupAlert' => $service->alert(),
            'backupFolder' => BackupService::folder(),
        ]);
    }

    public function request(): RedirectResponse
    {
        try {
            (new BackupService())->request(auth()->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->to('admin/backups')->with('error', $e->getMessage());
        }

        return redirect()->to('admin/backups')->with('message', 'Backup requested. The server takes it on its next run of "php spark backup:run".');
    }
}
