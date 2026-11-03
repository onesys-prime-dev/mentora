<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;

/**
 * The institutional administrator's view of reports (spec 3.3 rule 8, 5.2).
 * It is the only place chat text is readable outside the conversation itself,
 * and only for reports of the administrator's own institution. Reviewing also
 * gives the administrator their one way to blank a reported message in a course
 * chat or a group; a direct message is never moderated, so a report of one can
 * only be dismissed or marked as actioned. Audit entries hold ids and the
 * outcome, never text.
 */
class ReportReviewService
{
    public const STATUSES = ['open', 'dismissed', 'actioned'];

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
     * @return list<array{id: int, reason: string, status: string, created_at: string, conversation_type: string, conversation_name: string, reporter_name: string}>
     */
    public function list(User $admin, string $status = 'open'): array
    {
        $institution = $this->institutionOf($admin);

        if ($institution === null || ! in_array($status, self::STATUSES, true)) {
            return [];
        }

        $rows = db_connect()->table('message_reports r')
            ->select('r.id, r.reason, r.status, r.created_at, r.reporter_id, c.type, c.title')
            ->join('messages m', 'm.id = r.message_id')->join('conversations c', 'c.id = m.conversation_id')
            ->where('r.institution_id', $institution)->where('r.status', $status)->orderBy('r.id', 'DESC')->limit(200)->get()->getResultArray();

        $names = ConversationPresenter::names(array_map('intval', array_column($rows, 'reporter_id')));

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'reason' => (string) $r['reason'], 'status' => (string) $r['status'], 'created_at' => (string) $r['created_at'],
            'conversation_type' => (string) $r['type'],
            'conversation_name' => $r['type'] === 'group' ? (string) $r['title'] : ($r['type'] === 'course' ? 'Course chat' : 'Direct message'),
            'reporter_name'     => $names[(int) $r['reporter_id']] ?? 'Former member',
        ], $rows);
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

        return [
            'id'                => (int) $report['id'],
            'reason'            => (string) $report['reason'],
            'note'              => $note,
            'status'            => (string) $report['status'],
            'created_at'        => (string) $report['created_at'],
            'conversation_type' => (string) $report['type'],
            'snapshot'          => is_array($snapshot) && is_array($snapshot['messages'] ?? null) ? $snapshot['messages'] : [],
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
            $db->table('messages')->where(['id' => (int) $report['message_id'], 'removed_at' => null])->update(['removed_at' => $now, 'removed_by' => (int) $admin->id]);
        }

        $db->table('message_reports')->where('id', $reportId)->update(['status' => $status, 'reviewed_by' => (int) $admin->id, 'reviewed_at' => $now]);
        $db->transComplete();

        if (! $db->transStatus()) {
            return false;
        }

        $institution = (int) $report['institution_id'];

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
            && $this->policy->canModerate('institutional_admin', (string) $report['type'], false);
    }

    private function institutionOf(User $admin): ?int
    {
        return $this->resolver->activeRole($admin) === 'institutional_admin' ? $this->resolver->institutionOf($admin) : null;
    }
}
