<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\Users\AccountControl;
use App\Services\Users\PasswordResetService;
use CodeIgniter\Shield\Models\UserModel;
use Config\Email as EmailConfig;
use DateTimeImmutable;
use Throwable;

/**
 * Tells people by email what an upload, an import, an enrolment or an assignment did to them: that they have an
 * account and how to choose a password, that they were enrolled, that they teach a section, that they lead a
 * department.
 *
 * It works in two halves, so that nothing is sent for a change that did not happen:
 *
 *  - tell() adds a row to `mail_outbox` in the same transaction as the change. A preview (rolled back), a refused
 *    change and a failed run leave no row, so tell nobody. An upload the administrator chose not to email, and an
 *    institution's first import from its SIS, are muted for the run (context()).
 *  - sendDue(), run every minute by exports:run, makes ONE email per person out of all the rows that are due for
 *    them (so six enrolments are one email, not six), sends it, and marks the rows sent. A row waits MERGE_SECONDS
 *    first, so changes made one after another by hand merge too. A failed send is tried again later, up to
 *    MAX_ATTEMPTS times, and never loses the change itself.
 *
 * A new account's email carries a link to choose its password, made when the email is sent (so its time starts
 * then). Anyone else gets the news by email and in the app. Deactivated and deleted accounts are never emailed.
 */
class MailOutbox
{
    public const WELCOME     = 'welcome';
    public const ENROLLED    = 'enrolled';
    public const TEACHING    = 'teaching';
    public const DEAN        = 'dean';
    public const CHAIRPERSON = 'chairperson';

    public const KINDS = [self::WELCOME, self::ENROLLED, self::TEACHING, self::DEAN, self::CHAIRPERSON];

    /** How long a row waits so that changes made one after another make one email. */
    public const MERGE_SECONDS = 120;

    /** How long before a failed send is tried again. */
    public const RETRY_SECONDS = 900;

    public const MAX_ATTEMPTS = 5;

    /** People emailed per run of the job; the rest wait for the next minute. */
    public const PEOPLE_PER_RUN = 50;

    /** Lines of one kind listed in an email; the rest are counted. */
    private const MAX_LISTED = 15;

    private static bool $muted = false;

    private static ?string $batch = null;

    /**
     * Sets what tell() does until reset(): the batch rows are marked with (so a preview can count them), and
     * whether to write none at all.
     */
    public static function context(?string $batch, bool $muted): void
    {
        self::$batch = $batch;
        self::$muted = $muted;
    }

    public static function reset(): void
    {
        self::$batch = null;
        self::$muted = false;
    }

    public static function isMuted(): bool
    {
        return self::$muted;
    }

    /**
     * Notes that a person is to be told something. Never fails the change that calls it.
     *
     * @param string $detail what it is about, in a few words ("IT101 Intro to Computing, section A")
     *
     * @return bool whether a row was written
     */
    public function tell(int $institutionId, int $userId, string $kind, string $detail = '', ?DateTimeImmutable $now = null): bool
    {
        if (self::$muted || ! in_array($kind, self::KINDS, true)) {
            return false;
        }

        $now   ??= new DateTimeImmutable();
        $detail = mb_substr(trim($detail), 0, 255);

        try {
            $db = db_connect();

            // The same news, still waiting, is not told twice.
            $waiting = $db->table('mail_outbox')
                ->where(['institution_id' => $institutionId, 'user_id' => $userId, 'kind' => $kind, 'detail' => $detail])
                ->where('sent_at IS NULL', null, false)
                ->countAllResults();

            if ($waiting > 0) {
                return false;
            }

            $db->table('mail_outbox')->insert([
                'institution_id' => $institutionId,
                'user_id'        => $userId,
                'kind'           => $kind,
                'detail'         => $detail,
                'batch'          => self::$batch,
                'send_after'     => $now->modify('+' . self::MERGE_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
                'created_at'     => $now->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (Throwable $e) {
            log_message('error', 'Mail outbox: could not note {kind} for user {user}: {message}', ['kind' => $kind, 'user' => $userId, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /** An offering in a few words for an email: "IT101 Intro to Computing, section A". */
    public static function offeringLabel(int $offeringId): string
    {
        $row = db_connect()->table('course_offerings o')->select('o.section_code, c.course_code, c.course_title')
            ->join('courses c', 'c.id = o.course_id')->where('o.id', $offeringId)->get()->getRowArray();

        return $row === null ? '' : "{$row['course_code']} {$row['course_title']}, section {$row['section_code']}";
    }

    /**
     * Who a batch of rows will email, for a preview.
     *
     * @return array{people: int, welcome: int, notices: int}
     */
    public function plan(string $batch): array
    {
        $rows = db_connect()->table('mail_outbox')->select('user_id, kind')->where('batch', $batch)->get()->getResultArray();

        $people  = [];
        $welcome = [];

        foreach ($rows as $r) {
            $people[(int) $r['user_id']] = true;

            if ($r['kind'] === self::WELCOME) {
                $welcome[(int) $r['user_id']] = true;
            }
        }

        return ['people' => count($people), 'welcome' => count($welcome), 'notices' => count($people) - count($welcome)];
    }

    /**
     * Sends what is due: one email per person.
     *
     * @return int how many emails went out
     */
    public function sendDue(?DateTimeImmutable $now = null, int $limit = self::PEOPLE_PER_RUN): int
    {
        $now ??= new DateTimeImmutable();

        $people = db_connect()->table('mail_outbox')
            ->select('institution_id, user_id')
            ->where('sent_at IS NULL', null, false)
            ->where('attempts <', self::MAX_ATTEMPTS)
            ->where('send_after <=', $now->format('Y-m-d H:i:s'))
            ->groupBy('institution_id, user_id')
            ->orderBy('MIN(id)', 'ASC', false)
            ->limit($limit)
            ->get()->getResultArray();

        $sent = 0;

        foreach ($people as $person) {
            if ($this->sendTo((int) $person['institution_id'], (int) $person['user_id'], $now)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Rows still waiting to go, for the system health page and tests. */
    public function waiting(): int
    {
        return db_connect()->table('mail_outbox')->where('sent_at IS NULL', null, false)->where('attempts <', self::MAX_ATTEMPTS)->countAllResults();
    }

    /*
    |--------------------------------------------------------------------------
    | One person's email
    |--------------------------------------------------------------------------
    */

    private function sendTo(int $institutionId, int $userId, DateTimeImmutable $now): bool
    {
        $db    = db_connect();
        $stamp = $now->format('Y-m-d H:i:s');

        $rows = $db->table('mail_outbox')
            ->where(['institution_id' => $institutionId, 'user_id' => $userId])
            ->where('sent_at IS NULL', null, false)
            ->where('attempts <', self::MAX_ATTEMPTS)
            ->where('send_after <=', $stamp)
            ->orderBy('id')
            ->get()->getResultArray();

        if ($rows === []) {
            return false;
        }

        $ids         = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $user        = model(UserModel::class)->findById($userId);
        $institution = $db->table('institutions')->where('id', $institutionId)->where('deleted_at IS NULL', null, false)->get()->getRowArray();

        $active = (int) $db->table('users')->select('active')->where('id', $userId)->get()->getRow('active');

        // Someone who is gone, deactivated or without an address is not emailed, and is not asked about again.
        $skip = match (true) {
            $user === null || $institution === null                          => 'the account or the institution is gone',
            (string) $user->email === ''                                      => 'the account has no email address',
            $active !== 1 || (new AccountControl())->isDeactivated($userId) => 'the account is deactivated',
            default                                                           => null,
        };

        if ($skip !== null) {
            $db->table('mail_outbox')->whereIn('id', $ids)->update(['sent_at' => $stamp, 'last_error' => mb_substr('Not sent: ' . $skip . '.', 0, 255)]);

            return false;
        }

        $profile = $db->table('user_profiles')->select('first_name')->where('user_id', $userId)->get()->getRowArray();
        $by      = [];

        foreach ($rows as $r) {
            $by[$r['kind']][] = (string) $r['detail'];
        }

        $welcomeId = null;
        $link      = null;
        $expires   = null;

        try {
            if (isset($by[self::WELCOME])) {
                $issued    = (new PasswordResetService())->welcomeLink($userId, $now);
                $welcomeId = $issued['id'];
                $link      = $issued['url'];
                $expires   = $issued['expires'];
            }

            $name    = (string) $institution['name'];
            $subject = isset($by[self::WELCOME]) ? "Your Mentora account at {$name}" : "Updates to your Mentora account at {$name}";
            $body    = $this->message((string) ($profile['first_name'] ?? ''), $name, (string) $user->email, $by, $link, $expires);

            $config = config(EmailConfig::class);
            $email  = service('email');

            $email->clear();
            $email->setFrom($config->fromEmail, $config->fromName);
            $email->setTo((string) $user->email);
            $email->setSubject($subject);
            $email->setMessage($body);

            if ($email->send() !== true) {
                throw new \RuntimeException('The mail server did not accept the message.');
            }
        } catch (Throwable $e) {
            log_message('error', 'Mail outbox: email to user {user} failed: {message}', ['user' => $userId, 'message' => $e->getMessage()]);

            $db->query('UPDATE mail_outbox SET attempts = attempts + 1, last_error = ?, send_after = ? WHERE id IN (' . implode(',', $ids) . ')', [
                mb_substr($e->getMessage(), 0, 255),
                $now->modify('+' . self::RETRY_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
            ]);

            return false;
        }

        $db->table('mail_outbox')->whereIn('id', $ids)->update(['sent_at' => $stamp, 'last_error' => null]);

        if ($welcomeId !== null) {
            (new PasswordResetService())->sentAt($welcomeId, $now);
        }

        // Someone who already has an account also finds it in the app. (A new one has not signed in yet.)
        if (! isset($by[self::WELCOME])) {
            $this->notify($userId, $institutionId, (string) $institution['name'], $by, min($ids), $now);
        }

        return true;
    }

    /**
     * @param array<string, list<string>> $by kind => details
     */
    private function message(string $first, string $institution, string $email, array $by, ?string $link, ?string $expires): string
    {
        $out = ($first !== '' ? "Hello {$first}," : 'Hello,') . "\n\n";

        if (isset($by[self::WELCOME])) {
            $role = $by[self::WELCOME][0] !== '' ? ' as ' . $this->withArticle($by[self::WELCOME][0]) : '';

            $out .= "You have been added to {$institution} on Mentora{$role}.\n\n"
                . "Your sign-in email is {$email}. Choose your password with this link:\n{$link}\n\n"
                . 'The link works once and expires on ' . substr((string) $expires, 0, 16) . ". If it has expired, ask your administrator to send you a new one.\n\n";
        } else {
            $out .= "Here is what changed for you at {$institution}.\n\n";
        }

        $out .= $this->list('You are enrolled in', $by[self::ENROLLED] ?? []);
        $out .= $this->list('You are assigned to teach', $by[self::TEACHING] ?? []);

        foreach ($by[self::DEAN] ?? [] as $department) {
            $out .= "You are now the dean of {$department}.\n\n";
        }

        foreach ($by[self::CHAIRPERSON] ?? [] as $sub) {
            $out .= "You are now the chairperson of {$sub}.\n\n";
        }

        $out .= isset($by[self::WELCOME])
            ? "If you were not expecting this, you can ignore this email: nothing happens unless the link is used.\n"
            : 'Sign in at ' . site_url('login') . " to see it.\n";

        return $out;
    }

    /** @param list<string> $items */
    private function list(string $lead, array $items): string
    {
        $items = array_values(array_filter($items, static fn (string $i): bool => $i !== ''));

        if ($items === []) {
            return '';
        }

        $shown = array_slice($items, 0, self::MAX_LISTED);
        $out   = $lead . ":\n" . implode("\n", array_map(static fn (string $i): string => ' - ' . $i, $shown)) . "\n";

        if (count($items) > count($shown)) {
            $out .= ' - and ' . (count($items) - count($shown)) . " more\n";
        }

        return $out . "\n";
    }

    private function withArticle(string $role): string
    {
        return (in_array($role[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ') . $role;
    }

    /**
     * @param array<string, list<string>> $by
     */
    private function notify(int $userId, int $institutionId, string $institution, array $by, int $firstId, DateTimeImmutable $now): void
    {
        $lines = [];

        foreach ($by[self::ENROLLED] ?? [] as $d) {
            $lines[] = "Enrolled in {$d}.";
        }

        foreach ($by[self::TEACHING] ?? [] as $d) {
            $lines[] = "Assigned to teach {$d}.";
        }

        foreach ($by[self::DEAN] ?? [] as $d) {
            $lines[] = "Now the dean of {$d}.";
        }

        foreach ($by[self::CHAIRPERSON] ?? [] as $d) {
            $lines[] = "Now the chairperson of {$d}.";
        }

        if ($lines === []) {
            return;
        }

        $body = implode(' ', array_slice($lines, 0, 5)) . (count($lines) > 5 ? ' And ' . (count($lines) - 5) . ' more.' : '');

        try {
            (new NotificationService())->send($userId, 'account_update', "Updates to your account at {$institution}", $body, false, "mail-outbox:{$firstId}", $institutionId, null, $now);
        } catch (Throwable $e) {
            log_message('error', 'Mail outbox: notification for user {user} failed: {message}', ['user' => $userId, 'message' => $e->getMessage()]);
        }
    }
}
