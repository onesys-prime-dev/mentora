<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Profiles\AvatarData;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * The institutional administrator's view of reports (spec 3.3 rule 8, 5.2).
 * It is the only place chat text is readable outside the conversation itself,
 * and only for reports of the administrator's own institution. Reviewing also
 * gives the administrator their one way to blank a reported message in a course
 * chat, a group or a direct message (its members then read "Message removed by
 * an administrator"). Audit entries hold ids and the
 * outcome, never text.
 *
 * An official screen, so people are named by their legal name as the rest of the
 * moderation UI does; only the avatars beside the names go through
 * AvatarData::forViewer (photo and card only for people the admin may see).
 */
class ReportReviewService
{
    public const STATUSES = ['open', 'dismissed', 'actioned'];

    private const ROLE_ORDER = ['institutional_admin', 'dean', 'chairperson', 'instructor', 'student', 'parent'];

    private MessageCipher $cipher;

    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    public function __construct(?MessageCipher $cipher = null, ?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null)
    {
        $this->cipher   = $cipher ?? new MessageCipher();
        $this->resolver = $resolver ?? new MessagingContextResolver();
        $this->policy   = $policy ?? new MessagingPolicy();
    }

    /**
     * Reports of the administrator's institution, newest first. No message text and no note.
     *
     * @return list<array{id: int, reason: string, status: string, created_at: string, conversation_type: string, reported_user_id: int, conversation_name: string, reporter_name: string, reporter_avatar: array{person: array<string, mixed>, interactive: bool}|null}>
     */
    public function list(User $admin, string $status = 'open'): array
    {
        $institution = $this->institutionOf($admin);

        if ($institution === null || ! in_array($status, self::STATUSES, true)) {
            return [];
        }

        $rows = db_connect()->table('message_reports r')
            ->select('r.id, r.reason, r.status, r.created_at, r.reporter_id, m.sender_id, c.type, c.title')
            ->join('messages m', 'm.id = r.message_id')->join('conversations c', 'c.id = m.conversation_id')
            ->where('r.institution_id', $institution)->where('r.status', $status)->orderBy('r.id', 'DESC')->limit(200)->get()->getResultArray();

        $ids     = array_values(array_unique(array_map('intval', array_column($rows, 'reporter_id'))));
        $names   = ConversationPresenter::names($ids);
        $avatars = (new AvatarData())->forViewer($admin, $ids);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'reason' => (string) $r['reason'], 'status' => (string) $r['status'], 'created_at' => (string) $r['created_at'],
            'conversation_type' => (string) $r['type'], 'reported_user_id' => (int) $r['sender_id'],
            'conversation_name' => $r['type'] === 'group' ? (string) $r['title'] : ($r['type'] === 'course' ? 'Course chat' : 'Direct message'),
            'reporter_name'     => $names[(int) $r['reporter_id']] ?? 'Former member',
            'reporter_avatar'   => $avatars[(int) $r['reporter_id']] ?? null,
        ], $rows);
    }

    /**
     * The same reports grouped by the person reported (one case per person, the person with the newest report first),
     * with their automatic pause and administrator restriction when running. No message text.
     *
     * @return list<array{user_id: int, name: string, role: string, avatar: array{person: array<string, mixed>, interactive: bool}|null, reports: list<array<string, mixed>>, pause: array{ends_at: string}|null, restriction: array{kind: string, ends_at: ?string}|null}>
     */
    public function cases(User $admin, string $status = 'open'): array
    {
        $grouped = [];

        foreach ($this->list($admin, $status) as $row) {
            $grouped[$row['reported_user_id']][] = $row;
        }

        $ids     = array_keys($grouped);
        $names   = ConversationPresenter::names($ids);
        $avatars = (new AvatarData())->forViewer($admin, $ids);
        $cases   = [];

        foreach ($grouped as $userId => $reports) {
            (new RestrictionService())->active($userId); // closes an elapsed row first

            $live = db_connect()->table('message_restrictions')->where('user_id', $userId)->where('ended_at', null)->orderBy('id', 'DESC')->get()->getResultArray();
            $pause = array_values(array_filter($live, static fn (array $r): bool => $r['source'] === 'pause'))[0] ?? null;
            $mine  = array_values(array_filter($live, static fn (array $r): bool => $r['source'] === 'admin'))[0] ?? null;
            $user  = model(UserModel::class)->findById($userId);
            $roles = $user === null ? [] : $this->resolver->roles($user);

            $cases[] = [
                'user_id' => $userId, 'name' => $names[$userId] ?? 'Former member',
                'role'    => array_values(array_intersect(self::ROLE_ORDER, $roles))[0] ?? ($roles[0] ?? 'member'),
                'avatar'  => $avatars[$userId] ?? null, 'reports' => $reports,
                'pause'   => $pause === null ? null : ['ends_at' => (string) $pause['ends_at']],
                'restriction' => $mine === null ? null : ['kind' => (string) $mine['kind'], 'ends_at' => $mine['ends_at']],
            ];
        }

        return $cases;
    }

    /**
     * One report with its snapshot, or null for anyone who is not this institution's administrator.
     *
     * @return array{id: int, reason: string, note: ?string, status: string, created_at: string, conversation_type: string, snapshot: list<array<string, mixed>>, reported_id: int, removed: bool, can_remove: bool}|null
     */
    public function view(User $admin, int $reportId): ?array
    {
        $report = $this->find($admin, $reportId);

        if ($report === null) {
            return null;
        }

        $conversationId = (int) $report['conversation_id'];
        $reporterId     = (int) $report['reporter_id'];
        $decoded        = $this->cipher->decrypt((string) $report['snapshot_enc'], $conversationId, $reporterId);
        $snapshot       = $decoded === null ? null : json_decode($decoded, true);
        $note           = $report['note_enc'] === null ? null : $this->cipher->decrypt((string) $report['note_enc'], $conversationId, $reporterId);
        $removed        = $report['removed_at'] !== null;
        $lines          = is_array($snapshot) && is_array($snapshot['messages'] ?? null) ? $snapshot['messages'] : [];
        $avatars        = (new AvatarData())->forViewer($admin, array_values(array_unique(array_map(static fn ($l): int => (int) ($l['sender_id'] ?? 0), $lines))));

        foreach ($lines as &$line) {
            $line['avatar'] = $avatars[(int) ($line['sender_id'] ?? 0)] ?? null;
        }
        unset($line);

        return [
            'id'                => (int) $report['id'],
            'reason'            => (string) $report['reason'],
            'note'              => $note,
            'status'            => (string) $report['status'],
            'created_at'        => (string) $report['created_at'],
            'conversation_type' => (string) $report['type'],
            'snapshot'          => $lines,
            'reported_id'       => (int) $report['message_id'],
            'removed'           => $removed,
            'can_remove'        => $this->canRemove($report),
        ];
    }

    /**
     * Dismisses or actions an open report, and blanks the reported message when asked and allowed. False for
     * anyone else, an unknown report, one already reviewed, or a status that is not a decision.
     */
    public function review(User $admin, int $reportId, string $status, bool $removeMessage): bool
    {
        $report = $this->find($admin, $reportId);

        if ($report === null || $report['status'] !== 'open' || ! in_array($status, ['dismissed', 'actioned'], true)) {
            return false;
        }

        $db      = db_connect();
        $now     = date('Y-m-d H:i:s');
        $removed = $removeMessage && $this->canRemove($report);

        $db->transStart();

        if ($removed) {
            $db->table('messages')->where(['id' => (int) $report['message_id'], 'removed_at' => null])->update(['removed_at' => $now, 'removed_by' => (int) $admin->id, 'changed_at' => $now]);
        }

        // Only an open report can be decided: of two simultaneous decisions the second changes nothing.
        $db->table('message_reports')->where(['id' => $reportId, 'status' => 'open'])->update(['status' => $status, 'reviewed_by' => (int) $admin->id, 'reviewed_at' => $now]);
        $decided = $db->affectedRows() > 0;

        if (! $decided) {
            $db->transRollback();

            return false;
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            return false;
        }

        $institution = (int) $report['institution_id'];

        $this->endPauseIfReviewed($admin, $report['pause_id'] === null ? null : (int) $report['pause_id'], $institution);

        service('auditLogger')->log('messages.report_reviewed', $admin, [
            'target_type' => 'message_report', 'target_id' => $reportId, 'institution_id' => $institution,
            'new'         => ['status' => $status, 'removed' => $removed],
        ]);

        if ($removed) {
            service('auditLogger')->log('messages.message_removed', $admin, [
                'target_type' => 'message', 'target_id' => (int) $report['message_id'], 'institution_id' => $institution,
                'new'         => ['conversation_id' => (int) $report['conversation_id'], 'report_id' => $reportId],
            ]);
        }

        return true;
    }

    /**
     * Decides every open report of one person at once: 'dismiss' dismisses them, 'remove' removes the reported messages
     * and marks them actioned. Returns how many were decided.
     */
    public function reviewAll(User $admin, int $userId, string $decision): int
    {
        $institution = $this->institutionOf($admin);

        if ($institution === null || ! in_array($decision, ['dismiss', 'remove'], true)) {
            return 0;
        }

        $ids = array_column(db_connect()->table('message_reports r')->select('r.id')->join('messages m', 'm.id = r.message_id')
            ->where('r.institution_id', $institution)->where('r.status', 'open')->where('m.sender_id', $userId)->orderBy('r.id')->get()->getResultArray(), 'id');
        $done = 0;

        foreach ($ids as $id) {
            $done += (int) $this->review($admin, (int) $id, $decision === 'dismiss' ? 'dismissed' : 'actioned', $decision === 'remove');
        }

        return $done;
    }

    /** Ends the automatic pause once none of the reports it counted is open. A pause that already ended or ran out is left alone. */
    private function endPauseIfReviewed(User $admin, ?int $pauseId, int $institution): void
    {
        if ($pauseId === null || db_connect()->table('message_reports')->where(['pause_id' => $pauseId, 'status' => 'open'])->countAllResults() > 0) {
            return;
        }

        $db   = db_connect();
        $now  = date('Y-m-d H:i:s');
        $row  = $db->table('message_restrictions')->where('id', $pauseId)->get()->getRowArray();
        $db->table('message_restrictions')->where('id', $pauseId)->where('ended_at', null)->where('ends_at >', $now)
            ->update(['ended_at' => $now, 'ended_by' => (int) $admin->id, 'end_reason' => 'reviewed']);

        if ($row !== null && $db->affectedRows() > 0) {
            service('auditLogger')->log('messages.pause_ended', $admin, [
                'target_type' => 'user', 'target_id' => (int) $row['user_id'], 'institution_id' => $institution, 'restriction_id' => $pauseId, 'end_reason' => 'reviewed',
            ]);
        }
    }

    /**
     * The report row with its conversation, only for the administrator of its institution.
     *
     * @return array<string, mixed>|null
     */
    private function find(User $admin, int $reportId): ?array
    {
        $institution = $this->institutionOf($admin);

        if ($institution === null || $reportId < 1) {
            return null;
        }

        return db_connect()->table('message_reports r')
            ->select('r.*, m.conversation_id, m.removed_at, c.type')
            ->join('messages m', 'm.id = r.message_id')->join('conversations c', 'c.id = m.conversation_id')
            ->where('r.id', $reportId)->where('r.institution_id', $institution)->get()->getRowArray();
    }

    /** @param array<string, mixed> $report a row from find() */
    private function canRemove(array $report): bool
    {
        return $report['status'] === 'open'
            && $report['removed_at'] === null
            && ($report['type'] === 'direct' || $this->policy->canModerate(new Party(0, ['institutional_admin'], true, null), (string) $report['type'], false));
    }

    private function institutionOf(User $admin): ?int
    {
        return in_array('institutional_admin', $this->resolver->roles($admin), true) ? $this->resolver->institutionOf($admin) : null;
    }
}
