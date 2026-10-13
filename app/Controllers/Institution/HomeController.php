<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Models\Academic\InstitutionModel;
use App\Services\Audit\AuditLogFilters;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionAttention;
use App\Services\Institutions\InstitutionAuditLog;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\InstitutionSnapshot;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;

/**
 * The institutional administrator's home: whose institution this is, what
 * needs attention, how big it is, where it stands against its plan, and what
 * has just happened in it. Read-only, so it also works while the institution
 * is read-only or past.
 */
class HomeController extends BaseController
{
    public function index(): string|RedirectResponse
    {
        $institutionId = (new InstitutionScope())->institutionId();
        $institution   = $institutionId === null ? null : (new InstitutionModel())->find($institutionId);

        if ($institution === null) {
            return view('pages/institution/no-institution', [
                'title'     => 'Home',
                'page'      => 'institution',
                'pageTitle' => 'Institution',
                'user'      => auth()->user(),
            ]);
        }

        $user = auth()->user();
        $id   = (int) $institution['id'];

        // What the signed-in administrator is allowed to open; the page offers nothing else.
        $canAudit = $user->can('institution.audit');

        return view('pages/institution/home', [
            'title'           => 'Home',
            'page'            => 'dashboard',
            'user'            => $user,
            'homeInstitution' => $institution,
            'homeSnapshot'    => (new InstitutionSnapshot())->for($id),
            'homeUsage'       => (new PlanLimits())->report($id),
            'homeAttention'   => (new InstitutionAttention())->items($id, new DateTimeImmutable()),
            'homeActivity'    => $canAudit ? (new InstitutionAuditLog($id))->page(new AuditLogFilters(perPage: 6))['rows'] : [],
            'homeCanAudit'    => $canAudit,
            'homeShortcuts'   => $this->shortcuts((int) $user->id),
        ]);
    }

    /**
     * The quick actions this administrator may use right now: what needs a
     * permission they hold, and what writes only while the institution is not
     * read-only.
     *
     * @return list<array{label: string, icon: string, url: string}>
     */
    private function shortcuts(int $userId): array
    {
        $user     = auth()->user();
        $canWrite = (new InstitutionAccess())->canWrite($userId);
        $shortcuts = [];

        if ($canWrite && $user->can('people.manage')) {
            $shortcuts[] = ['label' => 'Invite a person', 'icon' => 'user', 'url' => site_url('institution/people')];
            $shortcuts[] = ['label' => 'Upload a CSV', 'icon' => 'folder', 'url' => site_url('institution/people/upload')];
        }

        if ($canWrite && $user->can('offerings.manage')) {
            $shortcuts[] = ['label' => 'Add an offering', 'icon' => 'modules', 'url' => site_url('institution/offerings')];
        }

        if ($user->can('reports.view')) {
            $shortcuts[] = ['label' => 'See reports', 'icon' => 'score', 'url' => site_url('institution/reports')];
        }

        return $shortcuts;
    }
}
