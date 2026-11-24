<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Filters\MessagesFilter;
use App\Services\Auth\RolePermissions;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessagingContextResolver;
use App\Services\Messaging\MessagingPolicy;
use App\Services\Messaging\RateLimitedException;
use App\Services\Profiles\AvatarData;
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
        return $this->fragment($id, 'pages/profile/partials/person-panel');
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
    private function fragment(int $id, string $view): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $data = $this->data($id);

        if ($data === null) {
            return $this->response->setStatusCode(404)->setBody('<p class="text-sm text-[#646464]" data-profile-unavailable>This profile isn&#039;t available.</p>');
        }

        return $this->response->setBody(view($view, $data, ['saveData' => false]));
    }

    /** @return array<string, mixed>|null null when the viewer may not see this person */
    private function data(int $id): ?array
    {
        $viewer = auth()->user();
        $p      = (new ProfileReader())->forViewer($viewer, $id);
        if ($p === null) {
            return null;
        }
        if (($p['display_name'] ?? '') === '') {
            $p['display_name'] = (string) $viewer->username; // only yourself as a system administrator: no profile row
        }

        return [
            'p'          => $p,
            'avatar'     => (new AvatarData())->forMany([$id])[$id] ?? ['id' => $id, 'name' => $p['display_name']],
            'canMessage' => $this->canMessage($viewer, $id, $p),
        ];
    }

    /** @param array<string, mixed> $p */
    private function canMessage(User $viewer, int $id, array $p): bool
    {
        if ($id === (int) $viewer->id || array_key_exists('deactivated_badge', $p) || MessagesFilter::closed() || ! (new RolePermissions())->allows($viewer, 'messages.use')) {
            return false;
        }
        $subject = model(UserModel::class)->findById($id);
        if ($subject === null) {
            return false;
        }
        $resolver = new MessagingContextResolver();
        $context  = $resolver->forStart($viewer, $subject, null);

        // D32: the one accepted active_role read in the profile; the button follows Messages' own rule.
        return $context !== null && (new MessagingPolicy())->canStart($resolver->activeRole($viewer), $resolver->activeRole($subject), $context);
    }
}
