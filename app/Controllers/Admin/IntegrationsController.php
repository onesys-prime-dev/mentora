<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Academic\InstitutionModel;
use App\Services\Sis\SisConnectionService;
use App\Services\Sis\SisConnectors;
use App\Services\Sis\SisException;
use App\Services\Sis\SisRunLog;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * One institution's student information system connection: its settings,
 * credentials (write-only), a connection test, and the run history. The
 * routes decide who may look (integrations.view) and who may change
 * (integrations.manage, plus the password); the rules live in
 * SisConnectionService.
 */
class IntegrationsController extends BaseController
{
    public function show(int $institutionId): string
    {
        $institution = $this->institution($institutionId);
        $service     = new SisConnectionService();
        $connection  = $service->view($institutionId);

        return view('pages/admin/institutions/integrations', [
            'title'              => 'Integrations: ' . $institution['name'],
            'page'               => 'admin',
            'user'               => auth()->user(),
            'sisInstitution'     => $institution,
            'sisConnection'      => $connection,
            'sisType'            => SisConnectors::types()[$connection['connector']],
            'sisRuns'            => (new SisRunLog())->history($institutionId),
        ]);
    }

    public function save(int $institutionId): RedirectResponse
    {
        $this->institution($institutionId);

        $input = [];

        foreach (array_keys(SisConnectors::types()[SisConnectors::FILE]['fields']) as $field) {
            $input[$field] = $this->request->getPost($field);
        }

        try {
            (new SisConnectionService())->save(
                $institutionId,
                $this->request->getPost('enabled') === '1',
                $input,
                $this->request->getPost('clear_credentials') === '1',
                auth()->user(),
            );
        } catch (InvalidArgumentException | SisException $e) {
            return redirect()->to("admin/institutions/{$institutionId}/integrations")->with('error', $e->getMessage());
        }

        return redirect()->to("admin/institutions/{$institutionId}/integrations")->with('message', 'Connection saved.');
    }

    public function test(int $institutionId): RedirectResponse
    {
        $this->institution($institutionId);

        $result = (new SisConnectionService())->test($institutionId, auth()->user());

        return redirect()->to("admin/institutions/{$institutionId}/integrations")->with($result->ok ? 'message' : 'error', ($result->ok ? 'Connection works. ' : 'Connection test failed. ') . $result->message);
    }

    /** @return array<string, mixed> */
    private function institution(int $institutionId): array
    {
        return (new InstitutionModel())->find($institutionId) ?? throw PageNotFoundException::forPageNotFound();
    }
}
