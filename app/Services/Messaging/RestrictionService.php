<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Notifications\NotificationService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use DateTimeImmutable;

/**
 * Restrictions on sending (FR6): an administrator's `no_new` (no new conversations, groups or members) or
 * `no_send`, and the automatic pause (a `no_new` row with source 'pause'). One active admin restriction per person.
 */
class RestrictionService
{
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * The person's current restriction row, or null. An elapsed row is closed here (end_reason 'expired') and audited
     * as a system action.
     *
     * @return array<string, mixed>|null
     */
    public function active(int $userId, ?DateTimeImmutable $now = null): ?array
    {
        $now  ??= new DateTimeImmutable();
        $db     = db_connect();
        $stamp  = $now->format(self::FORMAT);
        $rows   = $this->openRows($userId);
        $live   = null;

        foreach ($rows as $row) {
            if ($row['ends_at'] !== null && $row['ends_at'] <= $stamp) {
                // Another request may have closed it first: only the one that did audits.
                $db->table('message_restrictions')->where('id', $row['id'])->where('ended_at', null)->update(['ended_at' => $stamp, 'end_reason' => 'expired']);

                if ($db->affectedRows() < 1) {
                    continue;
                }

                service('auditLogger')->log($row['source'] === 'pause' ? 'messages.pause_expired' : 'messages.restriction_expired', null, [
                    'target_type' => 'user', 'target_id' => $userId, 'institution_id' => (int) $row['institution_id'], 'restriction_id' => (int) $row['id'],
                ]);

                continue;
            }

            $live ??= $row;
        }

        return $live;
    }

    /** @return list<array<string, mixed>> the person's rows not yet ended, newest first */
    protected function openRows(int $userId): array
    {
        return db_connect()->table('message_restrictions')->where('user_id', $userId)->where('ended_at', null)->orderBy('id', 'DESC')->get()->getResultArray();
    }

    /**
     * The automatic pause (D8): three different strangers reporting this person's first-contact direct messages
     * within 24 hours. Strangers share no active offering with them. Counted reports are tagged with the pause so
     * they never count twice. Never for an institutional administrator or someone already restricted.
     *
     * @return int|null the new pause's id
     */
    public function evaluatePause(int $reportedUserId, ?DateTimeImmutable $now = null): ?int
    {
        $now ??= new DateTimeImmutable();
        $db    = db_connect();
        $rows  = $db->table('message_reports r')->select('r.id, r.reporter_id, r.institution_id')
            ->join('messages m', 'm.id = r.message_id')->join('conversations c', 'c.id = m.conversation_id')
            ->where('r.pause_id', null)->where('r.created_at >', $now->modify('-24 hours')->format(self::FORMAT))
            ->where('m.sender_id', $reportedUserId)->where('c.type', 'direct')->where('c.first_contact', 1)->where('c.created_by', $reportedUserId)
            ->get()->getResultArray();

        $resolver = new MessagingContextResolver();
        $mine     = $resolver->activeOfferingIds($reportedUserId);
        $strangers = [];

        foreach (array_unique(array_map('intval', array_column($rows, 'reporter_id'))) as $reporter) {
            $strangers[$reporter] = array_intersect($resolver->activeOfferingIds($reporter), $mine) === [];
        }

        $counted = array_values(array_filter($rows, static fn (array $r): bool => $strangers[(int) $r['reporter_id']]));

        if (count(array_unique(array_column($counted, 'reporter_id'))) < 3) {
            return null;
        }

        $user = model(UserModel::class)->findById($reportedUserId);

        if ($user === null || in_array('institutional_admin', $resolver->roles($user), true) || $this->active($reportedUserId, $now) !== null) {
            return null;
        }

        $institution = (int) $counted[0]['institution_id'];
        $stamp       = $now->format(self::FORMAT);
        $ids   = array_map('intval', array_column($counted, 'id'));
        $db->transStart();

        // Reports that arrive together queue on the person's row; the one behind sees the pause the first started.
        $this->lockPerson($reportedUserId);

        if ($this->liveLocked($reportedUserId, $stamp) !== []) {
            $db->transRollback();

            return null;
        }

        $db->table('message_restrictions')->insert([
            'institution_id' => $institution, 'user_id' => $reportedUserId, 'kind' => 'no_new', 'source' => 'pause',
            'starts_at' => $stamp, 'ends_at' => $now->modify('+72 hours')->format(self::FORMAT), 'created_at' => $stamp,
        ]);
        $id = (int) $db->insertID();
        $db->table('message_reports')->whereIn('id', $ids)->where('pause_id', null)->update(['pause_id' => $id]);

        if ($db->affectedRows() < count($ids)) {
            $db->transRollback();

            return null;
        }

        if (! $db->transComplete()) {
            return null;
        }

        service('auditLogger')->log('messages.pause_started', null, [
            'target_type' => 'user', 'target_id' => $reportedUserId, 'institution_id' => $institution, 'restriction_id' => $id, 'reports' => count($counted),
        ]);

        $admins = array_values(array_filter(array_map('intval', array_column($db->table('institution_users iu')->select('u.id')
            ->join('users u', 'u.id = iu.user_id AND u.deleted_at IS NULL AND u.active = 1', 'inner', false)
            ->join('auth_groups_users g', "g.user_id = u.id AND g.`group` = 'institutional_admin'", 'inner', false)
            ->where('iu.institution_id', $institution)->get()->getResultArray(), 'id')), static fn (int $admin): bool => $resolver->accountActive($admin)));

        (new NotificationService())->sendToMany($admins, 'message_pause', 'A person was paused from starting conversations', 'Several reports in a day paused someone from starting new conversations for 72 hours. Review the reports.', 'message_pause:' . $id, $institution, 'messages/reports', $now);

        return $id;
    }

    /** 'none', 'no_new' or 'no_send', as the policy reads it. */
    public function kindFor(int $userId): string
    {
        return (string) ($this->active($userId)['kind'] ?? 'none');
    }

    /** Places a restriction, replacing the administrator's earlier one. False unless $admin administers the person's institution. */
    public function restrict(User $admin, int $userId, string $kind, ?DateTimeImmutable $until): bool
    {
        $institution = $this->sharedInstitution($admin, $userId);
        $now         = new DateTimeImmutable();

        if ($institution === null || ! in_array($kind, ['no_new', 'no_send'], true) || ($until !== null && $until <= $now)) {
            return false;
        }

        $stamp = $now->format(self::FORMAT);
        $db    = db_connect();
        $db->transStart();
        $this->lockPerson($userId); // two administrators at once take turns, so the second replaces the first
        $db->table('message_restrictions')->where('user_id', $userId)->where('source', 'admin')->where('ended_at', null)
            ->update(['ended_at' => $stamp, 'ended_by' => (int) $admin->id, 'end_reason' => 'replaced']);
        // The administrator's decision replaces any automatic pause.
        $pauses = array_column($db->table('message_restrictions')->select('id')->where('user_id', $userId)->where('source', 'pause')->where('ended_at', null)->where('ends_at >', $stamp)->get()->getResultArray(), 'id');
        $pauses === [] || $db->table('message_restrictions')->whereIn('id', $pauses)->update(['ended_at' => $stamp, 'ended_by' => (int) $admin->id, 'end_reason' => 'restricted']);
        $db->table('message_restrictions')->insert([
            'institution_id' => $institution, 'user_id' => $userId, 'kind' => $kind, 'source' => 'admin',
            'starts_at' => $stamp, 'ends_at' => $until?->format(self::FORMAT), 'created_by' => (int) $admin->id, 'created_at' => $stamp,
        ]);
        $id = (int) $db->insertID();
        $db->transComplete();

        if (! $db->transStatus()) {
            return false;
        }

        foreach ($pauses as $pauseId) {
            service('auditLogger')->log('messages.pause_ended', $admin, [
                'target_type' => 'user', 'target_id' => $userId, 'institution_id' => $institution, 'restriction_id' => (int) $pauseId, 'end_reason' => 'restricted',
            ]);
        }

        service('auditLogger')->log('messages.restriction_placed', $admin, [
            'target_type' => 'user', 'target_id' => $userId, 'institution_id' => $institution, 'restriction_id' => $id, 'kind' => $kind,
        ]);

        return true;
    }

    /** Ends every live administrator restriction of the person. False when there is none or $admin may not. */
    public function lift(User $admin, int $userId): bool
    {
        $institution = $this->sharedInstitution($admin, $userId);

        if ($institution === null) {
            return false;
        }

        $this->active($userId); // closes an elapsed row first, with its own audit

        $db = db_connect();
        $db->transStart();
        $this->lockPerson($userId);
        $ids = array_map('intval', array_column($db->query('SELECT id FROM message_restrictions WHERE user_id = ? AND source = ? AND ended_at IS NULL FOR UPDATE', [$userId, 'admin'])->getResultArray(), 'id'));

        if ($ids === []) {
            $db->transRollback();

            return false;
        }

        $db->table('message_restrictions')->whereIn('id', $ids)->update(['ended_at' => date(self::FORMAT), 'ended_by' => (int) $admin->id, 'end_reason' => 'lifted']);

        if (! $db->transComplete()) {
            return false;
        }

        foreach ($ids as $id) {
            service('auditLogger')->log('messages.restriction_lifted', $admin, [
                'target_type' => 'user', 'target_id' => $userId, 'institution_id' => $institution, 'restriction_id' => $id,
            ]);
        }

        return true;
    }

    /** Whether $admin is the institutional administrator of $userId's institution (and not $userId). */
    public function administers(User $admin, int $userId): bool
    {
        return $this->sharedInstitution($admin, $userId) !== null;
    }

    /** Takes the person's row for the rest of the transaction, so restriction changes for one person run one at a time. */
    private function lockPerson(int $userId): void
    {
        db_connect()->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
    }

    /**
     * The person's restriction rows still running at $stamp, read with a lock (the latest committed state, not the
     * transaction's snapshot).
     *
     * @return list<array<string, mixed>>
     */
    private function liveLocked(int $userId, string $stamp): array
    {
        return db_connect()->query('SELECT id FROM message_restrictions WHERE user_id = ? AND ended_at IS NULL AND (ends_at IS NULL OR ends_at > ?) FOR UPDATE', [$userId, $stamp])->getResultArray();
    }

    /** The institution when $admin is its institutional administrator and $userId (not the admin) belongs to it. */
    private function sharedInstitution(User $admin, int $userId): ?int
    {
        $resolver    = new MessagingContextResolver();
        $institution = $resolver->institutionOf($admin);

        if ($institution === null || (int) $admin->id === $userId || ! in_array('institutional_admin', $resolver->roles($admin), true)) {
            return null;
        }

        $target = model(UserModel::class)->findById($userId);

        return $target !== null && $resolver->institutionOf($target) === $institution ? $institution : null;
    }
}
