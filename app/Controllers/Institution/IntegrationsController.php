<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionIntegrations;
use App\Services\Institutions\InstitutionScope;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * The institution's view of its student information system connection and
 * the runs it may start. The routes decide who may (sis.run, the password for
 * a real import and for sending grades, and not while the institution is
 * read-only); the rules are the system administrator's own services behind
 * InstitutionIntegrations.
 */
class IntegrationsController extends BaseController
{
    private const BACK = 'institution/integrations';

    private InstitutionIntegrations $integrations;

    public function __construct()
    {
        $this->integrations = new InstitutionIntegrations();
    }

    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        return view('pages/institution/integrations', [
            'title'          => 'Integrations',
            'page'           => 'integrations',
            'pageTitle'      => 'Integrations',
            'user'           => auth()->user(),
            'reauthDialog'   => true,
            'sisConnection'  => $this->integrations->view($institutionId),
            'sisCanRun'      => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    public function preview(): RedirectResponse
    {
        return $this->importRun(true);
    }

    public function run(): RedirectResponse
    {
        return $this->importRun(false);
    }

    public function gradesPreview(): RedirectResponse
    {
        return $this->gradeRun(true);
    }

    public function gradesSend(): RedirectResponse
    {
        return $this->gradeRun(false);
    }

    private function importRun(bool $dryRun): RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        try {
            $result = $this->integrations->import($institutionId, $dryRun, auth()->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->to(self::BACK)->with('error', $e->getMessage());
        }

        if (! $result['ok']) {
            return redirect()->to(self::BACK)->with('error', 'The import failed. ' . $result['message']);
        }

        return redirect()->to(self::BACK)->with('message', ($dryRun ? 'Preview done: ' : 'Import done: ') . $result['message']);
    }

    private function gradeRun(bool $dryRun): RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        try {
            $result = $this->integrations->grades($institutionId, $dryRun, auth()->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->to(self::BACK)->with('error', $e->getMessage());
        }

        if (! $result['ok']) {
            return redirect()->to(self::BACK)->with('error', 'Sending grades failed. ' . $result['message']);
        }

        return redirect()->to(self::BACK)->with('message', $result['message']);
    }
}
