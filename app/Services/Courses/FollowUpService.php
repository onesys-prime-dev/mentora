<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Services\Audit\AuditLogger;
use App\Services\Auth\ActiveRoleService;
use App\Services\Notifications\NotificationService;
use CodeIgniter\Shield\Entities\User;

/**
 * Follow-up requests: one feature with two scopes. A dean asks the chairpersons and
 * instructors responsible for a section of their department to act on something that needs
 * attention (overdue grading, an inactive course, an at-risk student, a blocked sign-off); a
 * chairperson asks the instructors of the sections in their sub-departments. The person asked
 * sees it in their list, answers in a small thread and marks it done; the sender sees what is
 * open, overdue and done.
 *
 * A chairperson can pass a dean's request down to the instructor: that makes a request of
 * their own, linked to the dean's, which the dean still sees, and which closes the dean's when
 * the instructor is done. Who may be asked is always worked out live from the sender's reach
 * and the section's real assignments, never from what the form sent.
 */
class FollowUpService
{
    public const TABS = ['open', 'overdue', 'done'];

    public function __construct(private readonly NotificationService $notifications = new NotificationService())
    {
    }

    /** The role this person acts as when it is one that may send requests, else null. */
    public function senderRole(User $user): ?string
    {
        $role = (new ActiveRoleService())->resolve($user);

        return in_array($role, [FollowUpRules::DEAN, FollowUpRules::CHAIRPERSON], true) ? $role : null;
    }

    /**
     * Whom this sender may ask about a section: its instructors, and for a dean also its
     * chairpersons, and only where the section is within their reach. Never the sender.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    public function recipientsFor(User $sender, int $offeringId): array
    {
        $role = $this->senderRole($sender);

        if ($role === null) {
            return [];
        }

        $reaches = $role === FollowUpRules::DEAN
            ? (new DeanAccessService())->canAccessOffering($sender, $offeringId)
            : (new ChairpersonAccessService())->canAccess($sender, $offeringId);

        $section = $reaches ? $this->section($offeringId) : null;

        if ($section === null) {
            return [];
        }

        $db     = db_connect();
        $people = [];

        foreach ($db->table('instructor_assignments')->select('instructor_user_id AS id')->where('offering_id', $offeringId)->get()->getResultArray() as $row) {
            $people[(int) $row['id']] = FollowUpRules::INSTRUCTOR;
        }

        foreach ($db->table('chairperson_assignments')->select('chairperson_user_id AS id')->where(['sub_department_id' => $section['sub_department_id'], 'status' => 'active'])->get()->getResultArray() as $row) {
            // A person who is both the section's instructor and its chairperson is asked as the chairperson.
            $people[(int) $row['id']] = FollowUpRules::CHAIRPERSON;
        }

        unset($people[(int) $sender->id]);

        $people = array_filter($people, static fn (string $recipientRole): bool => FollowUpRules::mayAsk($role, $recipientRole));

        if ($people === []) {
            return [];
        }

        $names = $this->names(array_keys($people));
        $list  = [];

        foreach ($people as $id => $recipientRole) {
            $list[] = ['id' => $id, 'name' => $names[$id] ?? 'Unnamed', 'role' => $recipientRole];
        }

        usort($list, static fn (array $a, array $b): int => [$a['role'] === 'chairperson' ? 0 : 1, $a['name']] <=> [$b['role'] === 'chairperson' ? 0 : 1, $b['name']]);

        return $list;
    }

    /**
     * Sections this sender may ask about, for the course picker: a dean's whole department, a
     * chairperson's sub-departments.
     *
     * @return list<array{id: int, label: string}>
     */
    public function offeringsFor(User $sender): array
    {
        $role = $this->senderRole($sender);

        if ($role === null) {
            return [];
        }

        $builder = db_connect()->table('course_offerings co')
            ->select(['co.id', 'c.course_code', 'co.section_code', 'c.course_title'])
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->orderBy('c.course_code', 'ASC')->orderBy('co.section_code', 'ASC');

        if ($role === FollowUpRules::DEAN) {
            $ids = (new DeanAccessService())->programIds($sender);
        } else {
            $ids = (new MonitorProgramService())->chairedProgramIds($sender);
        }

        if ($ids === []) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'label' => trim($row['course_code'] . ' ' . $row['section_code']) . ' · ' . $row['course_title']],
            $builder->whereIn('p.id', $ids)->get()->getResultArray()
        );
    }

    /**
     * Sends a request.
     *
     * @param array{offering_id?: mixed, recipient_id?: mixed, student_id?: mixed, reason?: mixed, title?: mixed, message?: mixed, due_date?: mixed} $input
     *
     * @return array{success: bool, message: string, id?: int}
     */
    public function send(User $sender, array $input): array
    {
        $role = $this->senderRole($sender);

        if ($role === null) {
            return $this->refused('Only a dean or a chairperson can send a follow-up.');
        }

        $offeringId  = (int) ($input['offering_id'] ?? 0);
        $recipientId = (int) ($input['recipient_id'] ?? 0);
        $recipient   = null;

        foreach ($this->recipientsFor($sender, $offeringId) as $candidate) {
            if ($candidate['id'] === $recipientId) {
                $recipient = $candidate;
            }
        }

        if ($recipient === null) {
            return $this->refused('You cannot send a follow-up to that person about that course.');
        }

        $title   = trim((string) ($input['title'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $reason  = (string) ($input['reason'] ?? 'other');
        $due     = trim((string) ($input['due_date'] ?? ''));

        if ($title === '' || mb_strlen($title) > 200) {
            return $this->refused('Give the follow-up a subject of up to 200 characters.');
        }

        if ($message === '' || mb_strlen($message) > 2000) {
            return $this->refused('Write what you are asking, in up to 2000 characters.');
        }

        if ($due !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || strtotime($due) === false || $due < date('Y-m-d'))) {
            return $this->refused('The due date must be today or later.');
        }

        $studentId = (int) ($input['student_id'] ?? 0);

        if ($studentId > 0 && db_connect()->table('enrollments')->where(['offering_id' => $offeringId, 'student_user_id' => $studentId])->countAllResults() === 0) {
            return $this->refused('That student is not in this course.');
        }

        return $this->create($sender, $role, $recipient, [
            'offering_id' => $offeringId,
            'student_id'  => $studentId,
            'reason'      => array_key_exists($reason, FollowUpRules::REASONS) ? $reason : 'other',
            'title'       => $title,
            'message'     => $message,
            'due_date'    => $due === '' ? null : $due,
        ], null);
    }

    /**
     * A chairperson passes a dean's request down to an instructor of the section. The dean's stays open
     * until the instructor's is done, and the dean sees where it went.
     *
     * @return array{success: bool, message: string, id?: int}
     */
    public function passDown(User $chair, int $followUpId, int $instructorId, string $note = ''): array
    {
        $parent = $this->find($followUpId);

        if ($this->senderRole($chair) !== FollowUpRules::CHAIRPERSON || $parent === null
            || (int) $parent['recipient_id'] !== (int) $chair->id || $parent['sender_role'] !== FollowUpRules::DEAN) {
            return $this->refused('That request is not one you can pass on.');
        }

        if ($parent['status'] !== 'open') {
            return $this->refused('That request is already done.');
        }

        $instructor = null;

        foreach ($this->recipientsFor($chair, (int) $parent['offering_id']) as $candidate) {
            if ($candidate['id'] === $instructorId && $candidate['role'] === FollowUpRules::INSTRUCTOR) {
                $instructor = $candidate;
            }
        }

        if ($instructor === null) {
            return $this->refused('You can only pass it to an instructor of that course.');
        }

        $note   = trim($note);
        $result = $this->create($chair, FollowUpRules::CHAIRPERSON, $instructor, [
            'offering_id' => (int) $parent['offering_id'],
            'student_id'  => (int) $parent['student_id'],
            'reason'      => $parent['reason'],
            'title'       => $parent['title'],
            'message'     => $note !== '' ? $note . "\n\n— passed on from the dean's request:\n" . $parent['message'] : $parent['message'],
            'due_date'    => $parent['due_date'],
        ], (int) $parent['id']);

        if ($result['success']) {
            $this->addReply((int) $parent['id'], (int) $chair->id, 'Passed on to ' . $instructor['name'] . '.');
            $this->notifications->send((int) $parent['sender_id'], 'follow_up', 'Follow-up passed on', $this->nameOf((int) $chair->id) . ' passed on “' . $parent['title'] . '” to ' . $instructor['name'] . '.', false, "follow-up:{$parent['id']}:passed:{$result['id']}", (int) $parent['institution_id'], $this->linkFor('dean'));
        }

        return $result;
    }

    /** @return array{success: bool, message: string} */
    public function reply(User $user, int $followUpId, string $body): array
    {
        $row = $this->find($followUpId);

        if ($row === null || ! $this->takesPart($user, $row)) {
            return $this->refused('That request is not yours.');
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 2000) {
            return $this->refused('Write a reply of up to 2000 characters.');
        }

        $replyId = $this->addReply($followUpId, (int) $user->id, $body);

        $this->tellOther($user, $row, "follow-up:{$followUpId}:reply:{$replyId}", 'Reply to a follow-up', $this->nameOf((int) $user->id) . ' replied to “' . $row['title'] . '”.');

        return ['success' => true, 'message' => 'Reply sent.'];
    }

    /**
     * The person asked, or the one who asked, closes the request. When it was passed down from a dean's, the
     * dean's closes with it.
     *
     * @return array{success: bool, message: string}
     */
    public function markDone(User $user, int $followUpId): array
    {
        $row = $this->find($followUpId);

        if ($row === null || ! $this->takesPart($user, $row)) {
            return $this->refused('That request is not yours.');
        }

        if ($row['status'] !== 'open') {
            return $this->refused('That request is already done.');
        }

        $this->close($row, $user);
        $this->tellOther($user, $row, "follow-up:{$followUpId}:done", 'Follow-up done', $this->nameOf((int) $user->id) . ' marked “' . $row['title'] . '” as done.');

        if ($row['parent_id'] !== null) {
            $parent = $this->find((int) $row['parent_id']);

            if ($parent !== null && $parent['status'] === 'open') {
                $this->close($parent, $user);
                $this->addReply((int) $parent['id'], (int) $user->id, 'Done by ' . $this->nameOf((int) $user->id) . ', the instructor it was passed to.');

                if ((int) $parent['sender_id'] !== (int) $user->id) {
                    $this->notifications->send((int) $parent['sender_id'], 'follow_up', 'Follow-up done', '“' . $parent['title'] . '” is done.', false, "follow-up:{$parent['id']}:done", (int) $parent['institution_id'], $this->linkFor($parent['sender_role']));
                }
            }
        }

        return ['success' => true, 'message' => 'Marked as done.'];
    }

    /**
     * A person's requests, in the box they ask for.
     *
     * @param 'received'|'sent' $box
     * @param 'open'|'overdue'|'done' $tab
     *
     * @return array{rows: list<array<string, mixed>>, counts: array{open: int, overdue: int, done: int}}
     */
    public function listFor(User $user, string $box, string $tab = 'open', ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $db     = db_connect();
        $column = $box === 'sent' ? 'f.sender_id' : 'f.recipient_id';

        $rows = $db->table('follow_ups f')
            ->select(['f.*', 'c.course_code', 'c.course_title', 'co.section_code'])
            ->join('course_offerings co', 'co.id = f.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->where($column, (int) $user->id)
            ->orderBy('f.id', 'DESC')->limit(200)
            ->get()->getResultArray();

        $ids        = array_map('intval', array_column($rows, 'id'));
        $replyRows  = $ids === [] ? [] : $db->table('follow_up_replies')->whereIn('follow_up_id', $ids)->orderBy('id', 'ASC')->get()->getResultArray();
        $childRows  = $ids === [] ? [] : $db->table('follow_ups')->select(['parent_id', 'recipient_id', 'status'])->whereIn('parent_id', $ids)->get()->getResultArray();

        $names = $this->names(array_values(array_unique(array_filter(array_map('intval', array_merge(
            array_column($rows, 'sender_id'),
            array_column($rows, 'recipient_id'),
            array_column($rows, 'student_id'),
            array_column($replyRows, 'user_id'),
            array_column($childRows, 'recipient_id')
        ))))));

        $replies = [];
        $passed  = [];

        foreach ($replyRows as $reply) {
            $replies[(int) $reply['follow_up_id']][] = $reply + ['name' => $names[(int) $reply['user_id']] ?? ''];
        }

        foreach ($childRows as $child) {
            $passed[(int) $child['parent_id']] = ['recipient_id' => (int) $child['recipient_id'], 'status' => $child['status']];
        }

        $counts = ['open' => 0, 'overdue' => 0, 'done' => 0];
        $kept   = [];

        foreach ($rows as $row) {
            $overdue = FollowUpRules::isOverdue($row['status'], $row['due_date'], $row['created_at'], $today);

            $counts[$row['status'] === 'open' ? 'open' : 'done']++;
            $overdue && $counts['overdue']++;

            if (! (($tab === 'done' && $row['status'] === 'done') || ($tab === 'overdue' && $overdue) || ($tab === 'open' && $row['status'] === 'open'))) {
                continue;
            }

            $id    = (int) $row['id'];
            $child = $passed[$id] ?? null;

            $kept[] = $row + [
                'overdue'        => $overdue,
                'due_on'         => FollowUpRules::dueOn($row['due_date'], $row['created_at']),
                'sender_name'    => $names[(int) $row['sender_id']] ?? '',
                'recipient_name' => $names[(int) $row['recipient_id']] ?? '',
                'student_name'   => (int) $row['student_id'] > 0 ? ($names[(int) $row['student_id']] ?? '') : null,
                'replies'        => $replies[$id] ?? [],
                'passed_to'      => $child === null ? null : ['name' => $names[$child['recipient_id']] ?? '', 'status' => $child['status']],
                'label'          => trim($row['course_code'] . ' ' . $row['section_code']),
                'slug'           => url_title((string) $row['course_title'], '-', true),
            ];
        }

        return ['rows' => $kept, 'counts' => $counts];
    }

    /** What a person's open requests come to, for the badge and the dashboard. */
    public function openCounts(User $user, ?string $today = null): array
    {
        $received = $this->listFor($user, 'received', 'open', $today)['counts'];
        $sent     = $this->listFor($user, 'sent', 'open', $today)['counts'];

        return ['received' => $received['open'], 'sent_open' => $sent['open'], 'sent_overdue' => $sent['overdue']];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return db_connect()->table('follow_ups')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /**
     * @param array{id: int, name: string, role: string} $recipient
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, id?: int}
     */
    private function create(User $sender, string $role, array $recipient, array $data, ?int $parentId): array
    {
        $section = $this->section((int) $data['offering_id']);
        $db      = db_connect();
        $subject = (int) $data['student_id'] > 0 ? 'student' : 'offering';
        $key     = ['sender_id' => (int) $sender->id, 'recipient_id' => $recipient['id'], 'subject_type' => $subject, 'offering_id' => (int) $data['offering_id'], 'student_id' => (int) $data['student_id']];

        if ($section === null || $db->table('follow_ups')->where($key + ['open_marker' => 1])->countAllResults() > 0) {
            return $this->refused('You already have an open request to ' . $recipient['name'] . ' about this.');
        }

        $now = date('Y-m-d H:i:s');

        try {
            $db->table('follow_ups')->insert($key + $data + [
                'institution_id' => (int) $section['institution_id'],
                'parent_id'      => $parentId,
                'sender_role'    => $role,
                'recipient_role' => $recipient['role'],
                'status'         => 'open',
                'open_marker'    => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        } catch (\Throwable) {
            // Two sends at once: the unique key let only one in.
            return $this->refused('You already have an open request to ' . $recipient['name'] . ' about this.');
        }

        $id = (int) $db->insertID();

        (new AuditLogger())->log('followup.sent', $sender, [
            'target_type'    => 'follow_up',
            'target_id'      => $id,
            'institution_id' => (int) $section['institution_id'],
            'acting_role'    => $role,
            'new'            => ['recipient_id' => $recipient['id'], 'offering_id' => (int) $data['offering_id'], 'reason' => $data['reason'], 'parent_id' => $parentId],
        ]);

        $this->notifications->send(
            $recipient['id'],
            'follow_up',
            'New follow-up request',
            $this->nameOf((int) $sender->id) . ' asks: ' . $data['title'] . ' (' . trim($section['course_code'] . ' ' . $section['section_code']) . ')',
            false,
            "follow-up:{$id}:new",
            (int) $section['institution_id'],
            $this->linkFor($recipient['role']),
        );

        return ['success' => true, 'message' => 'Follow-up sent to ' . $recipient['name'] . '.', 'id' => $id];
    }

    /** @param array<string, mixed> $row */
    private function close(array $row, User $by): void
    {
        $now = date('Y-m-d H:i:s');

        db_connect()->table('follow_ups')->where(['id' => $row['id'], 'status' => 'open'])->update([
            'status' => 'done', 'open_marker' => null, 'done_by' => (int) $by->id, 'done_at' => $now, 'updated_at' => $now,
        ]);

        (new AuditLogger())->log('followup.closed', $by, [
            'target_type'    => 'follow_up',
            'target_id'      => (int) $row['id'],
            'institution_id' => (int) $row['institution_id'],
            'acting_role'    => (int) $row['sender_id'] === (int) $by->id ? $row['sender_role'] : $row['recipient_role'],
        ]);
    }

    private function addReply(int $followUpId, int $userId, string $body): int
    {
        $db = db_connect();
        $db->table('follow_up_replies')->insert(['follow_up_id' => $followUpId, 'user_id' => $userId, 'body' => $body, 'created_at' => date('Y-m-d H:i:s')]);

        return (int) $db->insertID();
    }

    /** @param array<string, mixed> $row */
    private function takesPart(User $user, array $row): bool
    {
        return in_array((int) $user->id, [(int) $row['sender_id'], (int) $row['recipient_id']], true);
    }

    /** Tells the other side of a request what the actor did. @param array<string, mixed> $row */
    private function tellOther(User $actor, array $row, string $key, string $title, string $body): void
    {
        $senderActs = (int) $row['sender_id'] === (int) $actor->id;
        $otherId    = (int) ($senderActs ? $row['recipient_id'] : $row['sender_id']);
        $otherRole  = $senderActs ? $row['recipient_role'] : $row['sender_role'];

        $this->notifications->send($otherId, 'follow_up', $title, $body, false, $key, (int) $row['institution_id'], $this->linkFor($otherRole));
    }

    private function linkFor(string $role): string
    {
        return site_url($role . '/follow-ups');
    }

    /** @return array<string, mixed>|null */
    private function section(int $offeringId): ?array
    {
        return db_connect()->table('course_offerings co')
            ->select(['d.institution_id', 'sd.id AS sub_department_id', 'c.course_code', 'c.course_title', 'co.section_code'])
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray();
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, string>
     */
    private function names(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $names = [];

        foreach (db_connect()->table('user_profiles')->select(['user_id', "TRIM(CONCAT_WS(' ', first_name, last_name)) AS name"], false)->whereIn('user_id', $userIds)->get()->getResultArray() as $row) {
            $names[(int) $row['user_id']] = (string) $row['name'];
        }

        return $names;
    }

    private function nameOf(int $userId): string
    {
        return $this->names([$userId])[$userId] ?? 'Someone';
    }

    /** @return array{success: false, message: string} */
    private function refused(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
