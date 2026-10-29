<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Academic\InstitutionModel;
use App\Services\Admin\PlatformSettings;
use App\Services\Audit\RecordsAuditedChanges;
use DateTimeImmutable;
use Throwable;

/**
 * Moves institutions along their subscription's timeline (SA13).
 *
 * sync() is the daily job: an institution whose subscription has run into its
 * read-only period becomes `read_only`, and one past that becomes `past`. It
 * only ever moves an institution DOWN that ladder (active, read_only, past).
 * It never lifts one, so an administrator's manual suspension is not undone
 * by the next run; lifting happens only when a renewal is recorded, in
 * statusAfterRenewal(). A `pending` institution (onboarding not finished) is
 * left alone, and so is one with no subscription.
 *
 * The timeline (warning, grace, read-only days) is the platform setting,
 * unless the institution has its own.
 */
class SubscriptionLifecycle
{
    use RecordsAuditedChanges;

    public const RANK = [
        InstitutionModel::ACTIVE    => 1,
        InstitutionModel::READ_ONLY => 2,
        InstitutionModel::PAST      => 3,
    ];

    /** The subscription's place on the timeline for the given day. */
    public function statusOf(string $endDate, int $institutionId, DateTimeImmutable $today): SubscriptionStatus
    {
        $settings = new PlatformSettings();

        return SubscriptionStatus::at(
            $endDate,
            $today,
            (int) $settings->get('expiryWarningDays', $institutionId),
            (int) $settings->get('expiryGraceDays', $institutionId),
            (int) $settings->get('expiryReadOnlyDays', $institutionId),
        );
    }

    /**
     * Applies the timeline to every institution that has a subscription.
     *
     * @return array{changed: list<array{id: int, from: string, to: string, state: string}>, failed: array<int, string>}
     */
    public function sync(DateTimeImmutable $now, bool $dryRun = false): array
    {
        $rows = db_connect()->table('subscriptions')
            ->select('subscriptions.end_date, institutions.id, institutions.status')
            ->join('institutions', 'institutions.id = subscriptions.institution_id')
            ->where('institutions.deleted_at IS NULL', null, false)
            ->whereIn('institutions.status', [InstitutionModel::ACTIVE, InstitutionModel::READ_ONLY])
            ->orderBy('institutions.id')
            ->get()->getResultArray();

        $result = ['changed' => [], 'failed' => []];

        foreach ($rows as $row) {
            $id     = (int) $row['id'];
            $from   = (string) $row['status'];
            $status = $this->statusOf((string) $row['end_date'], $id, $now);
            $to     = $status->institutionStatus();

            // Only ever down the ladder.
            if (self::RANK[$to] <= self::RANK[$from]) {
                continue;
            }

            if (! $dryRun) {
                try {
                    $this->move($id, $from, $to, $status);
                } catch (Throwable $e) {
                    $result['failed'][$id] = $e->getMessage();

                    continue;
                }
            }

            $result['changed'][] = ['id' => $id, 'from' => $from, 'to' => $to, 'state' => $status->state];
        }

        return $result;
    }

    /**
     * The status a renewal should leave the institution in, or null to leave
     * it alone. A renewal reactivates a read-only or past institution (as far
     * as its new end date allows); a pending one stays pending until
     * onboarding finishes.
     */
    public function statusAfterRenewal(int $institutionId, string $currentStatus, string $newEndDate, DateTimeImmutable $today): ?string
    {
        if (! isset(self::RANK[$currentStatus])) {
            return null;
        }

        $target = $this->statusOf($newEndDate, $institutionId, $today)->institutionStatus();

        return $target !== $currentStatus ? $target : null;
    }

    /**
     * @throws Throwable whatever stopped the change or its audit entry; the change is undone
     */
    private function move(int $institutionId, string $from, string $to, SubscriptionStatus $status): void
    {
        $model = new InstitutionModel();
        $model->update($institutionId, ['status' => $to]);

        $this->auditedChange(
            static fn () => (new InstitutionModel())->update($institutionId, ['status' => $from]),
            'institution.status_changed',
            null,
            'institution',
            $institutionId,
            $institutionId,
            [
                'old' => ['status' => $from],
                'new' => ['status' => $to, 'reason' => 'subscription ' . $status->state, 'subscription_ended' => $status->endDate],
            ],
            ['actor_label' => 'system (subscription check)'],
        );
    }
}
