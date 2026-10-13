<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\AcademicDefaults;
use App\Services\Institutions\BrandColours;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionAnnouncement;
use App\Services\Institutions\InstitutionProfileService;
use App\Services\Institutions\InstitutionSecurity;
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

    private const ACADEMIC = 'institution/settings/academic';

    private const ANNOUNCEMENT = 'institution/settings/announcement';

    private const SECURITY = 'institution/settings/security';

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

    public function academic(): string
    {
        return $this->show('academic');
    }

    public function saveAcademic(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => (new AcademicDefaults())->save($inst, $this->input(), auth()->user()), 'The academic defaults were saved.', self::ACADEMIC);
    }

    public function resetAcademic(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => (new AcademicDefaults())->reset($inst, auth()->user()), 'The academic defaults are back to the platform values.', self::ACADEMIC);
    }

    public function announcement(): string
    {
        return $this->show('announcement');
    }

    public function saveAnnouncement(): ResponseInterface|RedirectResponse
    {
        $input = $this->input();

        return $this->run(
            function (int $inst) use ($input): void {
                (new InstitutionAnnouncement())->save($inst, (string) ($input['text'] ?? ''), (string) ($input['until'] ?? ''));
            },
            trim((string) ($input['text'] ?? '')) === '' ? 'The announcement was removed.' : 'The announcement was saved.',
            self::ANNOUNCEMENT,
        );
    }

    public function removeAnnouncement(): ResponseInterface|RedirectResponse
    {
        return $this->run(function (int $inst): void {
            (new InstitutionAnnouncement())->remove($inst);
        }, 'The announcement was removed.', self::ANNOUNCEMENT);
    }

    public function security(): string
    {
        return $this->show('security');
    }

    public function saveSecurity(): ResponseInterface|RedirectResponse
    {
        $input = $this->input();

        return $this->run(function (int $inst) use ($input): void {
            (new InstitutionSecurity())->save($inst, $input);
        }, 'The security settings were saved.', self::SECURITY);
    }

    public function resetSecurity(): ResponseInterface|RedirectResponse
    {
        return $this->run(function (int $inst): void {
            (new InstitutionSecurity())->reset($inst);
        }, 'The security settings are back to the platform values.', self::SECURITY);
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
            'reauthDialog'  => $tab === 'security',
            'settingsAnnouncement' => $tab === 'announcement' ? (new InstitutionAnnouncement())->get($institutionId) : null,
            'settingsSecurity' => $tab === 'security' ? (new InstitutionSecurity())->forInstitution($institutionId) : null,
            'settingsAcademic' => $tab === 'academic' ? (new AcademicDefaults())->forInstitution($institutionId) : null,
            'settingsCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
            'settingsDefaults' => ['primary' => BrandColours::DEFAULT_PRIMARY, 'accent' => BrandColours::DEFAULT_ACCENT],
        ]);
    }
}
