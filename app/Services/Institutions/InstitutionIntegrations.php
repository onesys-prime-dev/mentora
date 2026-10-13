<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Sis\SisConnectionService;
use App\Services\Sis\SisConnectors;
use App\Services\Sis\SisGradeExporter;
use App\Services\Sis\SisImporter;
use App\Services\Sis\SisRunLog;
use CodeIgniter\Shield\Entities\User;
use InvalidArgumentException;

/**
 * What the institution's administrator may see of, and do with, its student
 * information system connection: whether it is connected, the history of what
 * ran, a preview and a run of the import, and a preview and a send of final
 * grades. The connection itself (its settings, folders and credentials) stays
 * with the system administrator, so none of that is ever returned from here.
 *
 * The runs are the same services the system administrator uses, with their
 * rules (the feature on, the connection saved and enabled, a preview before
 * the first import, an active institution), so there is one behaviour.
 */
class InstitutionIntegrations
{
    /** The most history lines shown. */
    public const HISTORY = 20;

    /**
     * The connection as the administrator may see it.
     *
     * @return array{available: bool, configured: bool, enabled: bool, label: string, runs: list<array<string, mixed>>, latest: ?array<string, mixed>}
     */
    public function view(int $institutionId): array
    {
        $connection = (new SisConnectionService())->view($institutionId);
        $type       = SisConnectors::types()[$connection['connector']] ?? ['label' => 'Student information system'];

        $runs = [];

        foreach ((new SisRunLog())->history($institutionId, self::HISTORY * 2) as $run) {
            // A connection test is the system administrator's: its message names the folders.
            if ($run['trigger'] === SisRunLog::TEST) {
                continue;
            }

            $runs[] = [
                'started_at'    => (string) $run['started_at'],
                'direction'     => (string) $run['direction'],
                'trigger'       => (string) $run['trigger'],
                'status'        => (string) $run['status'],
                'dry_run'       => (int) $run['dry_run'] === 1,
                'created'       => (int) $run['created_count'],
                'updated'       => (int) $run['updated_count'],
                'skipped'       => (int) $run['skipped_count'],
                'failed'        => (int) $run['failed_count'],
                'message'       => $this->safe((string) ($run['message'] ?? ''), $institutionId),
                'details'       => $run['details'],
            ];

            if (count($runs) >= self::HISTORY) {
                break;
            }
        }

        return [
            'available'  => (bool) $connection['flagOn'],
            'configured' => (bool) $connection['configured'],
            'enabled'    => (bool) $connection['enabled'],
            'label'      => (string) $type['label'],
            'runs'       => $runs,
            'latest'     => $this->latestImport($runs, $institutionId),
        ];
    }

    /**
     * A dry run of the import, or a real one.
     *
     * @return array{ok: bool, message: string}
     *
     * @throws InvalidArgumentException saying why it is not allowed right now
     */
    public function import(int $institutionId, bool $dryRun, ?User $actor = null): array
    {
        $result = (new SisImporter())->run($institutionId, $actor, $dryRun, SisRunLog::MANUAL);

        return ['ok' => $result->ok, 'message' => $this->safe($result->message, $institutionId)];
    }

    /**
     * What sending final grades would deliver, or sending them.
     *
     * @return array{ok: bool, message: string}
     *
     * @throws InvalidArgumentException saying why it is not allowed right now
     */
    public function grades(int $institutionId, bool $dryRun, ?User $actor = null): array
    {
        $result = (new SisGradeExporter())->run($institutionId, $actor, $dryRun, SisRunLog::MANUAL);

        return ['ok' => $result->ok, 'message' => $this->safe($result->message, $institutionId)];
    }

    /**
     * The newest import (preview or real), with the rows that were refused.
     *
     * @param list<array<string, mixed>> $runs newest first
     *
     * @return array{dry_run: bool, message: string, issues: list<array<string, string>>, truncated: bool}|null
     */
    private function latestImport(array $runs, int $institutionId): ?array
    {
        foreach ($runs as $run) {
            if ($run['direction'] !== SisRunLog::INBOUND || $run['status'] === 'running') {
                continue;
            }

            $details = json_decode((string) $run['details'], true);
            $issues  = [];

            foreach (is_array($details) ? ($details['issues'] ?? []) : [] as $issue) {
                $issues[] = [
                    'dataset' => (string) ($issue['dataset'] ?? ''),
                    'item'    => (string) ($issue['item'] ?? ''),
                    'reason'  => $this->safe((string) ($issue['reason'] ?? ''), $institutionId),
                ];
            }

            return [
                'dry_run'   => $run['dry_run'],
                'message'   => $run['message'],
                'issues'    => $issues,
                'truncated' => is_array($details) && ($details['truncated'] ?? false) === true,
            ];
        }

        return null;
    }

    /**
     * Text from a run with the connection's folders taken out, in case a
     * message ever names where the files are.
     */
    private function safe(string $text, int $institutionId): string
    {
        $folders = SisConnectors::folders($institutionId);

        foreach ([$folders['inbound'], $folders['outbound'], SisConnectors::baseFolder()] as $path) {
            if ($path !== '') {
                $text = str_replace([$path, str_replace('\\', '/', $path)], 'the shared folder', $text);
            }
        }

        return $text;
    }
}
