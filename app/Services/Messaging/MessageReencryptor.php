<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Moves everything Messages has encrypted onto the current key (spec 5.1
 * "Rotation"): message bodies, report snapshots and report notes. A value that
 * already opens with the current key is left byte for byte as it is; one that
 * only a previous key opens is decrypted and encrypted again with the same
 * binding (conversation and sender or reporter); one nothing opens is counted
 * and its row id reported, never its content. In batches of BATCH rows, and a
 * dry run only counts.
 */
class MessageReencryptor
{
    public const BATCH = 200;

    private const REPORTED_IDS = 20;

    private MessageCipher $cipher;

    public function __construct(?MessageCipher $cipher = null)
    {
        $this->cipher = $cipher ?? new MessageCipher();
    }

    /**
     * @return array{rewritten: int, current: int, unreadable: int, unreadable_ids: list<string>}
     */
    public function run(bool $dryRun): array
    {
        $totals = ['rewritten' => 0, 'current' => 0, 'unreadable' => 0, 'unreadable_ids' => []];
        $db     = db_connect();

        // Message bodies.
        $this->each('messages', 'id, conversation_id, sender_id, body_enc', false, function (array $row) use (&$totals, $db, $dryRun): void {
            $this->handle($totals, $db, $dryRun, 'messages', (int) $row['id'], 'body_enc', (string) $row['body_enc'], (int) $row['conversation_id'], (int) $row['sender_id'], 'message');
        });

        // Report snapshots and notes: bound to the reporter and the reported message's conversation.
        $this->each('message_reports r', 'r.id, r.reporter_id, r.snapshot_enc, r.note_enc, m.conversation_id', true, function (array $row) use (&$totals, $db, $dryRun): void {
            foreach (['snapshot_enc', 'note_enc'] as $column) {
                if ($row[$column] !== null && $row[$column] !== '') {
                    $this->handle($totals, $db, $dryRun, 'message_reports', (int) $row['id'], $column, (string) $row[$column], (int) $row['conversation_id'], (int) $row['reporter_id'], 'report');
                }
            }
        });

        return $totals;
    }

    /**
     * @param array{rewritten: int, current: int, unreadable: int, unreadable_ids: list<string>} $totals
     */
    private function handle(array &$totals, \CodeIgniter\Database\BaseConnection $db, bool $dryRun, string $table, int $id, string $column, string $stored, int $conversationId, int $boundTo, string $label): void
    {
        if ($this->cipher->usesCurrentKey($stored)) {
            $totals['current']++;

            return;
        }

        $plain = $this->cipher->decrypt($stored, $conversationId, $boundTo);

        if ($plain === null) {
            $totals['unreadable']++;

            if (count($totals['unreadable_ids']) < self::REPORTED_IDS) {
                $totals['unreadable_ids'][] = $label . ' ' . $id;
            }

            return;
        }

        if (! $dryRun) {
            $db->table($table)->where('id', $id)->update([$column => $this->cipher->encrypt($plain, $conversationId, $boundTo)]);
        }

        $totals['rewritten']++;
    }

    /**
     * Walks a table by id in batches; `$withMessage` joins each report to the message it is about.
     *
     * @param callable(array<string, mixed>): void $each
     */
    private function each(string $table, string $columns, bool $withMessage, callable $each): void
    {
        $alias = str_contains($table, ' ') ? explode(' ', $table)[1] : $table;
        $db    = db_connect();
        $after = 0;

        do {
            $query = $db->table($table)->select($columns);

            if ($withMessage) {
                $query->join('messages m', 'm.id = r.message_id');
            }

            $rows = $query->where("{$alias}.id >", $after)->orderBy("{$alias}.id")->limit(self::BATCH)->get()->getResultArray();

            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $each($row);
            }
        } while (count($rows) === self::BATCH);
    }
}
