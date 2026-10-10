<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Admin\PlatformSettings;
use App\Services\Institutions\InstitutionService;
use App\Services\Institutions\OnboardingService;
use App\Services\Subscriptions\PlanService;
use App\Services\Subscriptions\SubscriptionInput;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Onboarding an institution, for system administrators: step 1 (the
 * institution) is the Add form; this controller has step 2 (plan and first
 * subscription) and step 3 (the first administrator). Step 2's answers are
 * kept in the session, checked but not saved; step 3 saves everything at once
 * (see OnboardingService), so leaving halfway changes nothing.
 */
class OnboardingController extends BaseController
{
    /** Session key: the subscription answers given so far, per institution id. */
    private const DRAFTS = 'onboarding_drafts';

    private InstitutionService $institutions;

    private OnboardingService $onboarding;

    public function __construct()
    {
        $this->institutions = new InstitutionService();
        $this->onboarding   = new OnboardingService();
    }

    /** Picks up where the person left off. */
    public function index(int $id): RedirectResponse
    {
        $institution = $this->institution($id);

        if ($institution['status'] !== 'pending') {
            return $this->alreadyDone($institution);
        }

        return redirect()->to("admin/institutions/{$id}/onboarding/" . ($this->draft($id) === null ? 'subscription' : 'administrator'));
    }

    /** @return RedirectResponse|string */
    public function subscription(int $id)
    {
        $institution = $this->institution($id);

        if ($institution['status'] !== 'pending') {
            return $this->alreadyDone($institution);
        }

        return view('pages/admin/onboarding/subscription', [
            'title'          => 'Onboard ' . $institution['name'],
            'page'           => 'admin',
            'user'           => auth()->user(),
            'onbInstitution' => $institution,
            'onbPlans'       => (new PlanService())->selectable(),
            'onbCurrency'    => SubscriptionInput::DEFAULT_CURRENCY,
            'onbValues'      => $this->draft($id) ?? [],
        ]);
    }

    public function saveSubscription(int $id): RedirectResponse
    {
        $institution = $this->institution($id);

        if ($institution['status'] !== 'pending') {
            return $this->alreadyDone($institution);
        }

        try {
            $term = $this->onboarding->checkSubscription($id, $this->request->getPost());
        } catch (InvalidArgumentException $e) {
            return redirect()->to("admin/institutions/{$id}/onboarding/subscription")->withInput()->with('error', $e->getMessage());
        }

        session()->set(self::DRAFTS, [$id => $term] + (array) session()->get(self::DRAFTS));

        return redirect()->to("admin/institutions/{$id}/onboarding/administrator");
    }

    /** @return RedirectResponse|string */
    public function administrator(int $id)
    {
        $institution = $this->institution($id);

        if ($institution['status'] !== 'pending') {
            return $this->alreadyDone($institution);
        }

        $term = $this->draft($id);

        if ($term === null) {
            return redirect()->to("admin/institutions/{$id}/onboarding/subscription")->with('error', 'Choose the plan and subscription period first.');
        }

        $plan = (new PlanService())->find($term['plan_id']);

        return view('pages/admin/onboarding/administrator', [
            'title'           => 'Onboard ' . $institution['name'],
            'page'            => 'admin',
            'user'            => auth()->user(),
            'onbInstitution'  => $institution,
            'onbTerm'         => $term,
            'onbPlanName'     => $plan['name'] ?? 'Unknown plan',
            'onbValidityDays' => (int) (new PlatformSettings())->get('invitationValidityDays'),
            'onbValues'       => [
                'first_name' => old('first_name', ''),
                'last_name'  => old('last_name', ''),
                'email'      => old('email', ''),
            ],
        ]);
    }

    public function finish(int $id): RedirectResponse
    {
        $institution = $this->institution($id);

        if ($institution['status'] !== 'pending') {
            return $this->alreadyDone($institution);
        }

        $term = $this->draft($id);

        if ($term === null) {
            return redirect()->to("admin/institutions/{$id}/onboarding/subscription")->with('error', 'Choose the plan and subscription period first.');
        }

        try {
            $result = $this->onboarding->complete($id, $term, $this->request->getPost());
        } catch (InvalidArgumentException $e) {
            return redirect()->to("admin/institutions/{$id}/onboarding/administrator")->withInput()->with('error', $e->getMessage());
        }

        $drafts = (array) session()->get(self::DRAFTS);
        unset($drafts[$id]);
        session()->set(self::DRAFTS, $drafts);

        $back = redirect()->to("admin/institutions/{$id}")->with(
            'message',
            "{$institution['name']} is onboarded. An invitation for {$result['email']} " . ($result['email_error'] === null ? 'was emailed; the link works until ' . substr($result['expires_at'], 0, 16) . '.' : 'was saved.'),
        );

        return $result['email_error'] === null ? $back : $back->with('error', $result['email_error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function institution(int $id): array
    {
        return $this->institutions->find($id) ?? throw PageNotFoundException::forPageNotFound();
    }

    /**
     * @return array{plan_id: int, period_start: string, period_end: string, amount: ?string, currency: string, reference: ?string, notes: ?string}|null
     */
    private function draft(int $id): ?array
    {
        $drafts = session()->get(self::DRAFTS);

        return is_array($drafts) && isset($drafts[$id]) && is_array($drafts[$id]) ? $drafts[$id] : null;
    }

    /** @param array<string, mixed> $institution */
    private function alreadyDone(array $institution): RedirectResponse
    {
        return redirect()->to('admin/institutions/' . $institution['id'])->with('message', "{$institution['name']} has already been onboarded.");
    }
}
