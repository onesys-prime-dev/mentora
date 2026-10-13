<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Flags\FeatureFlags;
use App\Services\Subscriptions\PlanService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Subscription plans, for system administrators. The routes decide who may
 * reach what (subscriptions.view to look, subscriptions.manage to change, and
 * the password again to edit, retire or reinstate); the rules live in
 * PlanService and PlanInput.
 */
class PlansController extends BaseController
{
    private PlanService $plans;

    public function __construct()
    {
        $this->plans = new PlanService();
    }

    public function index(): string
    {
        return view('pages/admin/plans/index', [
            'title'    => 'Plans',
            'page'     => 'admin',
            'user'     => auth()->user(),
            'planRows' => $this->plans->all(),
        ]);
    }

    public function new(): string
    {
        return $this->form('Add a plan', null, []);
    }

    public function create(): RedirectResponse
    {
        try {
            $this->plans->create($this->request->getPost());
        } catch (InvalidArgumentException $e) {
            return redirect()->to('admin/plans/new')->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/plans')->with('message', 'Plan added.');
    }

    public function edit(int $id): string
    {
        $plan = $this->plans->find($id) ?? throw PageNotFoundException::forPageNotFound();

        return $this->form('Edit ' . $plan['name'], $plan, $plan);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            $this->plans->update($id, $this->request->getPost());
        } catch (InvalidArgumentException $e) {
            return redirect()->to("admin/plans/{$id}/edit")->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/plans')->with('message', 'Plan saved. Institutions on this plan now get the new features and limits.');
    }

    public function retire(int $id): RedirectResponse
    {
        return $this->act(fn () => $this->plans->retire($id), 'Plan retired. Institutions already on it keep it; it can no longer be chosen for new subscriptions.');
    }

    public function reinstate(int $id): RedirectResponse
    {
        return $this->act(fn () => $this->plans->reinstate($id), 'Plan reinstated.');
    }

    /**
     * @param callable(): void $action
     */
    private function act(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return redirect()->to('admin/plans')->with('error', $e->getMessage());
        }

        return redirect()->to('admin/plans')->with('message', $success);
    }

    /**
     * @param array<string, mixed>|null $plan   null when adding
     * @param array<string, mixed>      $values what to show in the fields
     */
    private function form(string $heading, ?array $plan, array $values): string
    {
        $features = $values['features'] ?? [];

        return view('pages/admin/plans/form', [
            'title'       => $heading,
            'page'        => 'admin',
            'user'        => auth()->user(),
            'formHeading' => $heading,
            'planFeatureKeys' => array_keys((new FeatureFlags())->definitions()),
            'plan'        => $plan,
            'formValues'  => [
                'name'                => old('name', $values['name'] ?? ''),
                'description'         => old('description', $values['description'] ?? ''),
                'features'            => old('features', is_array($features) ? implode("\n", $features) : ''),
                'seat_limit'          => old('seat_limit', $values['seat_limit'] ?? ''),
                'storage_limit_mb'    => old('storage_limit_mb', $values['storage_limit_mb'] ?? ''),
                'ai_generation_limit' => old('ai_generation_limit', $values['ai_generation_limit'] ?? ''),
            ],
        ]);
    }
}
