<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Services\Notifications\MailOutbox;
use App\Services\Notifications\NotificationService;
use DateTimeImmutable;
use Throwable;

/**
 * The ten group events (D19), told in the app and by email (MailOutbox kind "group"). This is the one place that picks
 * recipients and words. Each notice has a dedupe key per event, group or activity and round, so a repeat tells nobody
 * again; the person who caused the event is never told. Grade notices never carry an amount, a reason, a note or
 * another member's anything (D8). Group services call it through hook(), so a failure here never fails their action.
 */
class GroupNotifier
{
    /** Tests swap in a notifier that throws, to prove an action never depends on it. */
    public static ?self $instance = null;

    /** @var array<int, array<string, mixed>> offering details, for this object's lifetime */
    private array $offerings = [];

    /** One guarded line at each event site: whatever goes wrong is logged, never thrown. */
    public static function hook(callable $call): void
    {
        try {
            $call(self::$instance ?? new self());
        } catch (Throwable $e) {
            log_message('error', 'A group notification was not sent: ' . $e->getMessage());
        }
    }

    /** 1. Students placed in a group (by hand, by a saved generation, by a copy or an approved proposal). @param list<int> $studentIds */
    public function placed(int $groupId, array $studentIds, int $actorId): void
    {
        $g = $this->group($groupId);

        if ($g === null) {
            return;
        }

        $body = $g['config_id'] === null
            ? "You are in \"{$g['name']}\" in the group set \"{$g['title']}\"."
            : "You are in \"{$g['name']}\" for \"{$g['title']}\".";

        $this->tell($studentIds, $actorId, 'groups.placed', 'You are in a group', $body, "groups.placed:{$groupId}", $g, $this->studentLink($g));
    }

    /** 1. Every group of a set or an activity was (re)made: each member is told about their group. */
    public function groupsSaved(string $column, int $id, int $actorId): void
    {
        foreach (db_connect()->table('student_groups')->select('id')->where($column === 'set_id' ? 'set_id' : 'config_id', $id)->where('status', 'active')->get()->getResultArray() as $row) {
            $this->placed((int) $row['id'], $this->members((int) $row['id']), $actorId);
        }
    }

    /** 1 + 2. Added to a group: the student is placed, the others hear who joined. */
    public function added(int $groupId, int $studentId, int $actorId): void
    {
        $this->placed($groupId, [$studentId], $actorId);
        $this->memberChanged($groupId, $studentId, 'joined', $actorId);
    }

    /** 1. An approved proposal makes the group real: its members are placed. */
    public function groupApproved(int $groupId, int $actorId): void
    {
        $this->placed($groupId, $this->members($groupId), $actorId);
    }

    /** 2 + 7. An invitation was accepted: the others hear who joined, and the proposal may now be ready for approval. */
    public function accepted(int $groupId, int $studentId): void
    {
        $this->memberChanged($groupId, $studentId, 'joined', $studentId);
        $this->proposalReady($groupId, $studentId);
    }

    /** 1 + 2. Moved from one group to another. */
    public function moved(int $fromGroupId, int $toGroupId, int $studentId, int $actorId): void
    {
        $this->memberChanged($fromGroupId, $studentId, 'left', $actorId);
        $this->added($toGroupId, $studentId, $actorId);
    }

    /** 2. A member joined or left, or ($what = 'leader') $studentId became the leader: the other members are told. */
    public function memberChanged(int $groupId, int $studentId, string $what, int $actorId): void
    {
        $g = $this->group($groupId);

        if ($g === null || $g['config_id'] === null) {
            return;
        }

        $name    = $this->nameOf($studentId);
        $members = $this->members($groupId);

        [$body, $round, $to] = match ($what) {
            'joined' => ["{$name} joined \"{$g['name']}\" for \"{$g['title']}\".", 'joined:' . $studentId . ':' . $this->joinedAt($groupId, $studentId), array_diff($members, [$studentId])],
            'left'   => ["{$name} left \"{$g['name']}\" for \"{$g['title']}\".", 'left:' . $studentId . ':' . date('YmdHi'), array_diff($members, [$studentId])],
            default  => ["{$name} is now the leader of \"{$g['name']}\" for \"{$g['title']}\".", 'leader:' . $studentId . ':' . $g['leader_finalized_at'], $members],
        };

        $this->tell($to, $actorId, 'groups.member_changed', 'Your group changed', $body, "groups.member:{$groupId}:{$round}", $g, $this->studentLink($g));
    }

    /** 3. A leader vote started: the other members are told once per round (a round starts with the first vote cast). */
    public function leaderVote(int $groupId, int $voterId): void
    {
        $g     = $this->group($groupId);
        $votes = db_connect()->table('student_group_leader_votes')->select('MIN(voted_at) AS round, COUNT(*) AS n', false)->where('group_id', $groupId)->get()->getRowArray();
        $round = $votes['round'] ?? null;

        // Only the vote that opens a round tells anyone; later votes of the round are news to nobody.
        if ($g === null || $round === null || (int) $votes['n'] !== 1) {
            return;
        }

        $this->tell(array_diff($this->members($groupId), [$voterId]), $voterId, 'groups.leader', 'Your group is choosing a leader', "Your group \"{$g['name']}\" is choosing a leader for \"{$g['title']}\". Cast your vote.", "groups.vote:{$groupId}:{$round}", $g, $this->studentLink($g));
    }

    /** 4. The group handed in (shared styles): every other member is told. */
    public function submitted(int $groupId, int $assessmentId, int $actorId): void
    {
        $g   = $this->group($groupId);
        $sub = db_connect()->table('assessment_submissions')->selectMax('id')->where('assessment_id', $assessmentId)->where('group_id', $groupId)->get()->getRowArray()['id'] ?? null;

        if ($g === null || $sub === null) {
            return;
        }

        $this->tell($this->members($groupId), $actorId, 'groups.submitted', 'Your group handed in its work', $this->nameOf($actorId) . " handed in \"{$g['title']}\" for \"{$g['name']}\".", "groups.submitted:{$groupId}:{$sub}", $g, $this->studentLink($g));
    }

    /**
     * 5. Graded, or a member's score changed. The words are the same for everyone and say nothing about any score (D8);
     * the link is the assignment page, where each student sees only their own result.
     *
     * @param list<int> $studentIds
     */
    public function graded(int $groupId, int $assessmentId, array $studentIds, int $actorId): void
    {
        $g     = $this->group($groupId);
        $round = db_connect()->table('group_member_scores gms')->selectMax('gms.graded_at')->join('assessment_submissions s', 's.id = gms.submission_id')
            ->where('gms.group_id', $groupId)->where('s.assessment_id', $assessmentId)->get()->getRowArray()['graded_at'] ?? null;

        if ($g === null) {
            return;
        }

        $this->tell($studentIds, $actorId, 'groups.graded', 'Your group\'s work was graded', "Your group's work \"{$g['title']}\" was graded. See your result.", "groups.graded:{$assessmentId}:{$groupId}:" . ($round ?? 'now'), $g, $this->studentLink($g));
    }

    /** 7. A proposed group has enough members to be approved: the activity's instructors are told once. */
    public function proposalReady(int $groupId, int $actorId): void
    {
        $g = $this->group($groupId);

        if ($g === null || $g['status'] !== 'proposed' || count($this->members($groupId)) < (int) $g['min_size']) {
            return;
        }

        $this->tellStaff($g, $actorId, 'groups.proposal', 'A group proposal needs approval', "The proposed group \"{$g['name']}\" for \"{$g['title']}\" is waiting for your approval.", "groups.proposal:{$groupId}");
    }

    /** 9. More time for one group: every member is told the new deadline (never the reason). */
    public function extended(int $groupId, int $assessmentId, int $actorId): void
    {
        $g     = $this->group($groupId);
        $until = (new GroupExtensionService())->extensionFor($groupId, $assessmentId)['until_at'] ?? null;

        if ($g === null || $until === null) {
            return;
        }

        $this->tell($this->members($groupId), $actorId, 'groups.extended', 'Your group has more time', "Your group \"{$g['name']}\" has more time for \"{$g['title']}\". It is now due " . date('M j, Y g:i A', (int) strtotime((string) $until)) . '.', "groups.extended:{$groupId}:{$assessmentId}:{$until}", $g, $this->studentLink($g));
    }

    /** 10. A frozen group was unlocked ($round = the freeze that ended), or got a late member ($studentId): instructors and members. */
    public function changedAfterSubmission(int $groupId, string $what, int $actorId, string $round, ?int $studentId = null): void
    {
        $g = $this->group($groupId);

        if ($g === null) {
            return;
        }

        $body = match ($what) {
            'unlocked' => "\"{$g['name']}\" for \"{$g['title']}\" was unlocked after it handed in. Its members can change again.",
            'dropped'  => $this->nameOf((int) $studentId) . " left \"{$g['name']}\" for \"{$g['title']}\" after it handed in, because they are no longer in the course.",
            default    => $this->nameOf((int) $studentId) . " was added to \"{$g['name']}\" for \"{$g['title']}\" after it handed in.",
        };
        $title = $what === 'unlocked' ? 'A group was unlocked' : 'A group changed after it handed in';
        $key   = "groups.unlocked:{$groupId}:{$what}:{$round}";

        $this->tell($this->members($groupId), $actorId, 'groups.unlocked', $title, $body, $key, $g, $this->studentLink($g));
        $this->tellStaff($g, $actorId, 'groups.unlocked', $title, $body, $key);
    }

    /** 2 or 10. A dropped student was taken out of an activity group: after its first hand-in this is event 10. */
    public function dropped(int $groupId, int $studentId): void
    {
        $g = $this->group($groupId);

        if ($g === null || $g['config_id'] === null) {
            return;
        }

        $g['frozen_at'] === null
            ? $this->memberChanged($groupId, $studentId, 'left', 0)
            : $this->changedAfterSubmission($groupId, 'dropped', 0, (string) $studentId, $studentId);
    }

    /**
     * 6 + 8, from the scheduler: sign-up closing within a day (to students still without a group) and sign-up just
     * closed with work left (to the instructors). Each once per activity and closing time.
     *
     * @return int how many people were told
     */
    public function windows(DateTimeImmutable $now): int
    {
        $stamp   = $now->format('Y-m-d H:i:s');
        $told    = 0;
        $configs = db_connect()->table('activity_group_configs')->select('id')->whereIn('formation_mode', ['self_signup', 'proposed'])
            ->where('signup_closes_at >', $now->modify('-7 days')->format('Y-m-d H:i:s'))
            ->where('signup_closes_at <=', $now->modify('+1 day')->format('Y-m-d H:i:s'))
            ->get()->getResultArray();

        foreach ($configs as $row) {
            $c       = $this->activity((int) $row['id']);
            $summary = (new GroupProposalService())->windowSummary((int) $row['id']);
            $key     = "{$row['id']}:{$c['signup_closes_at']}";

            if ($c['signup_closes_at'] > $stamp) {
                if ($c['signup_opens_at'] === null || $c['signup_opens_at'] <= $stamp) {
                    $told += $this->tell($summary['ungrouped'], 0, 'groups.window_closing', 'Group sign-up closes soon', "Sign-up for groups in \"{$c['title']}\" closes " . date('M j, Y g:i A', (int) strtotime((string) $c['signup_closes_at'])) . '. Join a group before then.', "groups.window_closing:{$key}", $c, $this->studentLink($c));
                }

                continue;
            }

            $below = count($summary['belowMinimum']);
            $alone = count($summary['ungrouped']);
            $parts = array_filter([
                $below === 0 ? null : ($below === 1 ? '1 group below the minimum' : "{$below} groups below the minimum"),
                $alone === 0 ? null : ($alone === 1 ? '1 student without a group' : "{$alone} students without a group"),
            ]);

            if ($parts !== []) {
                $told += $this->tellStaff($c, 0, 'groups.window_closed', 'Group sign-up closed with work left', "Sign-up closed for \"{$c['title']}\": " . implode(' and ', $parts) . '.', "groups.window_closed:{$key}");
            }
        }

        return $told;
    }

    /*
    |--------------------------------------------------------------------------
    | Telling
    |--------------------------------------------------------------------------
    */

    /**
     * One in-app notice each (in a few queries however many people) and one email row each, for those not yet told.
     *
     * @param iterable<int> $userIds
     * @param array<string, mixed> $g group or activity context
     *
     * @return int how many were told
     */
    private function tell(iterable $userIds, int $actorId, string $type, string $title, string $body, string $key, array $g, string $link): int
    {
        $ids = [];

        foreach ($userIds as $id) {
            $ids[] = (int) $id;
        }

        $ids = array_values(array_diff(array_unique($ids), [$actorId, 0]));

        if ($ids === []) {
            return 0;
        }

        $had = array_map('intval', array_column(db_connect()->table('notifications')->select('user_id')->where('dedupe_key', $key)->whereIn('user_id', $ids)->get()->getResultArray(), 'user_id'));
        $new = array_values(array_diff($ids, $had));

        if ($new === []) {
            return 0;
        }

        $offering = $this->offering((int) $g['offering_id']);
        (new NotificationService())->sendToMany($new, $type, $title, $body, $key, $offering['institution_id'], $link);

        $outbox = new MailOutbox();

        foreach ($new as $id) {
            $outbox->tell((int) $offering['institution_id'], $id, MailOutbox::GROUP, $offering['label'] . ': ' . $body);
        }

        return count($new);
    }

    /** The offering's instructors (and chairpersons teaching it), each sent into their own area. @param array<string, mixed> $g */
    private function tellStaff(array $g, int $actorId, string $type, string $title, string $body, string $key): int
    {
        $staff = array_map('intval', array_column(db_connect()->table('instructor_assignments')->select('instructor_user_id')->where('offering_id', $g['offering_id'])->get()->getResultArray(), 'instructor_user_id'));

        if ($staff === []) {
            return 0;
        }

        $chairs = array_map('intval', array_column(db_connect()->table('auth_groups_users')->select('user_id')->whereIn('user_id', $staff)->where('group', 'instructor')->get()->getResultArray(), 'user_id'));
        $told   = 0;

        foreach (['instructor' => $chairs, 'chairperson' => array_diff($staff, $chairs)] as $area => $ids) {
            $told += $this->tell($ids, $actorId, $type, $title, $body, $key, $g, $this->link($g, $area));
        }

        return $told;
    }

    /*
    |--------------------------------------------------------------------------
    | What the words need
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed>|null the group with its activity (or set) */
    private function group(int $groupId): ?array
    {
        $row = db_connect()->table('student_groups g')
            ->select('g.id, g.name, g.status, g.config_id, g.set_id, g.frozen_at, g.leader_finalized_at, COALESCE(c.offering_id, s.offering_id) AS offering_id, c.assessment_id, c.learning_item_id, COALESCE(c.min_size, s.min_size) AS min_size, COALESCE(a.title, li.title, s.name) AS title', false)
            ->join('activity_group_configs c', 'c.id = g.config_id', 'left')
            ->join('group_sets s', 's.id = g.set_id', 'left')
            ->join('assessments a', 'a.id = c.assessment_id', 'left')
            ->join('learning_items li', 'li.id = c.learning_item_id', 'left')
            ->where('g.id', $groupId)->get()->getRowArray();

        return $row === null || $row['offering_id'] === null ? null : $row;
    }

    /** @return array<string, mixed> an activity, for the scheduled notices */
    private function activity(int $configId): array
    {
        return db_connect()->table('activity_group_configs c')
            ->select('c.id AS config_id, c.offering_id, c.assessment_id, c.learning_item_id, c.signup_opens_at, c.signup_closes_at, COALESCE(a.title, li.title) AS title', false)
            ->join('assessments a', 'a.id = c.assessment_id', 'left')
            ->join('learning_items li', 'li.id = c.learning_item_id', 'left')
            ->where('c.id', $configId)->get()->getRowArray();
    }

    /** @return array{institution_id: int, label: string, slug: string} */
    private function offering(int $offeringId): array
    {
        if (!isset($this->offerings[$offeringId])) {
            $row = db_connect()->table('course_offerings o')->select('d.institution_id, c.course_code, c.course_title, o.section_code')
                ->join('courses c', 'c.id = o.course_id')->join('programs p', 'p.id = c.program_id')->join('departments sd', 'sd.id = p.department_id')->join('colleges d', 'd.id = sd.college_id')
                ->where('o.id', $offeringId)->get()->getRowArray();

            helper('url');
            $this->offerings[$offeringId] = ['institution_id' => (int) $row['institution_id'], 'label' => "{$row['course_code']} {$row['course_title']}, section {$row['section_code']}", 'slug' => url_title((string) $row['course_title'], '-', true)];
        }

        return $this->offerings[$offeringId];
    }

    /** @param array<string, mixed> $g */
    private function studentLink(array $g): string
    {
        return $this->link($g, 'student');
    }

    /** The activity's page in the given area (the assignment, the learning item, or the course for a set). @param array<string, mixed> $g */
    private function link(array $g, string $area): string
    {
        $base = "{$area}/courses/{$g['offering_id']}/" . $this->offering((int) $g['offering_id'])['slug'];

        return match (true) {
            ($g['assessment_id'] ?? null) !== null    => "{$base}/assessments?assessment={$g['assessment_id']}&view=1",
            ($g['learning_item_id'] ?? null) !== null => "{$base}/modules/items/{$g['learning_item_id']}",
            default                                   => $base,
        };
    }

    /** @return list<int> */
    private function members(int $groupId): array
    {
        return array_map('intval', array_column(db_connect()->table('student_group_members')->select('student_user_id')->where('group_id', $groupId)->orderBy('id')->get()->getResultArray(), 'student_user_id'));
    }

    private function joinedAt(int $groupId, int $studentId): string
    {
        return (string) (db_connect()->table('student_group_members')->select('joined_at')->where('group_id', $groupId)->where('student_user_id', $studentId)->get()->getRowArray()['joined_at'] ?? date('YmdHi'));
    }

    private function nameOf(int $userId): string
    {
        $row = db_connect()->table('users u')->select('u.username, up.first_name, up.last_name')->join('user_profiles up', 'up.user_id = u.id', 'left')->where('u.id', $userId)->get()->getRowArray();

        return $row === null ? 'A member' : (trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: (string) $row['username']);
    }
}
