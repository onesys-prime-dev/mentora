<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Models\Academic\InstitutionModel;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\InstitutionSnapshot;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The institutional administrator's home: whose institution this is, how big
 * it is, and where it stands against its plan. Read-only, so it also works
 * while the institution is read-only or past.
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

        return view('pages/institution/home', [
            'title'           => 'Home',
            'page'            => 'dashboard',
            'user'            => auth()->user(),
            'homeInstitution' => $institution,
            'homeSnapshot'    => (new InstitutionSnapshot())->for((int) $institution['id']),
            'homeUsage'       => (new PlanLimits())->report((int) $institution['id']),
        ]);
    }
}
