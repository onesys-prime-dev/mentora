<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Academic\InstitutionModel;
use App\Services\Flags\FeatureFlagAdmin;
use App\Services\Flags\FeatureFlags;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Feature flags for system administrators: the list with each flag's global
 * setting, and a page per institution showing every flag's answer and why,
 * with a form to override it. The routes decide who may reach what (flags.view
 * to look, flags.manage to change, and the password again for a global change);
 * the rules live in FeatureFlagAdmin.
 */
class FlagsController extends BaseController
{
    private FeatureFlags $flags;

    private FeatureFlagAdmin $admin;

    public function __construct()
    {
        $this->flags = new FeatureFlags();
        $this->admin = new FeatureFlagAdmin();
    }

    public function index(): string
    {
        return view('pages/admin/flags/index', [
            'title'         => 'Feature flags',
            'page'          => 'admin',
            'user'          => auth()->user(),
            'flagDefs'      => $this->flags->definitions(),
            'flagGlobal'    => $this->flags->inspect(null),
            'flagAdoption'  => $this->flags->adoption(),
        ]);
    }

    public function setGlobal(string $key): RedirectResponse
    {
        return $this->act(
            'admin/flags',
            fn () => $this->admin->setGlobal($key, (string) $this->request->getPost('state'), $this->post('note'), $this->post('ends_on')),
            'Flag updated for everyone.',
        );
    }

    public function institution(int $institutionId): string
    {
        $institution = (new InstitutionModel())->find($institutionId) ?? throw PageNotFoundException::forPageNotFound();

        return view('pages/admin/flags/institution', [
            'title'            => 'Feature flags: ' . $institution['name'],
            'page'             => 'admin',
            'user'             => auth()->user(),
            'flagInstitution'  => $institution,
            'flagDefs'         => $this->flags->definitions(),
            'flagDetail'       => $this->flags->inspect($institutionId),
        ]);
    }

    public function setForInstitution(int $institutionId, string $key): RedirectResponse
    {
        return $this->act(
            "admin/institutions/{$institutionId}/flags",
            fn () => $this->admin->setForInstitution($institutionId, $key, (string) $this->request->getPost('state'), $this->post('note'), $this->post('ends_on')),
            'Flag updated for this institution.',
        );
    }

    /**
     * Runs a change and goes back with its outcome, keeping what was typed
     * when it is refused.
     *
     * @param callable(): bool $change true when something changed
     */
    private function act(string $back, callable $change, string $success): RedirectResponse
    {
        try {
            $changed = $change();
        } catch (InvalidArgumentException $e) {
            return redirect()->to($back)->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to($back)->with('message', $changed ? $success : 'No change: that is already how it is.');
    }

    private function post(string $field): ?string
    {
        $value = $this->request->getPost($field);

        return is_string($value) ? $value : null;
    }
}
