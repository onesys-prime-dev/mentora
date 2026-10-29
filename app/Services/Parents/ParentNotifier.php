<?php

declare(strict_types=1);

namespace App\Services\Parents;

use App\Services\Notifications\NotificationService;
use DateTimeImmutable;

/**
 * Tells a child's parents what changed for the child (Q7, Q17).
 *
 * Every event goes to each linked parent once, through the same idempotent
 * NotificationService every other area uses (a unique key per parent and
 * event), so it is safe to run as often as the scheduler likes and the hook
 * that tells at once and the sweep that finds the rest never double up. A
 * parent always gets the in-app message; Mentora also emails it when the
 * event is one that is emailed and the parent's switch for that child is on.
 *
 * Nothing a parent reads here contains a grade, a percentage or a score: the
 * words say that something is ready and send the parent to sign in.
 *
 * Only a child's current courses are watched (an active offering the student
 * is enrolled in), and only while the institution's parent portal is on.
 */
class ParentNotifier
{
    /** A final grade is news for this long after it is finalized (so the first run after a deploy tells no old news). */
    public const FINAL_WINDOW_DAYS = 14;

    private NotificationService $notes;

    private ParentPreferences $prefs;

    private ParentPortal $portal;

    /** @var array<int, bool> portal state per institution, for this object's lifetime */
    private array $portalOn = [];

    /**
     * @param bool $dryRun only count what would be told: nothing is stored, sent or recorded
     */
    public function __construct(?NotificationService $notes = null, ?ParentPreferences $prefs = null, ?ParentPortal $portal = null, private readonly bool $dryRun = false)
    {
        $this->notes  = $notes ?? new NotificationService();
        $this->prefs  = $prefs ?? new ParentPreferences();
        $this->portal = $portal ?? new ParentPortal();
    }

    /**
     * Every kind of event, once: what the scheduler runs (`php spark parents:notify`).
     * Safe to run as often as you like; nobody is told the same thing twice.
     *
     * @return array{final: int, standing: int, announcement: int, missing: int} how many were told, by kind
     */
    public function sweep(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        return [
            'final'        => $this->finalGrades(null, $now),
            'standing'     => $this->standings($now),
            'announcement' => $this->announcements($now),
            'missing'      => $this->missingWork($now),
        ];
    }

    /**
     * The parents to tell about a child: linked in the child's institution, still
     * active, holding the parent role, members of that institution, whose
     * institution has the portal switched on, and only while the student is
     * still a member of it too.
     *
     * @return list<array{parent_id: int, institution_id: int}>
     */
    public function parentsOf(int $studentId): array
    {
        $parents = [];

        foreach ((new ParentAccessService())->parentsOf($studentId) as $parent) {
            if ($this->portalOn[$parent['institution_id']] ??= $this->portal->enabled($parent['institution_id'])) {
                $parents[] = $parent;
            }
        }

        return $parents;
    }

    /**
     * Final grades finalized in the last 14 days in a current course, to the
     * child's parents (emailed when the parent's switch is on). A grade that
     * was frozen as INC or Withdrawn is not a final grade and says nothing here.
     *
     * @param ?int $offeringId one course (the instant a teacher finalizes it), or every current course
     *
     * @return int how many notifications were created
     */
    public function finalGrades(?int $offeringId = null, ?DateTimeImmutable $now = null): int
    {
        $now   ??= new DateTimeImmutable();
        $since   = $now->modify('-' . self::FINAL_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');

        $builder = $this->currentRecords()
            ->select('r.finalized_at')
            ->whereIn('r.remarks', ['Passed', 'Failed'])
            ->where('r.finalized_at >=', $since);

        if ($offeringId !== null) {
            $builder->where('r.course_offering_id', $offeringId);
        }

        $created = 0;

        foreach ($builder->get()->getResultArray() as $row) {
            $first = $this->first($row);
            $code  = $this->code($row);
            $link  = $this->link($row, 'grades');

            foreach ($this->parentsOf((int) $row['student_id']) as $parent) {
                $created += $this->tell(
                    $parent,
                    (int) $row['student_id'],
                    'parent.final',
                    'Final grade available for ' . $first,
                    $first . '\'s final grade for ' . $code . ' is ready. Sign in to view it.',
                    'parent.final:' . $row['student_id'] . ':' . $row['course_offering_id'] . ':' . $row['finalized_at'],
                    $link,
                    true,
                    $now,
                ) ? 1 : 0;
            }
        }

        return $created;
    }

    /**
     * Where a child stands in a current course, told once per child and course:
     * marked incomplete (INC), withdrawn, or at risk (the grade so far is below
     * the passing mark, which is what the student's own Grades tab calls
     * "At risk"). All three are emailed when the parent's switch is on.
     *
     * @return int how many notifications were created
     */
    public function standings(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();

        $copy = [
            'inc' => [
                static fn (string $first, string $code): string => $first . ' is marked incomplete in ' . $code,
                static fn (string $first, string $code): string => 'The instructor marked ' . $first . '\'s work in ' . $code . ' as incomplete (INC). Sign in for details.',
                'grades',
            ],
            'withdrawn' => [
                static fn (string $first, string $code): string => $first . ' is withdrawn from ' . $code,
                static fn (string $first, string $code): string => $first . ' is shown as withdrawn from ' . $code . '. Contact the school if this is a surprise.',
                'overview',
            ],
            'at_risk' => [
                static fn (string $first, string $code): string => $first . ' may be falling behind in ' . $code,
                static fn (string $first, string $code): string => $first . '\'s grade so far in ' . $code . ' is below the passing mark. Sign in to see how it is made up.',
                'grades',
            ],
        ];

        $rows = [];

        // INC and At risk are about a student who is still enrolled...
        foreach ($this->currentRecords()->select('r.remarks, r.finalized_at')->whereIn('r.remarks', ['INC', 'Failed'])->get()->getResultArray() as $row) {
            if ($row['remarks'] === 'INC') {
                $rows[] = ['inc'] + $row;
            } elseif ($row['finalized_at'] === null) {
                $rows[] = ['at_risk'] + $row;
            }
        }

        // ...Withdrawn is about one whose enrolment has ended.
        foreach ($this->currentRecords(['withdrawn', 'dropped'])->select('r.remarks, r.finalized_at')->where('r.remarks', 'Withdrawn')->get()->getResultArray() as $row) {
            $rows[] = ['withdrawn'] + $row;
        }

        $created = 0;

        foreach ($rows as $row) {
            [$title, $body, $tab] = $copy[$row[0]];

            $first = $this->first($row);
            $code  = $this->code($row);

            foreach ($this->parentsOf((int) $row['student_id']) as $parent) {
                $created += $this->tell(
                    $parent,
                    (int) $row['student_id'],
                    'parent.' . $row[0],
                    $title($first, $code),
                    $body($first, $code),
                    'parent.' . $row[0] . ':' . $row['student_id'] . ':' . $row['course_offering_id'],
                    $this->link($row, $tab),
                    true,
                    $now,
                ) ? 1 : 0;
            }
        }

        return $created;
    }

    /**
     * Work that has become overdue and was not handed in, as ONE message per
     * parent per child per day (and so one email), saying only how many.
     *
     * An item counts when it is published and visible to the student, in a
     * current course, was due in the last 7 days and is still open to
     * submissions, has nothing handed in (an unfinished attempt does not count)
     * and is not excused. Each item is reported once, ever: it is recorded as
     * told only when the day's message was actually created, so one that falls
     * overdue after today's summary has gone is in tomorrow's.
     *
     * @return int how many notifications were created
     */
    public function missingWork(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $db    = db_connect();
        $stamp = $now->format('Y-m-d H:i:s');
        $since = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $day   = $now->format('Y-m-d');

        $items = $db->table('assessments a')
            ->select('a.id AS assessment_id, e.student_user_id AS student_id, o.id AS course_offering_id, c.course_code, c.course_title, o.section_code, up.first_name')
            ->join('course_modules cm', "cm.id = a.module_id AND cm.status = 'published'", 'inner', false)
            ->join('course_offerings o', "o.id = cm.offering_id AND o.status = 'active'", 'inner', false)
            ->join('courses c', 'c.id = o.course_id')
            ->join('enrollments e', "e.offering_id = o.id AND e.status = 'enrolled'", 'inner', false)
            ->join('learning_items li', 'li.id = a.learning_item_id', 'left')
            ->join('learning_units lu', 'lu.id = li.learning_unit_id', 'left')
            ->join('user_profiles up', 'up.user_id = e.student_user_id', 'left')
            ->where('a.status', 'Published')
            ->where('a.due_date <', $stamp)
            ->where('a.due_date >=', $since)
            ->groupStart()->where('a.available_until IS NULL', null, false)->orWhere('a.available_until >=', $stamp)->groupEnd()
            ->groupStart()->where('a.learning_item_id IS NULL', null, false)->orGroupStart()->where('li.status', 'published')->where('lu.status', 'published')->groupEnd()->groupEnd()
            ->where("NOT EXISTS (SELECT 1 FROM assessment_submissions s WHERE s.assessment_id = a.id AND s.student_id = e.student_user_id AND s.status NOT IN ('In Progress', 'Cancelled'))", null, false)
            ->where('NOT EXISTS (SELECT 1 FROM grade_items gi JOIN gradebook_item_scores gs ON gs.grade_item_id = gi.id WHERE gi.assessment_id = a.id AND gs.student_id = e.student_user_id AND gs.is_missing = 1)', null, false)
            ->where("NOT EXISTS (SELECT 1 FROM parent_alerted_items p WHERE p.student_user_id = e.student_user_id AND p.kind = 'missing' AND p.ref_id = a.id)", null, false)
            ->orderBy('e.student_user_id')
            ->get()->getResultArray();

        $byStudent = [];

        foreach ($items as $item) {
            $byStudent[(int) $item['student_id']][] = $item;
        }

        $created = 0;

        foreach ($byStudent as $studentId => $list) {
            $parents = $this->parentsOf($studentId);

            if ($parents === []) {
                continue;
            }

            $first   = $this->first($list[0]);
            $count   = count($list);
            $oneCourse = count(array_unique(array_column($list, 'course_offering_id'))) === 1;
            $link    = $oneCourse ? $this->link($list[0], 'assessments') : 'parent/children/' . $studentId;
            $sent    = false;

            foreach ($parents as $parent) {
                $made = $this->tell(
                    $parent,
                    $studentId,
                    'parent.missing',
                    $first . ' has work not handed in',
                    $first . ' has ' . $count . ' new ' . ($count === 1 ? 'item' : 'items') . ' not handed in. Sign in to see which.',
                    'parent.missing:' . $studentId . ':' . $day,
                    $link,
                    true,
                    $now,
                );

                $created += $made ? 1 : 0;
                $sent     = $sent || $made;
            }

            if ($sent && ! $this->dryRun) {
                foreach ($list as $item) {
                    $db->table('parent_alerted_items')->ignore(true)->insert(['student_user_id' => $studentId, 'kind' => 'missing', 'ref_id' => (int) $item['assessment_id'], 'created_at' => $stamp]);
                }
            }
        }

        return $created;
    }

    /**
     * A course announcement posted in the last three days, to the parents of
     * every enrolled student: in the app only, never emailed. A parent with
     * several children in the course hears once, and the link opens the
     * announcement under the first of them.
     *
     * @return int how many notifications were created
     */
    public function announcements(?DateTimeImmutable $now = null): int
    {
        helper('url');

        $now   ??= new DateTimeImmutable();
        $since   = $now->modify('-3 days')->format('Y-m-d H:i:s');
        $db      = db_connect();

        $posts = $db->table('announcements a')
            ->select('a.id, a.subject, a.offering_id, c.course_code, c.course_title, o.section_code')
            ->join('course_offerings o', "o.id = a.offering_id AND o.status = 'active'", 'inner', false)
            ->join('courses c', 'c.id = o.course_id')
            ->where('a.created_at >=', $since)
            // A post dated for a later day is news on that day (QA AMB-COM-01).
            ->where('a.created_at <=', $now->format('Y-m-d H:i:s'))
            ->get()->getResultArray();

        $created = 0;

        foreach ($posts as $post) {
            $students = array_map('intval', array_column(
                $db->table('enrollments')->select('student_user_id')->where(['offering_id' => $post['offering_id'], 'status' => 'enrolled'])->orderBy('student_user_id')->get()->getResultArray(),
                'student_user_id',
            ));

            // Each parent, with the first (lowest id) of their children in the course.
            $first = [];

            foreach ($students as $studentId) {
                foreach ($this->parentsOf($studentId) as $parent) {
                    $first[$parent['parent_id']] ??= ['parent' => $parent, 'student' => $studentId];
                }
            }

            $code = $post['course_code'] . '-' . $post['section_code'];

            foreach ($first as $pick) {
                $created += $this->tell(
                    $pick['parent'],
                    $pick['student'],
                    'parent.announcement',
                    'New announcement in ' . $code,
                    'The instructor posted "' . $post['subject'] . '" in ' . $code . '.',
                    'parent.announcement:' . $post['id'],
                    'parent/children/' . $pick['student'] . '/courses/' . (int) $post['offering_id'] . '/' . url_title((string) $post['course_title'], '-', true) . '/announcements?announcement=' . (int) $post['id'],
                    false,
                    $now,
                ) ? 1 : 0;
            }
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Shared
    |--------------------------------------------------------------------------
    */

    /**
     * Gradebook records of the child's current courses (an active offering, a
     * published grading scheme, the student in one of the given enrolment states)
     * with what a message needs to name them.
     *
     * @param list<string> $enrolment
     */
    private function currentRecords(array $enrolment = ['enrolled']): \CodeIgniter\Database\BaseBuilder
    {
        return db_connect()->table('gradebook_records r')
            ->select('r.course_offering_id, r.student_id, c.course_code, c.course_title, o.section_code, up.first_name')
            ->join('course_offerings o', "o.id = r.course_offering_id AND o.status = 'active'", 'inner', false)
            ->join('courses c', 'c.id = o.course_id')
            ->join('grading_schemes gs', "gs.id = r.grading_scheme_id AND gs.status = 'Published'", 'inner', false)
            ->join('enrollments e', 'e.offering_id = o.id AND e.student_user_id = r.student_id AND e.status IN (' . implode(',', array_map(static fn (string $s): string => db_connect()->escape($s), $enrolment)) . ')', 'inner', false)
            ->join('user_profiles up', 'up.user_id = r.student_id', 'left');
    }

    /**
     * Tells one parent. The app's message is always created; it is also emailed
     * when the event is an emailed kind and the parent's switch for the child is on.
     *
     * @param array{parent_id: int, institution_id: int} $parent
     */
    private function tell(array $parent, int $studentId, string $type, string $title, string $body, string $dedupe, string $link, bool $emailed, DateTimeImmutable $now): bool
    {
        // A dry run only asks whether this would be news to the parent.
        if ($this->dryRun) {
            return ! $this->notes->has($parent['parent_id'], $dedupe);
        }

        $urgent = $emailed && $this->prefs->emailEnabled($parent['parent_id'], $studentId);

        return $this->notes->send($parent['parent_id'], $type, $title, $body, $urgent, $dedupe, $parent['institution_id'], $link, $now);
    }

    /** @param array<string, mixed> $row */
    private function first(array $row): string
    {
        $first = trim((string) ($row['first_name'] ?? ''));

        return $first !== '' ? $first : 'your child';
    }

    /** @param array<string, mixed> $row */
    private function code(array $row): string
    {
        return $row['course_code'] . '-' . $row['section_code'];
    }

    /**
     * The parent's page for the course: the course address of the child, on one tab.
     *
     * @param array<string, mixed> $row
     */
    private function link(array $row, string $tab): string
    {
        helper('url');

        return 'parent/children/' . (int) $row['student_id'] . '/courses/' . (int) $row['course_offering_id'] . '/' . url_title((string) $row['course_title'], '-', true) . '/' . $tab;
    }
}
