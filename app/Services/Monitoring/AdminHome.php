<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Services\Admin\PlatformSettings;
use App\Services\Audit\AuditLogFilters;
use App\Services\Audit\AuditLogSearch;
use App\Services\Backup\BackupService;
use DateTimeImmutable;

/**
 * What the administrator's home page shows (SA14): what needs attention, a
 * snapshot, and recent activity. It reuses the numbers the other admin pages
 * already work out, so the home page never disagrees with them.
 */
class AdminHome
{
    public const URGENT = 'urgent';

    public const WARNING = 'warning';

    /** @var array<string, array{status: string, detail: string}>|null */
    private ?array $healthResults = null;

    /** @param SystemHealth|null $health replaced in tests so no real disk or service is read */
    public function __construct(private readonly ?SystemHealth $health = null)
    {
    }

    /**
     * What needs attention, most urgent first.
     *
     * @return list<array{severity: string, text: string, link: string}>
     */
    public function needsAttention(DateTimeImmutable $now): array
    {
        $items = [];
        $usage = new UsageReport();

        foreach ($usage->subscriptionWatch($now) as $row) {
            $text = match ($row['state']) {
                'expiring'  => "{$row['name']}: subscription ends {$row['endDate']}.",
                'grace'     => "{$row['name']}: in its grace period (ended {$row['endDate']}).",
                'read_only' => "{$row['name']}: read-only since its subscription ended {$row['endDate']}.",
                default     => "{$row['name']}: subscription expired ({$row['endDate']}).",
            };

            $items[] = [
                'severity' => in_array($row['state'], ['read_only', 'expired'], true) ? self::URGENT : self::WARNING,
                'text'     => $text,
                'link'     => "admin/institutions/{$row['id']}/subscription",
            ];
        }

        foreach ($usage->institutions($now) as $row) {
            foreach (['seats' => 'student seats', 'storage' => 'storage', 'ai' => 'AI generations'] as $kind => $label) {
                $state = $row[$kind]['state'] ?? 'unlimited';

                if (in_array($state, ['warning', 'full'], true)) {
                    $items[] = [
                        'severity' => $state === 'full' ? self::URGENT : self::WARNING,
                        'text'     => $state === 'full' ? "{$row['name']}: {$label} limit reached." : "{$row['name']}: {$label} nearly full.",
                        'link'     => "admin/institutions/{$row['id']}/subscription",
                    ];
                }
            }
        }

        $backup = (new BackupService())->alert($now);

        if ($backup !== null) {
            $items[] = ['severity' => self::URGENT, 'text' => $backup['message'], 'link' => 'admin/backups'];
        }

        foreach ($this->healthResults() as $name => $result) {
            if ($name !== 'backups' && $result['status'] !== SystemHealth::UP) {
                $items[] = [
                    'severity' => $result['status'] === SystemHealth::DOWN ? self::URGENT : self::WARNING,
                    'text'     => SystemHealth::LABELS[$name] . ': ' . $result['detail'],
                    'link'     => 'admin/monitoring',
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => ($a['severity'] === self::URGENT ? 0 : 1) <=> ($b['severity'] === self::URGENT ? 0 : 1));

        return $items;
    }

    /**
     * One cell per platform check, for the strip at the top of the page. The
     * question-builder service is left out: it is optional and probed over the
     * network, so it stays on the status page.
     *
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function health(): array
    {
        $cells = [];

        foreach ($this->healthResults() as $name => $result) {
            if ($name !== 'ai') {
                $cells[] = ['key' => $name, 'label' => SystemHealth::LABELS[$name], 'status' => $result['status'], 'detail' => $result['detail']];
            }
        }

        return $cells;
    }

    /**
     * The checks run once per request, however many parts of the page read them.
     *
     * @return array<string, array{status: string, detail: string}>
     */
    private function healthResults(): array
    {
        // The question-builder service is optional and is probed over the network; it is left to the status page.
        return $this->healthResults ??= ($this->health ?? new SystemHealth(['ai' => static fn (): array => ['status' => SystemHealth::UP, 'detail' => '']]))->run();
    }

    /**
     * Institutions by status, and the people counts.
     *
     * @return array{byStatus: array<string, int>, institutions: int, users: int, signedInToday: int}
     */
    public function snapshot(DateTimeImmutable $now): array
    {
        $byStatus = ['pending' => 0, 'active' => 0, 'read_only' => 0, 'past' => 0];

        foreach (db_connect()->table('institutions')
            ->select('status, COUNT(*) AS total', false)
            ->where('deleted_at IS NULL', null, false)
            ->groupBy('status')
            ->get()->getResultArray() as $row) {
            $byStatus[(string) $row['status']] = (int) $row['total'];
        }

        $platform = (new UsageReport())->platform($now);

        return [
            'byStatus'      => $byStatus,
            'institutions'  => $platform['institutions'],
            'users'         => $platform['users'],
            'signedInToday' => $platform['signedInToday'],
        ];
    }

    /**
     * The latest audit entries.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(int $limit = 8): array
    {
        $showIp = (bool) (new PlatformSettings())->get('auditShowSourceIp');

        return (new AuditLogSearch($showIp))->page(AuditLogFilters::fromInput(['per_page' => (string) $limit]))['rows'];
    }
}
