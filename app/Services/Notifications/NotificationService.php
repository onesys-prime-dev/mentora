<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Config\Email as EmailConfig;
use DateTimeImmutable;
use Throwable;

/**
 * Tells people things (SA15): an in-app notification, and for an urgent one
 * an email as well. Telling is idempotent: the same dedupe key for the same
 * person is only ever stored once, so a trigger can run as often as it likes.
 * A failed email never fails the notification; it stays in-app.
 */
class NotificationService
{
    /**
     * @return bool true when it was new (and so stored), false when this person already had it
     */
    public function send(int $userId, string $type, string $title, string $body, bool $urgent, string $dedupeKey, ?int $institutionId = null, ?string $link = null, ?DateTimeImmutable $now = null): bool
    {
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $db    = db_connect();

        $exists = $db->table('notifications')->where(['user_id' => $userId, 'dedupe_key' => $dedupeKey])->countAllResults() > 0;

        if ($exists) {
            return false;
        }

        $db->table('notifications')->insert([
            'user_id'        => $userId,
            'institution_id' => $institutionId,
            'type'           => $type,
            'title'          => $title,
            'body'           => $body,
            'link'           => $link,
            'urgent'         => $urgent ? 1 : 0,
            'dedupe_key'     => $dedupeKey,
            'created_at'     => $stamp,
        ]);

        if ($urgent) {
            $id = (int) $db->insertID();

            if ($this->email($userId, $title, $body, $link)) {
                $db->table('notifications')->where('id', $id)->update(['emailed_at' => $stamp]);
            }
        }

        return true;
    }

    /** Whether this person has already been told this (the same dedupe key), without telling them. */
    public function has(int $userId, string $dedupeKey): bool
    {
        return db_connect()->table('notifications')->where(['user_id' => $userId, 'dedupe_key' => $dedupeKey])->countAllResults() > 0;
    }

    /**
     * Tells many people the same thing at once (an announcement to a whole department): one in-app
     * notification each, never an email, and idempotent like send(): someone who already has this
     * dedupe key is skipped. Done in a few queries however many people there are.
     *
     * @param list<int> $userIds
     *
     * @return int how many were new (and so stored)
     */
    public function sendToMany(array $userIds, string $type, string $title, string $body, string $dedupeKey, ?int $institutionId = null, ?string $link = null, ?DateTimeImmutable $now = null): int
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));

        if ($userIds === []) {
            return 0;
        }

        $stamp  = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $db     = db_connect();
        $stored = 0;

        foreach (array_chunk($userIds, 500) as $chunk) {
            $have = array_map('intval', array_column($db->table('notifications')->select('user_id')->where('dedupe_key', $dedupeKey)->whereIn('user_id', $chunk)->get()->getResultArray(), 'user_id'));
            $new  = array_values(array_diff($chunk, $have));

            if ($new === []) {
                continue;
            }

            $db->table('notifications')->insertBatch(array_map(static fn (int $userId): array => [
                'user_id'        => $userId,
                'institution_id' => $institutionId,
                'type'           => $type,
                'title'          => $title,
                'body'           => $body,
                'link'           => $link,
                'urgent'         => 0,
                'dedupe_key'     => $dedupeKey,
                'created_at'     => $stamp,
            ], $new));

            $stored += count($new);
        }

        return $stored;
    }

    public function unreadCount(int $userId): int
    {
        return db_connect()->table('notifications')->where('user_id', $userId)->where('read_at IS NULL', null, false)->countAllResults();
    }

    /**
     * One batch of a person's notifications for the bell's panel, newest
     * first. Pass the id of the last row already shown to get the next batch.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function page(int $userId, ?int $beforeId = null, int $limit = 7): array
    {
        $limit = max(1, min(50, $limit));

        $builder = db_connect()->table('notifications')->where('user_id', $userId);

        if ($beforeId !== null && $beforeId > 0) {
            $builder->where('id <', $beforeId);
        }

        // One extra row tells whether another batch exists.
        $rows = $builder->orderBy('id', 'desc')->limit($limit + 1)->get()->getResultArray();

        $hasMore = count($rows) > $limit;

        return ['rows' => array_slice($rows, 0, $limit), 'has_more' => $hasMore];
    }

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $userId, int $limit = 50): array
    {
        return db_connect()->table('notifications')->where('user_id', $userId)->orderBy('id', 'desc')->limit($limit)->get()->getResultArray();
    }

    /**
     * Marks one (or, with null, every) unread notification of the person's as read.
     *
     * @return int how many were marked
     */
    public function markRead(int $userId, ?int $id = null, ?DateTimeImmutable $now = null): int
    {
        $builder = db_connect()->table('notifications')->where('user_id', $userId)->where('read_at IS NULL', null, false);

        if ($id !== null) {
            $builder->where('id', $id);
        }

        $builder->update(['read_at' => ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s')]);

        return db_connect()->affectedRows();
    }

    private function email(int $userId, string $title, string $body, ?string $link): bool
    {
        $to = db_connect()->table('auth_identities')->select('secret')->where(['user_id' => $userId, 'type' => 'email_password'])->get()->getRowArray()['secret'] ?? null;

        if ($to === null) {
            return false;
        }

        try {
            $config = config(EmailConfig::class);
            $email  = service('email');

            $email->clear();
            $email->setFrom($config->fromEmail, $config->fromName);
            $email->setTo($to);
            $email->setSubject('Mentora: ' . $title);
            $email->setMessage($body . ($link !== null ? "\n\n" . site_url($link) : '') . "\n");

            return $email->send() === true;
        } catch (Throwable $e) {
            log_message('error', 'Notification email to {email} failed: {message}', ['email' => $to, 'message' => $e->getMessage()]);

            return false;
        }
    }
}
