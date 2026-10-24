<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Academic\InstitutionModel;
use App\Services\Sis\SisOwnership;
use App\Services\Users\PasswordResetService;
use App\Services\Users\UserAdminService;
use App\Services\Users\UserDirectory;
use App\Services\Users\UserExport;
use App\Services\Users\UserFilters;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * People across every institution, for system administrators. The routes
 * decide who may reach what (users.view to look and export, users.manage to
 * change, plus the password confirmation for the changes that decide who can
 * get in); the rules live in UserAdminService.
 */
class UsersController extends BaseController
{
    /** The most rows one export holds. */
    private const EXPORT_MAX = 10000;

    private UserAdminService $users;

    private UserDirectory $directory;

    public function __construct()
    {
        $this->users     = new UserAdminService();
        $this->directory = new UserDirectory();
    }

    public function index(): string
    {
        $filters = UserFilters::fromInput($this->request->getGet());
        $result  = $this->directory->search($filters);

        return view('pages/admin/users/index', [
            'title'            => 'Users',
            'page'             => 'admin-users',
            'user'             => auth()->user(),
            'userFilters'      => $filters,
            'userRows'         => $result['rows'],
            'userTotal'        => $result['total'],
            'userPages'        => max(1, (int) ceil($result['total'] / $filters->perPage)),
            'userInstitutions' => $this->institutions(),
            'userSelf'         => (int) auth()->id(),
        ]);
    }

    /** Everyone matching the current filters, as a CSV file. */
    public function export(): ResponseInterface
    {
        $filters = UserFilters::fromInput($this->request->getGet());

        return $this->csv($this->directory->each($filters, self::EXPORT_MAX), ['filters' => $filters->toQuery()]);
    }

    /** Only the people ticked on the list, as a CSV file. */
    public function exportSelected(): ResponseInterface
    {
        $ids = $this->ids();

        if ($ids === []) {
            return redirect()->to('admin/users')->with('error', 'Choose at least one person first.');
        }

        return $this->csv($this->directory->byIds(array_slice($ids, 0, self::EXPORT_MAX)), ['selected' => count($ids)]);
    }

    public function new(): string
    {
        return view('pages/admin/users/new', [
            'title'           => 'Invite a person',
            'page'            => 'admin-users',
            'user'            => auth()->user(),
            'newInstitutions' => array_values(array_filter($this->institutions(), static fn (array $institution): bool => $institution['status'] !== InstitutionModel::PENDING)),
            'newRoles'        => UserAdminService::ASSIGNABLE_ROLES,
            'newValues'       => [
                'institution_id' => old('institution_id', $this->request->getGet('institution') ?? ''),
                'role'           => old('role', ''),
                'first_name'     => old('first_name', ''),
                'last_name'      => old('last_name', ''),
                'email'          => old('email', ''),
            ],
        ]);
    }

    public function invite(): RedirectResponse
    {
        $post = $this->request->getPost();

        try {
            $result = $this->users->invite((int) ($post['institution_id'] ?? 0), (string) ($post['role'] ?? ''), $post);
        } catch (InvalidArgumentException $e) {
            return redirect()->to('admin/users/new')->withInput()->with('error', $e->getMessage());
        }

        $back = redirect()->to('admin/users')->with(
            'message',
            $result['email_error'] === null
                ? "An invitation was emailed to {$result['email']}. They choose their own password, and the account is made then."
                : "An invitation for {$result['email']} was saved.",
        );

        return $result['email_error'] === null ? $back : $back->with('error', $result['email_error']);
    }

    public function show(int $id): string
    {
        try {
            $person = $this->users->find($id);
        } catch (InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('pages/admin/users/show', [
            'title'                 => ($person['first_name'] ?? '') !== '' ? trim($person['first_name'] . ' ' . $person['last_name']) : (string) $person['username'],
            'page'                  => 'admin-users',
            'user'                  => auth()->user(),
            'person'                => $person,
            'personAssignableRoles' => UserAdminService::ASSIGNABLE_ROLES,
            'personIsSelf'          => (int) auth()->id() === $id,
            'personSisManaged'      => (new SisOwnership())->managesPerson($id),
            'personValues'          => [
                'first_name' => old('first_name', (string) ($person['first_name'] ?? '')),
                'last_name'  => old('last_name', (string) ($person['last_name'] ?? '')),
                'email'      => old('email', (string) ($person['email'] ?? '')),
            ],
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        return $this->act($id, fn () => $this->users->update($id, $this->request->getPost()), 'Saved.', true);
    }

    public function deactivate(int $id): RedirectResponse
    {
        return $this->act($id, fn () => $this->users->deactivate($id), 'Account deactivated. They are signed out and cannot sign in.');
    }

    public function reactivate(int $id): RedirectResponse
    {
        return $this->act($id, fn () => $this->users->reactivate($id), 'Account reactivated.');
    }

    public function signOut(int $id): RedirectResponse
    {
        return $this->act($id, fn () => $this->users->signOut($id), 'Every session this person had has been ended.');
    }

    public function roles(int $id): RedirectResponse
    {
        $roles = array_values(array_filter((array) $this->request->getPost('roles'), 'is_string'));

        return $this->act($id, fn () => $this->users->setRoles($id, $roles), 'Roles saved.');
    }

    public function resetPassword(int $id): RedirectResponse
    {
        $back = redirect()->to("admin/users/{$id}");

        try {
            $result = (new PasswordResetService())->send($id);
        } catch (InvalidArgumentException $e) {
            return $back->with('error', $e->getMessage());
        }

        if ($result['email_error'] !== null) {
            return $back->with('message', "A password reset link for {$result['email']} was saved.")->with('error', $result['email_error']);
        }

        return $back->with('message', "A link to choose a new password was emailed to {$result['email']}. It works once, until " . substr($result['expires_at'], 0, 16) . '.');
    }

    /** Deactivate or reactivate everyone ticked on the list. */
    public function bulk(): RedirectResponse
    {
        $action = (string) $this->request->getPost('action');
        $back   = redirect()->to('admin/users');

        try {
            $result = $this->users->bulk($action, $this->ids());
        } catch (InvalidArgumentException $e) {
            return $back->with('error', $e->getMessage());
        }

        $verb    = $action === 'deactivate' ? 'deactivated' : 'reactivated';
        $done    = count($result['done']);
        $skipped = $result['skipped'];

        $back->with('message', $done === 0 ? 'Nobody was ' . $verb . '.' : $done . ' ' . ($done === 1 ? 'person was ' : 'people were ') . $verb . '.');

        if ($skipped !== []) {
            $reasons = array_unique(array_values($skipped));

            $back->with('error', count($skipped) . ' could not be ' . $verb . ': ' . implode(' ', $reasons));
        }

        return $back;
    }

    /**
     * Runs a change to one person and goes back to their page with the outcome,
     * keeping what was typed when it is refused.
     *
     * @param callable(): mixed $action
     */
    private function act(int $id, callable $action, string $success, bool $keepInput = false): RedirectResponse
    {
        $back = redirect()->to("admin/users/{$id}");

        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return ($keepInput ? $back->withInput() : $back)->with('error', $e->getMessage());
        }

        return $back->with('message', $success);
    }

    /**
     * The ticked people, as ids.
     *
     * @return list<int>
     */
    private function ids(): array
    {
        $ids = [];

        foreach ((array) $this->request->getPost('ids') as $id) {
            if (is_string($id) && preg_match('/^\d{1,9}$/', $id) === 1 && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @param array<string, mixed>           $narrowedBy what the export was narrowed by, for the record
     */
    private function csv(iterable $rows, array $narrowedBy): ResponseInterface
    {
        $export = UserExport::csv($rows);

        // The export is on the record: who took how many people, narrowed how.
        service('auditLogger')->log('users.exported', auth()->user(), [
            'target_type' => 'user',
            'new'         => ['rows' => $export['rows']] + $narrowedBy,
        ]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="users-' . date('Ymd-His') . '.csv"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($export['csv']);
    }

    /**
     * Institutions to choose from, deleted ones left out.
     *
     * @return list<array<string, mixed>>
     */
    private function institutions(): array
    {
        return (new InstitutionModel())->select('id, name, code, status')->orderBy('name')->findAll(1000);
    }
}
