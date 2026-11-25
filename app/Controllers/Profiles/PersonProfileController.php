<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Filters\MessagesFilter;
use App\Services\Auth\RolePermissions;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\RateLimitedException;
use App\Services\Profiles\AvatarData;
use App\Services\Profiles\ProfileModeration;
use App\Services\Profiles\ProfilePhotoService;
use App\Services\Profiles\ProfileReader;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Someone else's profile (D20): the /people/{id} page, the popover card and the modal panel, all
 * from ProfileReader::forViewer, so only what the viewer may see is drawn. Anyone they may not
 * see answers 404, so an id cannot be probed.
 */
class PersonProfileController extends BaseController
{
    public function show(int $id): string|RedirectResponse
    {
        if ($id === (int) auth()->id()) {
            return redirect()->to(site_url('profile'));
        }
        $data = $this->data($id) ?? throw PageNotFoundException::forPageNotFound();

        return view('pages/profile/person', $data + ['title' => $data['p']['display_name'], 'page' => 'people', 'user' => auth()->user()]);
    }

    public function card(int $id): ResponseInterface
    {
        return $this->fragment($id, 'pages/profile/partials/popover');
    }

    public function panel(int $id): ResponseInterface
    {
        return $this->fragment($id, 'pages/profile/partials/person-panel', ['modal' => true]);
    }

    /** Starts (or reopens) the direct conversation through Messages' own start path, which asks canStart again. */
    public function message(int $id): RedirectResponse
    {
        $viewer = auth()->user();
        if ((new ProfileReader())->forViewer($viewer, $id) === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        try {
            $conversation = (new ConversationService())->startDirect($viewer, $id);
        } catch (RateLimitedException $e) {
            return redirect()->to(site_url('people/' . $id))->with('error', $e->getMessage());
        }

        // Refused as the Messages API refuses: a 404, never a reason.
        if ($conversation === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return redirect()->to(site_url('messages/' . $conversation['id']));
    }

    /** Per-viewer HTML, never kept by a shared cache. */
    /** @param array<string, mixed> $extra */
    private function fragment(int $id, string $view, array $extra = []): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $data = $this->data($id);

        if ($data === null) {
            return $this->response->setStatusCode(404)->setBody('<p class="text-sm text-[#646464]" data-profile-unavailable>This profile isn&#039;t available.</p>');
        }

        return $this->response->setBody(view($view, $data + $extra, ['saveData' => false]));
    }

    /** @return array<string, mixed>|null null when the viewer may not see this person */
    private function data(int $id): ?array
    {
        $viewer = auth()->user();
        $p      = (new ProfileReader())->forViewer($viewer, $id);
        if ($p === null) {
            return null;
        }
        if ($id === (int) $viewer->id && ($p['display_name'] ?? '') === '') {
            $p['display_name'] = (string) $viewer->username; // only yourself as a system administrator: no profile row
        }

        $admin       = array_key_exists('admin_actions', $p);
        $permissions = new RolePermissions();

        return [
            'p'          => $p,
            'avatar'     => (new AvatarData())->forMany([$id])[$id] ?? ['id' => $id, 'name' => $p['display_name']],
            'canMessage' => $this->canMessage($viewer, $id, $p),
            // Admin actions follow the People routes: Open in People needs people.view, the photo controls people.manage (null hides them).
            'canPeople'  => $admin && $permissions->allows($viewer, 'people.view'),
            'photo'      => $admin && $permissions->allows($viewer, 'people.manage') ? (new ProfilePhotoService())->state($id) : null,
            // Fields an administrator may clear that hold something now (field => label); null without people.manage.
            'clearable'  => $admin && $permissions->allows($viewer, 'people.manage') ? $this->clearable($id) : null,
        ];
    }

    /** @return array<string, string> */
    private function clearable(int $id): array
    {
        $row = db_connect()->table('user_profiles')->select(implode(', ', array_keys(ProfileModeration::CLEARABLE)))->where('user_id', $id)->get()->getRowArray() ?? [];

        return array_filter(ProfileModeration::CLEARABLE, static fn (string $label, string $field): bool => trim((string) ($row[$field] ?? '')) !== '', ARRAY_FILTER_USE_BOTH);
    }

    /** @param array<string, mixed> $p */
    private function canMessage(User $viewer, int $id, array $p): bool
    {
        if ($id === (int) $viewer->id || array_key_exists('deactivated_badge', $p) || MessagesFilter::closed() || (new InstitutionAccess())->stateFor((int) $viewer->id) !== InstitutionAccess::WRITABLE || ! (new RolePermissions())->allows($viewer, 'messages.use')) {
            return false;
        }
        $subject = model(UserModel::class)->findById($id);
        if ($subject === null) {
            return false;
        }
        // D32: the active-role exception is gone (decisions are on parties now); the same check that starting the conversation makes.
        return (new ConversationService())->resolveDirectStart($viewer, $subject) !== null;
    }
}
