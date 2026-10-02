<?php

namespace App\Services\Courses;

use App\Services\Courses\Assessments\AssessmentOverviewService;

/**
 * The course Overview's "Recent Activity" feed.
 *
 * There is no event log: every event here is derived from a timestamp a
 * table already keeps (graded_at, submitted_at, enrolled_at, …), so nothing
 * needed a schema change. That has two honest limits, both worth knowing:
 *
 *   - Only things that leave a timestamp can appear. Someone opening a page
 *     or a draft being edited leaves no trace.
 *   - "Module updated" is a guess: course_modules only has updated_at, which
 *     moves on any edit, not just publishing. A module whose updated_at is
 *     within a minute of created_at is called "New module"; anything later
 *     is "Module updated".
 *
 * If the feed ever needs to be exact (or to cover more), the fix is an
 * activity_log table written at the moment things happen — this class's
 * output shape would stay the same.
 *
 * Bulk events are collapsed into single lines (see COLLAPSIBLE).
 *
 * What each role sees:
 *   student    → grades posted to them, assessments that opened, modules
 *                that appeared or changed, discussions others started
 *   instructor → work handed in by their section, students who joined,
 *                discussions students started
 */
class ActivityFeedService
{
    /** Events older than this aren't "recent". */
    public const WINDOW_DAYS = 30;

    /** Events shown on the Overview. */
    public const LIMIT = 6;

    /**
     * Kinds that happen in bulk: a roster enrolled at once, a course's
     * modules created together. Several in the same hour become one line
     * ("4 students joined the section") instead of drowning out the events
     * people actually act on - grades and submissions, which are always
     * listed individually.
     */
    protected const COLLAPSIBLE = ['joined', 'module', 'assessment', 'discussion'];

    /** How many names/titles a collapsed line spells out before "+N more". */
    protected const COLLAPSED_NAMES = 3;

    protected AssessmentOverviewService $labels;

    public function __construct()
    {
        $this->labels = new AssessmentOverviewService();
    }

    /**
     * @param  list<array> $assessmentRows The student's visible assessments,
     *                                     as AssessmentOverviewService builds them
     * @return list<array{kind: string, title: string, detail: string, when: string, ts: int}>
     */
    public function forStudent(int $studentId, int $offeringId, array $assessmentRows): array
    {
        $now = time();

        $since = $this->since($now);

        $byId = array_column($assessmentRows, null, 'id');

        $events = [];

        // Grades posted to this student.
        if ($byId !== []) {

            $graded = db_connect()->table('assessment_submissions')
                ->select(['assessment_id', 'score', 'graded_at'])
                ->select(
                    "(SELECT message FROM assessment_feedbacks
                        WHERE submission_id = assessment_submissions.id
                        ORDER BY id DESC LIMIT 1) AS feedback",
                    false
                )
                ->where('student_id', $studentId)
                ->where('is_graded', 1)
                ->whereIn('assessment_id', array_keys($byId))
                ->where('graded_at >=', $since)
                ->where('graded_at <=', date('Y-m-d H:i:s', $now))
                ->orderBy('graded_at', 'DESC')
                ->get()
                ->getResultArray();

            foreach ($graded as $row) {

                $assessment = $byId[$row['assessment_id']];

                $feedback = trim((string) $row['feedback']);

                $events[] = $this->event(
                    'grade',
                    'Grade posted',
                    $assessment['title'] . ': ' . round((float) $row['score'], 2) . '/' . round((float) $assessment['total_points'], 2)
                        . ($feedback !== '' ? ' — "' . $this->excerpt($feedback) . '"' : ''),
                    $row['graded_at']
                );

            }

        }

        // Assessments that opened for this student.
        foreach ($assessmentRows as $assessment) {

            if ($assessment['status'] !== 'Published') {
                continue;
            }

            $opened = $assessment['available_from'] ?: $assessment['created_at'];

            $ts = $opened ? strtotime($opened) : false;

            if ($ts === false || $ts < strtotime($since) || $ts > $now) {
                continue;
            }

            $events[] = $this->event(
                'assessment',
                'New ' . strtolower((string) ($assessment['assessment_type'] ?: 'assessment')),
                $assessment['title'] . ($assessment['due_ts'] !== null
                    ? ' · due ' . $this->labels->dueLabel((int) $assessment['due_ts'], $now)
                    : ''),
                $opened,
                $assessment['title']
            );

        }

        return $this->finish(array_merge(
            $events,
            $this->moduleEvents($offeringId, $since, $now),
            $this->discussionEvents($offeringId, $since, $now, $studentId, false)
        ));
    }

    /**
     * @return list<array{kind: string, title: string, detail: string, when: string, ts: int}>
     */
    public function forInstructor(int $offeringId): array
    {
        $now = time();

        $since = $this->since($now);

        $events = [];

        // Work handed in by the section. "In Progress" is a started attempt,
        // not something handed in, so it isn't news.
        $submissions = db_connect()->table('assessment_submissions s')
            ->select([
                's.submitted_at',
                's.is_late_submission',
                'a.title',
                "CONCAT(up.first_name, ' ', up.last_name) AS student_name",
            ])
            ->join('assessments a', 'a.id = s.assessment_id')
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->join(
                'enrollments e',
                "e.student_user_id = s.student_id AND e.offering_id = {$offeringId} AND e.status = 'enrolled'"
            )
            ->join('user_profiles up', 'up.user_id = s.student_id')
            ->where('cm.offering_id', $offeringId)
            ->where('s.status !=', 'In Progress')
            ->where('s.submitted_at >=', $since)
            ->where('s.submitted_at <=', date('Y-m-d H:i:s', $now))
            ->orderBy('s.submitted_at', 'DESC')
            ->limit(self::LIMIT)
            ->get()
            ->getResultArray();

        foreach ($submissions as $row) {

            $events[] = $this->event(
                'submission',
                $row['student_name'] . ' submitted work',
                $row['title'] . ($row['is_late_submission'] ? ' · late' : ''),
                $row['submitted_at']
            );

        }

        // Students who joined the section.
        $joined = db_connect()->table('enrollments e')
            ->select(['e.enrolled_at', "CONCAT(up.first_name, ' ', up.last_name) AS student_name"])
            ->join('user_profiles up', 'up.user_id = e.student_user_id')
            ->where('e.offering_id', $offeringId)
            ->where('e.status', 'enrolled')
            ->where('e.enrolled_at >=', $since)
            ->where('e.enrolled_at <=', date('Y-m-d H:i:s', $now))
            ->orderBy('e.enrolled_at', 'DESC')
            ->limit(self::LIMIT)
            ->get()
            ->getResultArray();

        foreach ($joined as $row) {

            $events[] = $this->event(
                'joined',
                $row['student_name'] . ' joined the section',
                'Enrolled',
                $row['enrolled_at'],
                $row['student_name'],
                'joined'
            );

        }

        return $this->finish(array_merge($events, $this->discussionEvents($offeringId, $since, $now, 0, true)));
    }

    /**
     * Published modules that appeared or changed. Students only ever see
     * published modules, so drafts are never announced to them.
     *
     * @return list<array>
     */
    protected function moduleEvents(int $offeringId, string $since, int $now): array
    {
        $modules = db_connect()->table('course_modules')
            ->select(['title', 'created_at', 'updated_at'])
            ->where('offering_id', $offeringId)
            ->where('status', 'published')
            ->where('updated_at >=', $since)
            ->where('updated_at <=', date('Y-m-d H:i:s', $now))
            ->orderBy('updated_at', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(function (array $module) {

            $isNew = abs(strtotime((string) $module['updated_at']) - strtotime((string) $module['created_at'])) < 60;

            return $this->event(
                'module',
                $isNew ? 'New module' : 'Module updated',
                $module['title'],
                $module['updated_at'],
                $module['title']
            );

        }, $modules);
    }

    /**
     * Discussions someone else started. A student hears about anyone's (but
     * not their own, and never a draft item's); an instructor only about
     * ones students started, since their own aren't news to them.
     *
     * @return list<array>
     */
    protected function discussionEvents(int $offeringId, string $since, int $now, int $exceptUserId, bool $studentsOnly): array
    {
        $builder = db_connect()->table('discussion_threads t')
            ->select(['t.title', 't.created_at', "CONCAT(up.first_name, ' ', up.last_name) AS author_name"])
            ->join('user_profiles up', 'up.user_id = t.author_id', 'left')
            ->join('learning_items li', 'li.id = t.learning_item_id', 'left')
            ->where('t.offering_id', $offeringId)
            ->where('t.created_at >=', $since)
            ->where('t.created_at <=', date('Y-m-d H:i:s', $now))
            ->orderBy('t.created_at', 'DESC')
            ->limit(self::LIMIT);

        if ($exceptUserId > 0) {
            $builder->where('t.author_id !=', $exceptUserId)
                ->groupStart()
                ->where('t.learning_item_id IS NULL', null, false)
                ->orWhere('li.status', 'published')
                ->groupEnd();
        }

        if ($studentsOnly) {
            $builder->where('t.learning_item_id IS NULL', null, false)
                ->join('auth_groups_users g', "g.user_id = t.author_id AND g.group = 'student'");
        }

        return array_map(fn (array $row) => $this->event(
            'discussion',
            'New discussion',
            trim((string) $row['author_name']) !== ''
                ? $row['title'] . ' · ' . trim((string) $row['author_name'])
                : $row['title'],
            $row['created_at'],
            $row['title']
        ), $builder->get()->getResultArray());
    }

    /*
    |--------------------------------------------------------------------------
    | Shaping
    |--------------------------------------------------------------------------
    */

    /**
     * `subject` is the short name of what the event is about (a student, a
     * module, an assessment) - only used to spell out a collapsed line.
     *
     * @return array{kind: string, title: string, detail: string, subject: string, when: string, ts: int}
     */
    protected function event(string $kind, string $title, string $detail, string $datetime, string $subject = '', ?string $group = null): array
    {
        return [
            'kind'    => $kind,
            'title'   => $title,
            'detail'  => $detail,
            // What makes two events "the same thing happening twice": by
            // default the title, but a title that carries a name ("Ana
            // joined the section") has to name its group explicitly.
            'group'   => $group ?? $title,
            'subject' => $subject,
            'when'    => $this->labels->relativeTime($datetime),
            'ts'      => (int) strtotime($datetime),
        ];
    }

    /**
     * Collapse bulk events, order newest first, cut to LIMIT.
     *
     * @param  list<array> $events
     * @return list<array>
     */
    protected function finish(array $events): array
    {
        $events = $this->collapse($events);

        usort($events, static fn (array $a, array $b) => $b['ts'] <=> $a['ts']);

        return array_slice($events, 0, self::LIMIT);
    }

    /**
     * Events of a collapsible kind with the same title in the same hour
     * become one line. A lone event is left exactly as it was.
     *
     * @param  list<array> $events
     * @return list<array>
     */
    protected function collapse(array $events): array
    {
        $groups = [];

        $kept = [];

        foreach ($events as $event) {

            if (! in_array($event['kind'], self::COLLAPSIBLE, true)) {
                $kept[] = $event;
                continue;
            }

            $groups[$event['kind'] . '|' . $event['group'] . '|' . date('Y-m-d H', $event['ts'])][] = $event;

        }

        foreach ($groups as $group) {

            $kept[] = count($group) === 1 ? $group[0] : $this->summarize($group);

        }

        return $kept;
    }

    /**
     * One line standing in for several events of the same kind.
     *
     * @param  list<array> $group
     * @return array
     */
    protected function summarize(array $group): array
    {
        $count = count($group);

        // Newest first inside the group, so the line is stamped with the
        // most recent of them.
        usort($group, static fn (array $a, array $b) => $b['ts'] <=> $a['ts']);

        $first = $group[0];

        $shown = array_slice(array_column($group, 'subject'), 0, self::COLLAPSED_NAMES);

        $title = match ($first['kind']) {
            'joined' => $count . ' students joined the section',
            'module' => $count . ($first['title'] === 'New module' ? ' new modules' : ' modules updated'),
            default  => $count . ' ' . $this->pluralize(lcfirst($first['title'])),
        };

        return [
            'kind'    => $first['kind'],
            'title'   => $title,
            'detail'  => implode(', ', $shown) . ($count > count($shown) ? ', +' . ($count - count($shown)) . ' more' : ''),
            'group'   => $first['group'],
            'subject' => '',
            'when'    => $first['when'],
            'ts'      => $first['ts'],
        ];
    }

    /** "new assignment" becomes "new assignments"; "new quiz" becomes "new quizzes". */
    protected function pluralize(string $phrase): string
    {
        return str_ends_with($phrase, 'z') ? $phrase . 'zes' : $phrase . 's';
    }

    protected function since(int $now): string
    {
        return date('Y-m-d H:i:s', $now - self::WINDOW_DAYS * 86400);
    }

    protected function excerpt(string $text, int $max = 70): string
    {
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
