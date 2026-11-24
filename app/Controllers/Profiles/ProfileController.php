<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentPortal;
use App\Services\Profiles\AvatarData;
use App\Services\Profiles\ProfileEditException;
use App\Services\Profiles\ProfileEditService;
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

        $row = db_connect()->table('user_profiles')->select('first_name, middle_name, last_name, suffix, student_number, preferred_first_name, pronouns')->where('user_id', $id)->get()->getRowArray() ?? [];
        if (($p['display_name'] ?? '') === '') {
            $p['display_name'] = (string) $user->username; // a system administrator has no profile row
        }

        $own = [
            'official' => array_filter(['First name' => $row['first_name'] ?? '', 'Middle name' => $row['middle_name'] ?? '', 'Last name' => $row['last_name'] ?? '', 'Suffix' => $row['suffix'] ?? ''], static fn ($v): bool => trim((string) $v) !== ''),
        ];

        // The student's own number comes from their own row, not from student_details.
        if (array_key_exists('student_details', $p)) {
            $own['student_number'] = trim((string) ($row['student_number'] ?? ''));
        }

        // Only parents who can see the student now, by name (the portal must be on), as the old page did.
        if (array_key_exists('parents', $p)) {
            $p['parents'] = (new ParentAccessService())->parentNamesWithAccess($id);
        }
        if (array_key_exists('children', $p)) {
            $own['portal_on'] = (new ParentPortal())->enabled((new PlanLimits())->institutionOf($id));
            $p['children']    = $own['portal_on'] ? $p['children'] : [];
        }

        // A system administrator has no profile row, so nothing to edit.
        $edit = $this->hasProfile($id) ? [
            'preferred_first_name' => (string) ($row['preferred_first_name'] ?? ''),
            'pronouns'             => (string) ($row['pronouns'] ?? ''),
            'staff'                => $user->inGroup('dean', 'chairperson', 'instructor', 'institutional_admin'),
        ] : null;

        return view('pages/profile/index', [
            'title'  => 'My profile',
            'page'   => 'profile',
            'user'   => $user,
            'p'      => $p,
            'own'    => $own,
            'edit'   => $edit,
            'avatar' => (new AvatarData())->forMany([$id])[$id] ?? ['id' => $id, 'name' => $p['display_name']],
        ]);
    }

    /** The old student/profile and parent/profile addresses: moved for good. */
    public function legacy(): RedirectResponse
    {
        return redirect()->to(site_url('profile'))->setStatusCode(301);
    }

    /** Saves one section of the signed-in person's own profile; the route only ever edits them. */
    public function update(string $section): ResponseInterface|RedirectResponse
    {
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

        return $wantsJson
            ? $this->response->setJSON(['message' => 'Profile updated'])
            : redirect()->to($back)->with('message', 'Profile updated');
    }

    private function hasProfile(int $id): bool
    {
        return db_connect()->table('user_profiles')->where('user_id', $id)->countAllResults() > 0;
    }
}
