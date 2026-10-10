<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Models\Academic\InstitutionModel;
use App\Services\Institutions\InstitutionExport;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;
use Throwable;

/**
 * An institutional administrator's own data export (SA13). It works while
 * the institution is read-only or past (this route group is deliberately
 * outside the read-only filter) and only ever reaches the administrator's
 * own institution's exports.
 */
class ExportController extends BaseController
{
    public function index(): string|RedirectResponse
    {
        $institution = $this->institution();

        if ($institution === null) {
            return redirect()->to('/')->with('error', 'Your account is not linked to an institution.');
        }

        return view('pages/institution/export', [
            'title'         => 'Data export',
            'page'          => 'institution',
            'user'          => auth()->user(),
            'exportName'    => (string) $institution['name'],
            'exportAllowed' => InstitutionExport::isEligible((string) $institution['status']),
            'exportRows'    => (new InstitutionExport())->history((int) $institution['id']),
            'exportDays'    => InstitutionExport::KEEP_DAYS,
        ]);
    }

    public function create(): RedirectResponse
    {
        $institution = $this->institution();

        if ($institution === null) {
            return redirect()->to('/');
        }

        try {
            (new InstitutionExport())->build((int) $institution['id'], auth()->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->to('institution/export')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Institution export failed: ' . $e->getMessage());

            return redirect()->to('institution/export')->with('error', 'The export could not be made. Please try again, or contact support.');
        }

        return redirect()->to('institution/export')->with('message', 'Your export is ready below.');
    }

    public function download(int $id): DownloadResponse|RedirectResponse
    {
        $institution = $this->institution();
        $export      = $institution === null ? null : (new InstitutionExport())->downloadable((int) $institution['id'], $id);

        if ($export === null) {
            return redirect()->to('institution/export')->with('error', 'That export is no longer available. Take a new one.');
        }

        service('auditLogger')->log('institution.export_downloaded', auth()->user(), [
            'target_type'    => 'institution',
            'target_id'      => $institution['id'],
            'institution_id' => (int) $institution['id'],
            'new'            => ['export_id' => $id],
        ]);

        return $this->response->download($export['path'], null)->setFileName((string) $export['file_name']);
    }

    /** @return array<string, mixed>|null the signed-in administrator's institution */
    private function institution(): ?array
    {
        $id = (new PlanLimits())->institutionOf((int) auth()->id());

        return $id === null ? null : (new InstitutionModel())->find($id);
    }
}
