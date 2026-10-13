<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionScope;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Services\Subscriptions\SubscriptionService;
use DateTimeImmutable;

/**
 * The institution's subscription, for its administrator to read: the plan,
 * the period, where it is on the timeline, usage against the plan's limits
 * and the renewal history. No actions: plans and renewals are the vendor's.
 */
class SubscriptionController extends BaseController
{
    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $service       = new SubscriptionService();
        $subscription  = $service->forInstitution($institutionId);

        $history = [];

        if ($subscription !== null) {
            // What was recorded and when, never by whom: that is the vendor's staff.
            $history = array_map(static fn (array $row): array => [
                'recorded_at'  => (string) $row['recorded_at'],
                'kind'         => (string) $row['kind'],
                'corrects'     => $row['corrects_renewal_id'] !== null,
                'plan_name'    => (string) $row['plan_name'],
                'period_start' => (string) $row['period_start'],
                'period_end'   => (string) $row['period_end'],
                'amount'       => $row['amount'],
                'currency'     => (string) $row['currency'],
                'reference'    => $row['reference'],
            ], $service->history((int) $subscription['id']));
        }

        return view('pages/institution/subscription', [
            'title'      => 'Subscription',
            'page'       => 'subscription',
            'pageTitle'  => 'Subscription',
            'user'       => auth()->user(),
            'planInfo'   => $subscription === null ? null : [
                'name'        => (string) $subscription['plan_name'],
                'retired'     => $subscription['plan_status'] === 'retired',
                'start_date'  => (string) $subscription['start_date'],
                'end_date'    => (string) $subscription['end_date'],
            ],
            'planStatus' => $subscription === null ? null : (new SubscriptionLifecycle())->statusOf((string) $subscription['end_date'], $institutionId, new DateTimeImmutable()),
            'planUsage'  => (new PlanLimits())->report($institutionId),
            'planHistory' => $history,
        ]);
    }
}
