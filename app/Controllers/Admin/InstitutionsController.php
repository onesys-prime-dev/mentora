<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionFilters;
use App\Services\Institutions\InstitutionService;
use App\Services\Subscriptions\PlanService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Institutions, for system administrators. The routes decide who may reach
 * what (institutions.view to look, institutions.manage to change, plus the
 * password confirmation for a restore); the rules live in InstitutionService.
 */
class InstitutionsController extends BaseController
{
    private InstitutionService $institutions;

    public function __construct()
    {
        $this->institutions = new InstitutionService();
    }

    public function index(): string
    {
        $filters = InstitutionFilters::fromInput($this->request->getGet());
        $result  = $this->institutions->search($filters);

        return view('pages/admin/institutions/index', [
            'title'              => 'Institutions',
            'page'               => 'admin',
            'user'               => auth()->user(),
            'institutionFilters' => $filters,
            'institutionRows'    => $result['rows'],
            'institutionTotal'   => $result['total'],
            'institutionPlans'   => (new PlanService())->all(),
            'institutionPages'   => max(1, (int) ceil($result['total'] / $filters->perPage)),
        ]);
    }

    public function new(): string
    {
        return $this->form('Add an institution', null, []);
    }

    public function create(): RedirectResponse
    {
        $name = (string) $this->request->getPost('name');
        $code = (string) $this->request->getPost('code');

        try {
            $this->institutions->create($name, $code);
        } catch (InvalidArgumentException $e) {
            return redirect()->to('admin/institutions/new')->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/institutions')->with('message', "Added {$name}. It stays pending until onboarding is finished.");
    }

    public function edit(int $id): string
    {
        $institution = $this->institutions->find($id) ?? throw PageNotFoundException::forPageNotFound();

        return $this->form('Edit ' . $institution['name'], $institution, $institution);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            $this->institutions->rename($id, (string) $this->request->getPost('name'));
        } catch (InvalidArgumentException $e) {
            return redirect()->to("admin/institutions/{$id}/edit")->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/institutions')->with('message', 'Institution saved.');
    }

    public function suspend(int $id): RedirectResponse
    {
        return $this->act(fn () => $this->institutions->suspend($id), 'Institution suspended. It is now read-only and can be renewed.');
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->act(
            fn () => $this->institutions->delete($id, (string) $this->request->getPost('confirm_name')),
            'Institution deleted. It can be restored until it is purged.',
        );
    }

    public function restore(int $id): RedirectResponse
    {
        return $this->act(fn () => $this->institutions->restore($id), 'Institution restored.');
    }

    /** Permanently removes a deleted institution. Needs the exact name typed and the password (see the routes). */
    public function purge(int $id): RedirectResponse
    {
        return $this->act(
            fn () => $this->institutions->purge($id, (string) $this->request->getPost('confirm_name')),
            'Institution purged. It and everything in it has been permanently removed.',
        );
    }

    /**
     * Runs an action and goes back with its outcome: to the archive when the
     * request came from there, otherwise to the list.
     *
     * @param callable(): void $action
     */
    private function act(callable $action, string $success): RedirectResponse
    {
        $back = $this->request->getPost('return') === 'archive' ? 'admin/archive' : 'admin/institutions';

        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return redirect()->to($back)->with('error', $e->getMessage());
        }

        return redirect()->to($back)->with('message', $success);
    }

    /**
     * @param array<string, mixed>|null $institution null when adding
     * @param array<string, mixed>      $values      what to show in the fields
     */
    private function form(string $heading, ?array $institution, array $values): string
    {
        return view('pages/admin/institutions/form', [
            'title'       => $heading,
            'page'        => 'admin',
            'user'        => auth()->user(),
            'formHeading' => $heading,
            'institution' => $institution,
            'formValues'  => array_merge($values, [
                'name' => old('name', $values['name'] ?? ''),
                'code' => old('code', $values['code'] ?? ''),
            ]),
        ]);
    }
}
