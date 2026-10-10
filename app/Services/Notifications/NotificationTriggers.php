<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Academic\InstitutionModel;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Services\Subscriptions\SubscriptionStatus;
use DateTimeImmutable;

/**
 * Works out what people should be told and tells them (SA15). It looks at the
 * state of every subscription and plan limit, and tells whoever has not yet
 * been told, so running it again changes nothing. It is run by
 * `subscriptions:sync` every day.
 *
 * Subscription: expiring soon (in-app), grace started (in-app), read-only
 * started and expired (urgent: also by email). Limits: 90% (in-app) and
 * 100% (urgent). System administrators hear about every institution; an
 * institution's own administrators hear about theirs.
 */
class NotificationTriggers
{
    private const LIMIT_NAMES = ['seats' => 'student seats', 'storage' => 'storage', 'ai' => 'AI generations'];

    /** @var list<int> system administrators, who get a link into the admin area */
    private array $admins = [];

    public function __construct(
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly NotificationRecipients $recipients = new NotificationRecipients(),
    ) {
    }

    /** @return int how many notifications were newly created */
    public function run(DateTimeImmutable $now): int
    {
        $created = 0;

        $rows = db_connect()->table('subscriptions')
            ->select('subscriptions.id, subscriptions.institution_id, subscriptions.end_date, institutions.name, institutions.status')
            ->join('institutions', 'institutions.id = subscriptions.institution_id')
            ->where('institutions.deleted_at IS NULL', null, false)
            ->where('institutions.status !=', InstitutionModel::PENDING)
            ->orderBy('subscriptions.id')
            ->get()->getResultArray();

        $lifecycle = new SubscriptionLifecycle();
        $limits    = new PlanLimits();

        foreach ($rows as $row) {
            $institutionId = (int) $row['institution_id'];
            $name          = (string) $row['name'];
            $admins        = $this->recipients->administrators();
            $audience      = array_values(array_unique(array_merge($admins, $this->recipients->institutionAdministrators($institutionId))));
            $this->admins  = $admins;

            $status = $lifecycle->statusOf((string) $row['end_date'], $institutionId, $now);

            $created += $this->subscription($status, (int) $row['id'], $institutionId, $name, $audience, $now);
            $created += $this->limits($limits->report($institutionId, $now), (string) $row['end_date'], $institutionId, $name, $audience, $now);
        }

        return $created;
    }

    /**
     * @param list<int> $audience
     */
    private function subscription(SubscriptionStatus $status, int $subscriptionId, int $institutionId, string $name, array $audience, DateTimeImmutable $now): int
    {
        $end = $status->endDate;

        [$type, $title, $body, $urgent] = match ($status->state) {
            SubscriptionStatus::EXPIRING  => ['subscription.expiring', "{$name}: subscription ends soon", "The subscription of {$name} ends on {$end}. Record a renewal to keep it running.", false],
            SubscriptionStatus::GRACE     => ['subscription.grace', "{$name}: grace period started", "The subscription of {$name} ended on {$end}. Full access continues until {$status->graceEnds}, then the institution becomes read-only.", false],
            SubscriptionStatus::READ_ONLY => ['subscription.read_only', "{$name} is now read-only", "The subscription of {$name} ended on {$end}. People can look and export but nothing can be changed until it is renewed.", true],
            SubscriptionStatus::EXPIRED   => ['subscription.expired', "{$name}: subscription expired", "The subscription of {$name} ended on {$end} and the institution is now past. A renewal brings it back.", true],
            default                       => [null, '', '', false],
        };

        if ($type === null) {
            return 0;
        }

        return $this->tell($audience, $type, $title, $body, $urgent, "sub:{$subscriptionId}:{$end}:{$status->state}", $institutionId, "admin/institutions/{$institutionId}/subscription", $now);
    }

    /**
     * @param array<string, mixed> $report PlanLimits::report()
     * @param list<int>            $audience
     */
    private function limits(array $report, string $periodKey, int $institutionId, string $name, array $audience, DateTimeImmutable $now): int
    {
        $created = 0;

        foreach (self::LIMIT_NAMES as $kind => $label) {
            $state = $report[$kind]['state'] ?? 'unlimited';

            if (! in_array($state, ['warning', 'full'], true)) {
                continue;
            }

            $full = $state === 'full';

            $created += $this->tell(
                $audience,
                'limit.' . ($full ? 'reached' : 'near'),
                $full ? "{$name}: {$label} limit reached" : "{$name}: {$label} nearly full",
                $full
                    ? "{$name} has used all of its {$label}. Anything that would add to it is refused until the plan changes."
                    : "{$name} has used 90% or more of its {$label}.",
                $full,
                "limit:{$institutionId}:{$kind}:{$state}:{$periodKey}",
                $institutionId,
                "admin/institutions/{$institutionId}/subscription",
                $now,
            );
        }

        return $created;
    }

    /**
     * @param list<int> $audience
     */
    private function tell(array $audience, string $type, string $title, string $body, bool $urgent, string $key, int $institutionId, string $link, DateTimeImmutable $now): int
    {
        $created = 0;

        foreach ($audience as $userId) {
            $to = in_array($userId, $this->admins, true) ? $link : null;

            if ($this->notifications->send($userId, $type, $title, $body, $urgent, $key, $institutionId, $to, $now)) {
                $created++;
            }
        }

        return $created;
    }
}
