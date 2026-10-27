<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Academic\InstitutionModel;
use App\Services\Backup\BackupService;
use App\Services\Institutions\InstitutionAttention;
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
 *
 * Only an institution's own administrators are told about its academic
 * calendar (IA13): a term ending soon, offerings with no instructor once the
 * term has started, and invitations about to lapse. A failed SIS run goes to
 * them as well as to the system administrators.
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
        $created = $this->backups($now);

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
            $created += $this->calendar($institutionId, $now);
        }

        return $created;
    }

    /** A failed or stale backup is told to the system administrators once per problem (urgent). */
    private function backups(DateTimeImmutable $now): int
    {
        $alert = (new BackupService())->alert($now);

        if ($alert === null) {
            return 0;
        }

        $this->admins = $this->recipients->administrators();

        // A failure shares backup:run's key (one per run of failures), so neither repeats the other.
        $key = $alert['kind'] === 'failed'
            ? BackupService::failureStreakKey()
            : 'backup:' . $alert['kind'] . ':' . (db_connect()->table('backups')->selectMax('finished_at')->where('status', 'succeeded')->get()->getRowArray()['finished_at'] ?? 'never');

        return $this->tell($this->admins, 'backup.' . $alert['kind'], 'Backups need attention', $alert['message'], true, $key, null, 'admin/backups', $now);
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
     * What the institution's administrators should know about its calendar and
     * invitations: each once, so running again changes nothing.
     */
    private function calendar(int $institutionId, DateTimeImmutable $now): int
    {
        $audience     = $this->recipients->institutionAdministrators($institutionId);
        $this->admins = [];

        if ($audience === []) {
            return 0;
        }

        $attention = new InstitutionAttention();
        $created   = 0;
        $term      = $attention->activeTerm($institutionId);
        $today     = $now->format('Y-m-d');

        if ($term !== null) {
            $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($term['end_date']))->format('%r%a');

            if ($days >= 0 && $days <= InstitutionAttention::TERM_DAYS) {
                $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days");

                $created += $this->tell($audience, 'term.ending', "{$term['name']} ends {$when}", 'Closing a term makes its offerings read-only and completes the enrolments still open. Do it when the grades are in.', false, "term-ending:{$term['id']}:{$term['end_date']}", $institutionId, '', $now, 'institution/terms');
            }

            // Once the term has started, whoever is still without an instructor is a problem to fix now.
            if ($term['start_date'] <= $today) {
                $unstaffed = $attention->offeringsWithoutInstructor($institutionId, $term['id']);

                if ($unstaffed > 0) {
                    $created += $this->tell($audience, 'term.no_instructor', $unstaffed === 1 ? '1 offering has no instructor' : "{$unstaffed} offerings have no instructor", "{$term['name']} has started. Nobody can teach or grade these offerings until an instructor is assigned.", false, "no-instructor:{$term['id']}", $institutionId, '', $now, 'institution/offerings?instructor=none&term=' . $term['id']);
                }
            }
        }

        $expiring = $attention->expiringInvitations($institutionId, $now);

        if ($expiring !== []) {
            $count = count($expiring);
            $key   = md5(implode(',', array_column($expiring, 'id')));

            $created += $this->tell($audience, 'invitation.expiring', $count === 1 ? '1 invitation is about to expire' : "{$count} invitations are about to expire", 'Their links stop working within ' . InstitutionAttention::INVITATION_DAYS . ' days. Send them again to give people more time.', false, "invites-expiring:{$institutionId}:{$key}", $institutionId, '', $now, 'institution/people?view=invitations');
        }

        return $created;
    }

    /**
     * A real SIS import or grade export failed (urgent: also by email). One
     * notification per failed run, so a retry that fails again is a new one.
     * The institution's own administrators are told as well as ours.
     *
     * @param string $what "import" or "grade export"
     */
    public function syncFailed(int $institutionId, string $name, string $message, int $runId, DateTimeImmutable $now, string $what = 'import'): int
    {
        $this->admins = $this->recipients->administrators();
        $audience     = array_values(array_unique(array_merge($this->admins, $this->recipients->institutionAdministrators($institutionId))));

        return $this->tell($audience, 'sis.sync_failed', "SIS {$what} failed for {$name}", $message, true, "sis:failed:{$runId}", $institutionId, "admin/institutions/{$institutionId}/integrations", $now, 'institution/integrations');
    }

    /**
     * @param list<int> $audience
     */
    private function tell(array $audience, string $type, string $title, string $body, bool $urgent, string $key, ?int $institutionId, string $link, DateTimeImmutable $now, ?string $institutionLink = null): int
    {
        $created = 0;

        foreach ($audience as $userId) {
            // The vendor's staff are sent into the admin area; an institution's people into their own.
            $to = in_array($userId, $this->admins, true) ? $link : $institutionLink;

            if ($this->notifications->send($userId, $type, $title, $body, $urgent, $key, $institutionId, $to, $now)) {
                $created++;
            }
        }

        return $created;
    }
}
