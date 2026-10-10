<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Academic\InstitutionModel;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Subscriptions\SubscriptionLifecycle;
use DateTimeImmutable;

/**
 * The monitoring page's numbers about customers (SA9): usage against plan
 * limits per institution, the subscriptions that need watching, and a few
 * platform-wide counts.
 */
class UsageReport
{
    /**
     * One row per institution that is not deleted or pending, busiest seats first.
     *
     * @return list<array<string, mixed>> id, name, status, plan, activeUsers, seats/storage/ai lines (PlanLimits::report)
     */
    public function institutions(?DateTimeImmutable $now = null): array
    {
        $limits = new PlanLimits();
        $rows   = [];

        $people = [];

        foreach (db_connect()->table('institution_users')
            ->select('institution_users.institution_id, COUNT(DISTINCT users.id) AS people', false)
            ->join('users', 'users.id = institution_users.user_id')
            ->where('users.deleted_at IS NULL', null, false)
            ->where('users.active', 1)
            ->groupBy('institution_users.institution_id')
            ->get()->getResultArray() as $row) {
            $people[(int) $row['institution_id']] = (int) $row['people'];
        }

        foreach ((new InstitutionModel())->where('status !=', InstitutionModel::PENDING)->orderBy('name')->findAll() as $institution) {
            $id     = (int) $institution['id'];
            $report = $limits->report($id, $now);

            $rows[] = [
                'id'          => $id,
                'name'        => $institution['name'],
                'status'      => $institution['status'],
                'plan'        => $report['plan'],
                'activeUsers' => $people[$id] ?? 0,
                'seats'       => $report['seats'],
                'storage'     => $report['storage'],
                'ai'          => $report['ai'],
            ];
        }

        return $rows;
    }

    /**
     * Subscriptions in the warning window, grace or read-only, soonest end first.
     *
     * @return list<array{id: int, name: string, state: string, endDate: string, daysToEnd: int}>
     */
    public function subscriptionWatch(DateTimeImmutable $now): array
    {
        $lifecycle = new SubscriptionLifecycle();
        $watch     = [];

        $rows = db_connect()->table('subscriptions')
            ->select('subscriptions.end_date, institutions.id, institutions.name')
            ->join('institutions', 'institutions.id = subscriptions.institution_id')
            ->where('institutions.deleted_at IS NULL', null, false)
            ->where('institutions.status !=', InstitutionModel::PENDING)
            ->orderBy('subscriptions.end_date')
            ->get()->getResultArray();

        foreach ($rows as $row) {
            $status = $lifecycle->statusOf((string) $row['end_date'], (int) $row['id'], $now);

            if ($status->state === 'active') {
                continue;
            }

            $watch[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'state' => $status->state, 'endDate' => (string) $row['end_date'], 'daysToEnd' => $status->daysToEnd];
        }

        return $watch;
    }

    /**
     * Platform-wide counts.
     *
     * @return array{institutions: int, users: int, signedInToday: int, failedLogins24h: int, failedLogins7d: int, sessions: int}
     */
    public function platform(DateTimeImmutable $now): array
    {
        $db = db_connect();

        $failed = static fn (string $since): int => $db->table('auth_logins')->where('success', 0)->where('date >=', $since)->countAllResults();

        return [
            'institutions'    => $db->table('institutions')->where('deleted_at IS NULL', null, false)->countAllResults(),
            'users'           => $db->table('users')->where('deleted_at IS NULL', null, false)->where('active', 1)->countAllResults(),
            'signedInToday'   => $db->table('users')->where('last_active >=', $now->format('Y-m-d 00:00:00'))->countAllResults(),
            'failedLogins24h' => $failed($now->modify('-24 hours')->format('Y-m-d H:i:s')),
            'failedLogins7d'  => $failed($now->modify('-7 days')->format('Y-m-d H:i:s')),
            'sessions'        => $this->activeSessions($now),
        ];
    }

    /** Session files touched within the session lifetime (the file handler keeps one file per session). */
    private function activeSessions(DateTimeImmutable $now): int
    {
        $path     = rtrim((string) config('Session')->savePath, '/\\');
        $lifetime = (int) config('Session')->expiration;
        $count    = 0;

        foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && ! str_ends_with($file, 'index.html') && $now->getTimestamp() - (int) filemtime($file) <= $lifetime) {
                $count++;
            }
        }

        return $count;
    }
}
