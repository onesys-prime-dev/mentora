<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionService;
use DateTimeImmutable;

/**
 * The archive: institutions that are no longer running. Past ones (kept,
 * read-only, renewable) and deleted ones (restorable until they are purged).
 * Restore and purge post to InstitutionsController.
 */
class ArchiveController extends BaseController
{
    public function index(): string
    {
        $institutions = new InstitutionService();
        $now          = new DateTimeImmutable();

        $deleted = $institutions->deletedInstitutions($now);

        foreach ($deleted as &$row) {
            $row['contents'] = $institutions->contents((int) $row['id']);
        }

        return view('pages/admin/archive', [
            'title'            => 'Archive',
            'page'             => 'admin',
            'user'             => auth()->user(),
            'pastRows'         => $institutions->pastInstitutions(),
            'deletedRows'      => $deleted,
            'retentionDays'    => $institutions->retentionDays(),
        ]);
    }
}
