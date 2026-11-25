<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentPortal;
use App\Services\Profiles\AvatarData;
use App\Services\Profiles\CorrectionRequestService;
use App\Services\Profiles\ProfileEditException;
use App\Services\Profiles\ProfileEditService;
use App\Services\Profiles\ProfilePhotoService;
use App\Services\Profiles\ProfileReader;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Your own profile, for every role: what people in your school see of you and the details
 * your school manages. It never reads the active role: a person with two roles sees both.
 */
class ProfileController extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();
        $id   = (int) $user->id;
        $p    = (new ProfileReader())->forViewer($user, $id) ?? throw PageNotFoundException::forPageNotFound();

        $row = db_connect()->table('user_profiles')->select('first_name, middle_name, last_name, suffix, student_number, preferred_first_name, pronouns, bio, office_location, consultation_hours, checklist_dismissed_at')->where('user_id', $id)->get()->getRowArray() ?? [];
        if (($p['display_name'] ?? '') === '') {
            $p['display_name'] = (string) $user->username; // a system administrator has no profile row
        }

        $own = [
            'official' => array_filter(['First name' => $row['first_name'] ?? '', 'Middle name' => $row['middle_name'] ?? '', 'Last name' => $row['last_name'] ?? '', 'Suffix' => $row['suffix'] ?? ''], static fn ($v): bool => trim((string) $v) !== ''),
        ];

        $own['managed'] = ! $user->inGroup('admin'); // a system administrator's account is not school-managed

        // What the owner may ask to correct, with today's value; a system administrator edits their own instead (FR7.6).
        $now = ['first_name' => $row['first_name'] ?? '', 'middle_name' => $row['middle_name'] ?? '', 'last_name' => $row['last_name'] ?? '', 'suffix' => $row['suffix'] ?? '', 'email' => (string) $user->email];
        if ($user->inGroup('student')) {
            $now['student_number'] = $row['student_number'] ?? '';
        }
        $own['correction'] = $own['managed'] ? ['now' => $now, 'open' => (new CorrectionRequestService())->openFor($user), 'writable' => (new InstitutionAccess())->canWrite($id)] : null;
        $own['official_edit'] = $own['managed'] ? null : $now;

        // The student's own number comes from their own row, not from student_details.
        if (array_key_exists('student_details', $p)) {
            $own['student_number'] = trim((string) ($row['student_number'] ?? ''));
        }

        // Only parents who can see the student now, by name (the portal must be on), as the old page did.
        if (array_key_exists('parents', $p)) {
            $p['parents'] = array_map(static fn (string $name): array => ['name' => $name], (new ParentAccessService())->parentNamesWithAccess($id));
        }
        if (array_key_exists('children', $p)) {
            $own['portal_on'] = (new ParentPortal())->enabled((new PlanLimits())->institutionOf($id));
            $p['children']    = $own['portal_on'] ? $p['children'] : [];
        }

        // A system administrator's profile is name, email and role only (D29-C5), even once their official edit made a row.
        $edit = $this->hasProfile($id) && ! $user->inGroup('admin') ? [
            'preferred_first_name' => (string) ($row['preferred_first_name'] ?? ''),
            'pronouns'             => (string) ($row['pronouns'] ?? ''),
            'staff'                => $user->inGroup('dean', 'chairperson', 'instructor', 'institutional_admin'),
        ] : null;

        $canPreview = ! $user->inGroup('admin');
        $preview    = $canPreview && $this->request->getGet('preview') === 'student';
        $previewP   = $preview ? (new ProfileReader())->forPreview($user) : null;
        if ($previewP !== null) {
            $previewP['display_name'] = ($previewP['display_name'] ?? '') !== '' ? $previewP['display_name'] : (string) $user->username;
        }

        $photo = (new ProfilePhotoService())->state($id);

        return view('pages/profile/index', [
            'photo'      => $photo,
            'canPreview' => $canPreview,
            'preview'    => $preview,
            'previewP'   => $previewP,
            'checklist'  => $edit === null ? [] : $this->checklist($edit['staff'], $row, $photo),
            'title'  => 'My profile',
            'page'   => 'profile',
            'user'   => $user,
            'p'      => $p,
            'own'    => $own,
            'isOwner' => true,
            'edit'   => $edit,
            'avatar' => (new AvatarData())->forMany([$id])[$id] ?? ['id' => $id, 'name' => $p['display_name']],
        ]);
    }

    /**
     * What is still empty on the owner's own profile, unless they dismissed the card.
     *
     * @param array<string, mixed> $row
     * @param array{has: bool, on: bool} $photo
     *
     * @return array<string, array{label: string, dialog: string}>
     */
    private function checklist(bool $staff, array $row, array $photo): array
    {
        if (($row['checklist_dismissed_at'] ?? null) !== null) {
            return [];
        }
        $empty = static fn (string $k): bool => trim((string) ($row[$k] ?? '')) === '';
        $items = [];
        // Not while the school has photos off: there would be nowhere to show it.
        if (! $photo['has'] && $photo['on']) {
            $items['photo'] = ['label' => 'Add a photo', 'dialog' => 'profile-photo'];
        }
        if ($staff && $empty('consultation_hours')) {
            $items['consultation'] = ['label' => 'Add your consultation hours', 'dialog' => 'profile-edit-staff'];
        }
        if ($staff && $empty('office_location')) {
            $items['office'] = ['label' => 'Add your office', 'dialog' => 'profile-edit-staff'];
        }
        if ($empty('pronouns')) {
            $items['pronouns'] = ['label' => 'Add your pronouns', 'dialog' => 'profile-edit-identity'];
        }
        if ($empty('bio')) {
            $items['bio'] = ['label' => 'Write a short bio', 'dialog' => 'profile-edit-about'];
        }

        return $items;
    }

    /** Hides the checklist card for good, for the signed-in person only. */
    public function dismissChecklist(): RedirectResponse
    {
        $this->refuseSystemAdmin();

        db_connect()->table('user_profiles')->where('user_id', (int) auth()->id())->update(['checklist_dismissed_at' => date('Y-m-d H:i:s')]);

        return redirect()->to(site_url('profile'));
    }

    /** The old student/profile and parent/profile addresses: moved for good. */
    public function legacy(): RedirectResponse
    {
        return redirect()->to(site_url('profile'))->setStatusCode(301);
    }

    /** Saves one section of the signed-in person's own profile; the route only ever edits them. */
    public function update(string $section): ResponseInterface|RedirectResponse
    {
        $this->refuseSystemAdmin();

        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back      = site_url('profile');
        $input     = (array) $this->request->getPost();

        // The pronoun select and its custom text arrive as two fields; the service sees one.
        $choice = (string) ($input['pronouns_choice'] ?? '');
        if (array_key_exists('pronouns_choice', $input)) {
            $input['pronouns'] = $choice === 'custom' ? (string) ($input['pronouns_custom'] ?? '') : $choice;
        }

        try {
            (new ProfileEditService())->update(auth()->user(), $section, $input);
        } catch (ProfileEditException $e) {
            $field = $e->field;

            return $wantsJson
                ? $this->response->setStatusCode(422)->setJSON(['errors' => $field !== '' ? [$field => $e->getMessage()] : [], 'message' => $e->getMessage()])
                : redirect()->to($back)->with('error', $e->getMessage());
        }

        if ($wantsJson) {
            // The dialog's script reloads the page; the message is waiting there.
            session()->setFlashdata('message', 'Profile updated');

            return $this->response->setJSON(['message' => 'Profile updated', 'redirect' => $back]);
        }

        return redirect()->to($back)->with('message', 'Profile updated');
    }

    /** The owner asks for a correction of one official detail (FR7). */
    public function requestCorrection(): ResponseInterface|RedirectResponse
    {
        $user = auth()->user();
        $this->refuseSystemAdmin(); // they edit their own directly

        $post = $this->request;

        return $this->answer(static fn () => (new CorrectionRequestService())->submit($user, (string) $post->getPost('field'), (string) $post->getPost('new_value'), (string) $post->getPost('reason')), 'Request sent');
    }

    /** A system administrator's own official details, edited directly (FR7.6). Anyone else: not found. */
    public function updateOfficial(): ResponseInterface|RedirectResponse
    {
        $input = (array) $this->request->getPost();

        return $this->answer(static fn () => (new CorrectionRequestService())->updateOwnOfficial(auth()->user(), $input), 'Profile updated');
    }

    /** Runs an owner's change and answers the dialog (JSON) or the plain form (redirect), as update() does. */
    private function answer(callable $change, string $message): ResponseInterface|RedirectResponse
    {
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back      = site_url('profile');

        try {
            $change();
        } catch (ProfileEditException $e) {
            return $wantsJson
                ? $this->response->setStatusCode(422)->setJSON(['errors' => $e->field !== '' ? [$e->field => $e->getMessage()] : [], 'message' => $e->getMessage()])
                : redirect()->to($back)->with('error', $e->getMessage());
        }

        if ($wantsJson) {
            session()->setFlashdata('message', $message);

            return $this->response->setJSON(['message' => $message, 'redirect' => $back]);
        }

        return redirect()->to($back)->with('message', $message);
    }

    /**
     * The owner's self-service edits are not for a system administrator (D29-C5): name, email and role only.
     *
     * @throws PageNotFoundException
     */
    public static function refuseSystemAdmin(): void
    {
        if (auth()->user()?->inGroup('admin')) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function hasProfile(int $id): bool
    {
        return db_connect()->table('user_profiles')->where('user_id', $id)->countAllResults() > 0;
    }
}
