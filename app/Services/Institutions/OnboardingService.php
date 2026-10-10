<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Academic\InstitutionModel;
use App\Services\Concerns\InTransaction;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Services\Subscriptions\SubscriptionService;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Finishes onboarding an institution (SA4).
 *
 * Onboarding is three steps: the institution (created `pending`), its plan
 * and first subscription, and its first administrator. Until the last step is
 * done the institution stays `pending` and nothing in it is usable: it has no
 * subscription and nobody can sign in to it. complete() does the second and
 * third steps' writes together, in one transaction: the subscription, the
 * invitation, and the move from `pending` to the status the subscription's
 * dates call for. If any of it fails, none of it happens.
 *
 * The invitation email goes out after that is saved (an email cannot be taken
 * back). If it fails the institution is still onboarded, the result says the
 * email did not go, and the invitation can be re-sent.
 *
 * Every refusal is an InvalidArgumentException whose message is fit to show.
 */
class OnboardingService
{
    use InTransaction;

    /**
     * Checks the subscription step while the person is still on it: the plan
     * must be one that can be chosen and the dates must make sense. Writes
     * nothing.
     *
     * @param array<string, mixed> $input a form post: plan_id, period_start, period_end, amount, currency, reference, notes
     *
     * @return array{plan_id: int, period_start: string, period_end: string, amount: ?string, currency: string, reference: ?string, notes: ?string}
     *
     * @throws InvalidArgumentException
     */
    public function checkSubscription(int $institutionId, array $input, ?DateTimeImmutable $now = null): array
    {
        $this->pending($institutionId);

        $term = (new SubscriptionService())->prepareStart($institutionId, $input)['term'];

        if ($term['period_end'] < ($now ?? new DateTimeImmutable())->format('Y-m-d')) {
            throw new InvalidArgumentException('A new customer cannot start with a subscription that has already ended. Choose an end date that is today or later.');
        }

        return $term;
    }

    /**
     * Starts the subscription, invites the administrator and activates the
     * institution, or does none of it.
     *
     * @param array<string, mixed> $subscription the subscription step's form post
     * @param array<string, mixed> $administrator first_name, last_name, email
     *
     * @return array{subscription_id: int, status: string, invitation_id: int, email: string, expires_at: string, email_error: ?string}
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever else stopped it; nothing was saved
     */
    public function complete(int $institutionId, array $subscription, array $administrator, ?User $actor = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        $institution = $this->pending($institutionId);
        $term        = $this->checkSubscription($institutionId, $subscription, $now);
        $person      = InvitationInput::person($administrator);

        $invitations = new InvitationService();

        $saved = $this->inTransaction(function () use ($institutionId, $institution, $subscription, $term, $person, $actor, $now, $invitations): array {
            $subscriptionId = (new SubscriptionService())->start($institutionId, $subscription, $actor);
            $issued         = $invitations->issue($institutionId, $person, $actor, $now, 'invitation.created');
            $status         = (new SubscriptionLifecycle())->statusOf($term['period_end'], $institutionId, $now)->institutionStatus();

            if (! (new InstitutionModel())->update($institutionId, ['status' => $status])) {
                throw new RuntimeException('The institution could not be activated.');
            }

            service('auditLogger')->log('institution.onboarded', $actor ?? auth()->user(), [
                'target_type'    => 'institution',
                'target_id'      => $institutionId,
                'institution_id' => $institutionId,
                'old'            => ['status' => $institution['status']],
                'new'            => ['status' => $status, 'subscription_id' => $subscriptionId, 'administrator' => $person['email']],
            ]);

            return ['subscription_id' => $subscriptionId, 'status' => $status, 'issued' => $issued];
        });

        $issued = $saved['issued'];

        return [
            'subscription_id' => $saved['subscription_id'],
            'status'          => $saved['status'],
            'invitation_id'   => $issued['id'],
            'email'           => $person['email'],
            'expires_at'      => $issued['expires_at'],
            'email_error'     => $invitations->deliver($issued['id'], $issued['token'], $now),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when there is no such institution, or it is not waiting for onboarding
     */
    private function pending(int $institutionId): array
    {
        $institution = (new InstitutionModel())->find($institutionId)
            ?? throw new InvalidArgumentException('That institution does not exist.');

        if ($institution['status'] !== InstitutionModel::PENDING) {
            throw new InvalidArgumentException('This institution has already been onboarded.');
        }

        return $institution;
    }
}
