<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Academic\InstitutionModel;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Subscriptions\PlanService;
use App\Services\Subscriptions\SubscriptionInput;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Services\Subscriptions\SubscriptionService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An institution's subscription for system administrators: the current
 * subscription, its renewal history, and the forms to start one, record a
 * renewal, or correct an earlier record. The routes decide who may reach what
 * (subscriptions.view to look, subscriptions.manage to change); the rules live
 * in SubscriptionService.
 */
class SubscriptionsController extends BaseController
{
    private SubscriptionService $subscriptions;

    public function __construct()
    {
        $this->subscriptions = new SubscriptionService();
    }

    public function show(int $institutionId): string
    {
        $institution  = (new InstitutionModel())->find($institutionId) ?? throw PageNotFoundException::forPageNotFound();
        $subscription = $this->subscriptions->forInstitution($institutionId);

        // Plans to choose from: the active ones, plus the one already in use even if it has been retired.
        $plans = (new PlanService())->selectable();

        if ($subscription !== null && $subscription['plan_status'] === 'retired') {
            $plans[] = ['id' => $subscription['plan_id'], 'name' => $subscription['plan_name'] . ' (retired)'];
        }

        return view('pages/admin/subscription', [
            'title'              => 'Subscription: ' . $institution['name'],
            'page'               => 'admin',
            'user'               => auth()->user(),
            'subInstitution'     => $institution,
            'subscription'       => $subscription,
            'subHistory'         => $subscription === null ? [] : $this->subscriptions->history((int) $subscription['id']),
            'subPlans'           => $plans,
            'subUsage'           => $subscription === null ? null : (new PlanLimits())->report($institutionId),
            'subRunning'         => $subscription !== null && $this->subscriptions->isRunning($institutionId),
            'subStatus'          => $subscription === null ? null : (new SubscriptionLifecycle())->statusOf((string) $subscription['end_date'], $institutionId, new DateTimeImmutable()),
            'subDefaultCurrency' => SubscriptionInput::DEFAULT_CURRENCY,
        ]);
    }

    public function start(int $institutionId): RedirectResponse
    {
        // A pending institution gets its first subscription as part of onboarding, which also invites its administrator.
        if (($institution = (new InstitutionModel())->find($institutionId)) !== null && $institution['status'] === InstitutionModel::PENDING) {
            return redirect()->to("admin/institutions/{$institutionId}/onboarding")->with('error', 'Start a pending institution\'s subscription by finishing its onboarding.');
        }

        return $this->act(
            $institutionId,
            fn () => $this->subscriptions->start($institutionId, $this->request->getPost()),
            'Subscription started.',
        );
    }

    public function renew(int $institutionId): RedirectResponse
    {
        return $this->act(
            $institutionId,
            fn () => $this->subscriptions->renew($institutionId, $this->request->getPost()),
            'Renewal recorded. The subscription now runs further.',
        );
    }

    public function correct(int $institutionId, int $renewalId): RedirectResponse
    {
        return $this->act(
            $institutionId,
            fn () => $this->subscriptions->correct($institutionId, $renewalId, $this->request->getPost()),
            'Correction recorded. The earlier record is kept as it was.',
        );
    }

    /**
     * Runs an action and goes back to the subscription page with its outcome,
     * keeping what was typed when it is refused.
     *
     * @param callable(): mixed $action
     */
    private function act(int $institutionId, callable $action, string $success): RedirectResponse
    {
        $back = redirect()->to("admin/institutions/{$institutionId}/subscription");

        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return $back->withInput()->with('error', $e->getMessage());
        }

        return $back->with('message', $success);
    }
}
