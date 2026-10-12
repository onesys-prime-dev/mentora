<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\BrandColours;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionProfileService;
use App\Services\Institutions\InstitutionScope;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institution's own settings: its profile and its branding. The routes
 * decide who may (institution.settings, and not while the institution is
 * read-only); the rules and audit entries live in InstitutionProfileService.
 */
class InstitutionSettingsController extends BaseController
{
    use AnswersDialogForms;

    private const PROFILE = 'institution/settings';

    private const BRANDING = 'institution/settings/branding';

    private InstitutionProfileService $profiles;

    public function __construct()
    {
        $this->profiles = new InstitutionProfileService();
    }

    public function profile(): string
    {
        return $this->show('profile');
    }

    public function branding(): string
    {
        return $this->show('branding');
    }

    public function saveProfile(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->profiles->saveProfile($inst, $this->input(), auth()->user()), 'The profile was saved.', self::PROFILE);
    }

    public function saveBranding(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->profiles->saveBranding($inst, $this->input(), auth()->user()), 'The colours were saved.', self::BRANDING);
    }

    public function resetBranding(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->profiles->resetBranding($inst, auth()->user()), 'The colours are back to the Mentora colours.', self::BRANDING);
    }

    public function uploadLogo(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->profiles->setLogo($inst, $this->request->getFile('logo'), auth()->user()), 'The logo was saved.', self::BRANDING);
    }

    public function removeLogo(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->profiles->removeLogo($inst, auth()->user()), 'The logo was removed.', self::BRANDING);
    }

    private function show(string $tab): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        return view('pages/institution/settings', [
            'title'         => 'Institution settings',
            'page'          => 'institution-settings',
            'pageTitle'     => 'Institution settings',
            'user'          => auth()->user(),
            'settingsTab'   => $tab,
            'settingsData'  => $this->profiles->get($institutionId),
            'settingsLogo'  => null,
            'settingsCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
            'settingsDefaults' => ['primary' => BrandColours::DEFAULT_PRIMARY, 'accent' => BrandColours::DEFAULT_ACCENT],
        ]);
    }
}
