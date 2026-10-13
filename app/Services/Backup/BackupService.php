<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Services\Admin\PlatformSettings;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Backups (SA11): the history, asking for one, and taking it.
 *
 * An administrator only ever *requests* a backup (a row, status `requested`);
 * the server's scheduled `php spark backup:run` takes it, because a dump does
 * not belong inside a web request. The same command takes the daily one when
 * none has succeeded recently. Every request and every outcome is recorded.
 */
class BackupService
{
    /** How many of the newest dump files are kept. */
    public const KEEP_FILES = 14;

    /** A scheduled backup is taken when none succeeded within this many hours. */
    public const SCHEDULE_EVERY_HOURS = 23;

    public function __construct(private readonly ?BackupRunner $runner = null)
    {
    }

    /** Where the dump files go: `backup.path` in .env, else writable/backups. */
    public static function folder(): string
    {
        $path = (string) env('backup.path', WRITEPATH . 'backups');

        return rtrim($path, '/\\');
    }

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 50): array
    {
        return db_connect()->table('backups')->orderBy('id', 'desc')->limit($limit)->get()->getResultArray();
    }

    /**
     * Asks for a backup now.
     *
     * @throws InvalidArgumentException when one is already waiting or running
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is saved then
     */
    public function request(User $actor, ?DateTimeImmutable $now = null): int
    {
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $db    = db_connect();

        if ($db->table('backups')->whereIn('status', ['requested', 'running'])->countAllResults() > 0) {
            throw new InvalidArgumentException('A backup is already waiting or running. Wait for it to finish.');
        }

        $db->transBegin();

        try {
            $db->table('backups')->insert([
                'status'               => 'requested',
                'trigger'              => 'manual',
                'requested_by_user_id' => $actor->id,
                'requested_by_label'   => $actor->email ?: $actor->username,
                'created_at'           => $stamp,
            ]);

            $id = (int) $db->insertID();

            service('auditLogger')->log('backup.requested', $actor, ['target_type' => 'backup', 'target_id' => $id]);
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $db->transCommit();

        return $id;
    }

    /**
     * What the server job does: takes every requested backup, and the daily
     * one when it is due.
     *
     * @return list<int> ids of the backups taken (successful or not)
     */
    public function runDue(?DateTimeImmutable $given = null): array
    {
        $now = $given ?? new DateTimeImmutable();
        $db   = db_connect();
        $ran  = [];

        foreach ($db->table('backups')->where('status', 'requested')->orderBy('id')->get()->getResultArray() as $row) {
            $ran[] = $this->take((int) $row['id'], $given);
        }

        if ($ran === [] && $db->table('backups')->where('status', 'running')->countAllResults() === 0 && $this->scheduledIsDue($now)) {
            $stamp = $now->format('Y-m-d H:i:s');

            $db->table('backups')->insert(['status' => 'requested', 'trigger' => 'scheduled', 'created_at' => $stamp]);

            $ran[] = $this->take((int) $db->insertID(), $given);
        }

        return $ran;
    }

    /** Takes one backup that was requested and records how it went. */
    public function take(int $id, ?DateTimeImmutable $given = null): int
    {
        $now = $given ?? new DateTimeImmutable();
        // The end is "now" again when the real clock is in use, so a long dump finishes later than it began.
        $end = static fn (): string => ($given ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $db   = db_connect();

        $name   = 'mentora-' . $now->format('Ymd-His') . '.sql.gz';
        $folder = self::folder();

        $db->table('backups')->where('id', $id)->update(['status' => 'running', 'started_at' => $now->format('Y-m-d H:i:s')]);

        try {
            if (! is_dir($folder) && ! @mkdir($folder, 0770, true) && ! is_dir($folder)) {
                throw new \RuntimeException('The backup folder could not be created.');
            }

            $path = $folder . DIRECTORY_SEPARATOR . $name;

            ($this->runner ?? service('backupRunner'))->dump($path);

            $size = is_file($path) ? (int) filesize($path) : 0;

            if ($size === 0) {
                throw new \RuntimeException('The backup file is empty.');
            }

            $db->table('backups')->where('id', $id)->update([
                'status' => 'succeeded', 'file_name' => $name, 'size_bytes' => $size, 'message' => null, 'finished_at' => $end(),
            ]);

            $this->prune($folder);
        } catch (Throwable $e) {
            log_message('error', 'Backup failed: ' . $e->getMessage());

            $db->table('backups')->where('id', $id)->update([
                'status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => $end(),
            ]);
        }

        return $id;
    }

    /**
     * The backup alert (SA11): what is wrong with the backups, or null when
     * they are fine. Failed or stale (nothing succeeded within the setting's
     * hours), or none ever.
     *
     * @return array{kind: string, message: string}|null
     */
    public function alert(?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable();

        $db   = db_connect();
        $last = $db->table('backups')->where('status', 'succeeded')->orderBy('finished_at', 'desc')->get()->getRowArray();
        $fail = $db->table('backups')->where('status', 'failed')->orderBy('finished_at', 'desc')->get()->getRowArray();

        return BackupStatus::assess(
            $last['finished_at'] ?? null,
            $fail['finished_at'] ?? null,
            $fail['message'] ?? null,
            $now,
            (int) (new PlatformSettings())->get('backupAlertHours'),
        );
    }

    private function scheduledIsDue(DateTimeImmutable $now): bool
    {
        $last = db_connect()->table('backups')->where('status', 'succeeded')->orderBy('finished_at', 'desc')->get()->getRowArray();

        return $last === null || strtotime((string) $last['finished_at']) <= $now->modify('-' . self::SCHEDULE_EVERY_HOURS . ' hours')->getTimestamp();
    }

    /** Keeps the newest KEEP_FILES dumps. */
    private function prune(string $folder): void
    {
        $files = glob($folder . DIRECTORY_SEPARATOR . 'mentora-*.sql.gz') ?: [];
        rsort($files);

        foreach (array_slice($files, self::KEEP_FILES) as $old) {
            @unlink($old);
        }
    }
}
