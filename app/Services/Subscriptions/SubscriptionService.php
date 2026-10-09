<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Academic\InstitutionModel;
use App\Models\Subscriptions\PlanModel;
use App\Models\Subscriptions\SubscriptionModel;
use App\Models\Subscriptions\SubscriptionRenewalModel;
use App\Services\Audit\RecordsAuditedChanges;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * An institution's subscription and its renewal history (SA13).
 *
 * start() makes the first subscription, renew() adds a term and extends the
 * end date, correct() puts a mistake in an earlier row right. Each of them
 * writes a new row to the history; no row there is ever changed or removed.
 * Billing is record-keeping only: amounts and references are for reference.
 *
 * Every change is checked here, recorded in the audit log, and undone if it
 * cannot be recorded. Every refusal is an InvalidArgumentException whose
 * message is fit to show.
 */
class SubscriptionService
{
    use RecordsAuditedChanges;

    /**
     * The institution's subscription with its plan's name, or null if it has none.
     *
     * @return array<string, mixed>|null
     */
    public function forInstitution(int $institutionId): ?array
    {
        return db_connect()->table('subscriptions')
            ->select('subscriptions.*, plans.name AS plan_name, plans.status AS plan_status')
            ->join('plans', 'plans.id = subscriptions.plan_id')
            ->where('subscriptions.institution_id', $institutionId)
            ->get()->getRowArray();
    }

    /**
     * The history of a subscription, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $subscriptionId): array
    {
        return db_connect()->table('subscription_renewals')
            ->select('subscription_renewals.*, plans.name AS plan_name')
            ->join('plans', 'plans.id = subscription_renewals.plan_id')
            ->where('subscription_renewals.subscription_id', $subscriptionId)
            ->orderBy('subscription_renewals.id', 'desc')
            ->get()->getResultArray();
    }

    /** True while the institution has a subscription that has not ended on the given day (today by default). */
    public function isRunning(int $institutionId, ?DateTimeImmutable $on = null): bool
    {
        $day = ($on ?? new DateTimeImmutable())->format('Y-m-d');

        return db_connect()->table('subscriptions')
            ->where('institution_id', $institutionId)
            ->where('end_date >=', $day)
            ->countAllResults() > 0;
    }

    /**
     * Starts an institution's first subscription.
     *
     * @param array<string, mixed> $input a form post: plan_id, period_start, period_end, amount, currency, reference, notes
     *
     * @return int the subscription's id
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function start(int $institutionId, array $input, ?User $actor = null): int
    {
        $term = SubscriptionInput::term($input);

        $this->institution($institutionId);

        if ($this->forInstitution($institutionId) !== null) {
            throw new InvalidArgumentException('This institution already has a subscription. Record a renewal instead.');
        }

        $plan = $this->plan($term['plan_id']);

        if ($plan['status'] !== PlanModel::ACTIVE) {
            throw new InvalidArgumentException("The plan \"{$plan['name']}\" is retired and cannot be chosen for a new subscription.");
        }

        $subscriptionId = $this->inTransaction(function () use ($institutionId, $term, $actor): int {
            $model = new SubscriptionModel();
            $id    = $model->insert([
                'institution_id' => $institutionId,
                'plan_id'        => $term['plan_id'],
                'start_date'     => $term['period_start'],
                'end_date'       => $term['period_end'],
            ]);

            if ($id === false) {
                throw new RuntimeException('The subscription could not be saved.');
            }

            $this->addRow((int) $id, SubscriptionRenewalModel::START, null, $term, $actor);

            return (int) $id;
        });

        $this->auditedChange(
            static fn () => (new SubscriptionModel())->delete($subscriptionId), // the database removes its history with it
            'subscription.started',
            $actor,
            'subscription',
            $subscriptionId,
            $institutionId,
            ['new' => ['plan' => $plan['name']] + $term],
        );

        return $subscriptionId;
    }

    /**
     * Records a renewal: a new term that carries the subscription further.
     * The end date moves to the end of the new term, and the plan may change
     * (to an active plan; renewing on the plan it already has is always fine,
     * even if that plan has since been retired).
     *
     * @param array<string, mixed> $input a form post: plan_id, period_start, period_end, amount, currency, reference, notes
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function renew(int $institutionId, array $input, ?User $actor = null): void
    {
        $term = SubscriptionInput::term($input);

        $this->institution($institutionId);

        $subscription = $this->forInstitution($institutionId)
            ?? throw new InvalidArgumentException('This institution has no subscription yet. Start one first.');

        $plan = $this->plan($term['plan_id']);

        if ($term['plan_id'] !== (int) $subscription['plan_id'] && $plan['status'] !== PlanModel::ACTIVE) {
            throw new InvalidArgumentException("The plan \"{$plan['name']}\" is retired. Choose an active plan, or keep the current one.");
        }

        if ($term['period_start'] < $subscription['start_date']) {
            throw new InvalidArgumentException('A renewal cannot start before the subscription did (' . $subscription['start_date'] . ').');
        }

        if ($term['period_end'] <= $subscription['end_date']) {
            throw new InvalidArgumentException('A renewal must end after the current end date (' . $subscription['end_date'] . '). To fix a mistake in an earlier record, add a correction instead.');
        }

        $before = ['plan_id' => (int) $subscription['plan_id'], 'end_date' => $subscription['end_date']];

        $rowId = $this->inTransaction(function () use ($subscription, $term, $actor): int {
            $updated = (new SubscriptionModel())->update((int) $subscription['id'], ['plan_id' => $term['plan_id'], 'end_date' => $term['period_end']]);

            if (! $updated) {
                throw new RuntimeException('The subscription could not be updated.');
            }

            return $this->addRow((int) $subscription['id'], SubscriptionRenewalModel::RENEWAL, null, $term, $actor);
        });

        $this->auditedChange(
            function () use ($subscription, $before, $rowId): void {
                (new SubscriptionModel())->update((int) $subscription['id'], $before);
                $this->removeRow($rowId);
            },
            'subscription.renewed',
            $actor,
            'subscription',
            (int) $subscription['id'],
            $institutionId,
            [
                'old' => ['plan_id' => $before['plan_id'], 'end_date' => $before['end_date']],
                'new' => ['plan' => $plan['name'], 'end_date' => $term['period_end']] + $term,
            ],
        );
    }

    /**
     * Puts a mistake in an earlier history row right, by adding a new row
     * (kind `correction`) that points at it. The earlier row is untouched and
     * the subscription's plan and dates do not change.
     *
     * @param array<string, mixed> $input a form post: amount, currency, reference, notes
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function correct(int $institutionId, int $renewalId, array $input, ?User $actor = null): void
    {
        $payment = SubscriptionInput::payment($input);

        $row = db_connect()->table('subscription_renewals')
            ->select('subscription_renewals.*')
            ->join('subscriptions', 'subscriptions.id = subscription_renewals.subscription_id')
            ->where('subscription_renewals.id', $renewalId)
            ->where('subscriptions.institution_id', $institutionId) // a record of another institution does not exist as far as this one is concerned
            ->get()->getRowArray()
            ?? throw new InvalidArgumentException('That record does not exist.');

        $same = ($row['amount'] === null ? null : number_format((float) $row['amount'], 2, '.', '')) === $payment['amount']
            && $row['currency'] === $payment['currency']
            && $row['reference'] === $payment['reference']
            && $row['notes'] === $payment['notes'];

        if ($same) {
            throw new InvalidArgumentException('Nothing to correct: those details are the same as the record.');
        }

        $term = [
            'plan_id'      => (int) $row['plan_id'],
            'period_start' => $row['period_start'],
            'period_end'   => $row['period_end'],
        ] + $payment;

        $rowId = $this->addRow((int) $row['subscription_id'], SubscriptionRenewalModel::CORRECTION, $renewalId, $term, $actor);

        $this->auditedChange(
            fn () => $this->removeRow($rowId),
            'subscription.corrected',
            $actor,
            'subscription_renewal',
            $renewalId,
            $institutionId,
            [
                'old' => ['amount' => $row['amount'], 'currency' => $row['currency'], 'reference' => $row['reference'], 'notes' => $row['notes']],
                'new' => $payment,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function institution(int $institutionId): array
    {
        return (new InstitutionModel())->find($institutionId)
            ?? throw new InvalidArgumentException('That institution does not exist.');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function plan(int $planId): array
    {
        return (new PlanModel())->find($planId) ?? throw new InvalidArgumentException('That plan does not exist.');
    }

    /**
     * Adds a row to the history.
     *
     * @param array{plan_id: int, period_start: string, period_end: string, amount: ?string, currency: string, reference: ?string, notes: ?string} $term
     *
     * @return int the new row's id
     */
    private function addRow(int $subscriptionId, string $kind, ?int $corrects, array $term, ?User $actor): int
    {
        $by    = $actor ?? auth()->user();
        $model = new SubscriptionRenewalModel();

        $id = $model->insert([
            'subscription_id'     => $subscriptionId,
            'kind'                => $kind,
            'corrects_renewal_id' => $corrects,
            'plan_id'             => $term['plan_id'],
            'period_start'        => $term['period_start'],
            'period_end'          => $term['period_end'],
            'amount'              => $term['amount'],
            'currency'            => $term['currency'],
            'reference'           => $term['reference'],
            'notes'               => $term['notes'],
            'recorded_by_user_id' => $by?->id,
            'recorded_by_label'   => $by?->email ?: $by?->username,
            'recorded_at'         => date('Y-m-d H:i:s'),
        ]);

        if ($id === false) {
            throw new RuntimeException('The record could not be saved.');
        }

        return (int) $id;
    }

    /**
     * Takes back a row that was added a moment ago, because the change it
     * belonged to could not be recorded. This is the one place a history row
     * is removed, and only before the change has been committed to the log;
     * it goes straight to the table because the model refuses to.
     */
    private function removeRow(int $rowId): void
    {
        db_connect()->table('subscription_renewals')->where('id', $rowId)->delete();
    }

    /**
     * Runs several writes as one: all happen or none do. Every write in $work
     * checks its own result and throws, which rolls the whole lot back.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inTransaction(callable $work)
    {
        $db = db_connect();

        $db->transBegin();

        try {
            $result = $work();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $db->transCommit();

        return $result;
    }
}
