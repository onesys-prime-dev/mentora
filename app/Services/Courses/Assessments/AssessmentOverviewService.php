<?php

namespace App\Services\Courses\Assessments;

use App\Services\Courses\LearningProgressService;
use CodeIgniter\Shield\Entities\User;

/**
 * Everything the Assessments tab's list page shows, per role:
 *
 *  - student:    Due This Week · Overdue · Completed; the list with the
 *                student's own status per assessment; Up Next (5) and the
 *                latest Feedback (3)
 *  - instructor: To Grade · Due This Week · Submission Rate · Missing
 *                Work; the list with per-assessment submission counts;
 *                Drafts (5) and Ready for Grading (5)
 *
 * Instructor numbers are scoped to the section (offering) being viewed.
 * A student's "attempt" is their latest finished one (anything but In
 * Progress), falling back to an in-progress one — the same rule the
 * grading screen uses — so each assessment appears once.
 */
class AssessmentOverviewService
{
    public const SOON_DAYS = 7;
    public const SIDEBAR_LIMIT = 5;
    public const FEEDBACK_LIMIT = 3;

    /** Statuses that count as "handed in". */
    public const SUBMITTED_STATUSES = ['Submitted', 'Partially Graded', 'Grading', 'Graded', 'Returned'];

    /** Handed in but not graded yet. */
    public const TO_GRADE_STATUSES = ['Submitted', 'Partially Graded', 'Grading'];

    protected AssessmentService $assessmentService;

    protected AssessmentResultService $resultService;

    public function __construct(?AssessmentService $assessmentService = null)
    {
        $this->assessmentService = $assessmentService ?? new AssessmentService();

        $this->resultService = new AssessmentResultService();
    }

    public function build(User $user, int $offeringId, int $courseId): array
    {
        $assessments = $this->courseAssessments($courseId, $offeringId);

        // forInstructor() is offering-scoped, not "my own sections" scoped —
        // access to the offering itself is already decided at the route
        // (AssessmentScope), so a chairperson viewing a course in their
        // program gets the same instructor-shaped overview, not the
        // student one.
        return $user->inGroup('instructor') || $user->inGroup('chairperson')
            ? $this->forInstructor($offeringId, $assessments)
            : $this->forStudent($user, $assessments);
    }

    /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */

    private function forStudent(User $user, array $assessments): array
    {
        $visible = $this->assessmentService->studentVisibleIds($assessments);

        $assessments = array_values(array_filter(
            $assessments,
            static fn (array $a) => isset($visible[(int) $a['id']])
        ));

        $attempts = $this->latestAttempts(array_column($assessments, 'id'), [(int) $user->id]);

        $progress = new LearningProgressService();

        $now = time();

        // One batched lookup for every assessment's learning item, rather
        // than re-reading a unit per assessment.
        $locks = $progress->locksFor($user, array_values(array_filter(array_map(
            static fn (array $a) => (int) ($a['learning_item_id'] ?? 0),
            $assessments
        ))));

        $rows = [];

        foreach ($assessments as $assessment) {

            $id = (int) $assessment['id'];

            $attempt = $attempts[$id][(int) $user->id] ?? null;

            $lock = !empty($assessment['learning_item_id'])
                ? ($locks[(int) $assessment['learning_item_id']] ?? null)
                : null;

            $assessment['student'] = $this->studentState($assessment, $attempt, $lock, $now);

            $rows[] = $assessment;

        }

        /*
        | Metrics
        */

        $soon = $now + self::SOON_DAYS * 86400;

        $unsubmitted = array_values(array_filter($rows, static fn (array $r) => !$r['student']['submitted']));

        $dueThisWeek = array_values(array_filter(
            $unsubmitted,
            static fn (array $r) => $r['due_ts'] !== null && $r['due_ts'] >= $now && $r['due_ts'] <= $soon && $r['status'] === 'Published'
        ));

        $overdue = array_values(array_filter(
            $unsubmitted,
            static fn (array $r) => $r['due_ts'] !== null && $r['due_ts'] < $now
        ));

        $acceptingLate = count(array_filter($overdue, static fn (array $r) => $r['student']['key'] === 'overdue'));

        $submittedCount = count($rows) - count($unsubmitted);

        $next = $dueThisWeek[0] ?? null;

        $metrics = [
            [
                'label' => 'Due This Week',
                'icon' => 'schedule',
                'tone' => 'neutral',
                'value' => count($dueThisWeek) . ' ' . (count($dueThisWeek) === 1 ? 'task' : 'tasks'),
                'detail' => $next
                    ? 'Next: ' . $next['title'] . ' · ' . $this->dueLabel($next['due_ts'], $now)
                    : 'Nothing due in the next 7 days',
            ],
            [
                'label' => 'Overdue',
                'icon' => 'attention',
                'tone' => $overdue ? 'danger' : 'neutral',
                'value' => count($overdue) . ' ' . (count($overdue) === 1 ? 'task' : 'tasks'),
                'detail' => match (true) {
                    !$overdue => "You're all caught up",
                    $acceptingLate > 0 => $acceptingLate . ' still ' . ($acceptingLate === 1 ? 'accepts' : 'accept') . ' late work',
                    default => 'No longer accepting submissions',
                },
            ],
            [
                'label' => 'Completed',
                'icon' => 'tasks',
                'tone' => 'neutral',
                'value' => $submittedCount . ' of ' . count($rows),
                'detail' => 'submitted',
            ],
        ];

        /*
        | Up Next — in-progress quizzes first, then open work by due date
        | (late-but-accepted first); no due date last.
        */

        $upNext = array_values(array_filter($rows, static fn (array $r) => $r['student']['actionable']));

        usort($upNext, static function (array $a, array $b): int {
            $rank = static fn (array $r) => match ($r['student']['key']) {
                'in_progress' => 0,
                'overdue' => 1,
                default => 2,
            };

            return [$rank($a), $a['due_ts'] ?? PHP_INT_MAX, (int) $a['id']]
                <=> [$rank($b), $b['due_ts'] ?? PHP_INT_MAX, (int) $b['id']];
        });

        $upNext = array_map(function (array $r) use ($now) {
            $r['nextDetail'] = match (true) {
                $r['student']['key'] === 'in_progress' => !empty($r['duration']) ? 'Resume · timer running' : 'Resume where you left off',
                $r['student']['key'] === 'overdue' => 'Late · was due ' . date('M j', $r['due_ts']),
                $r['due_ts'] !== null => 'Due ' . $this->dueLabel($r['due_ts'], $now),
                default => 'No due date',
            };
            return $r;
        }, array_slice($upNext, 0, self::SIDEBAR_LIMIT));

        return [
            'role' => 'student',
            'metrics' => $metrics,
            'assessments' => $rows,
            'upNext' => $upNext,
            'feedback' => $this->recentFeedback((int) $user->id, $rows),
        ];
    }

    /**
     * The student's standing on one assessment: a status chip and whether
     * there's something to do now.
     */
    private function studentState(array $assessment, ?array $attempt, ?array $lock, int $now): array
    {
        // Quiz: highest graded attempt counts; assignment: latest graded
        // submission (see AssessmentResultService).
        $result = $this->resultService->resultFor((bool) $assessment['is_quiz'], $attempt['attempts'] ?? []);

        $inProgress = $attempt['inProgress'] ?? null;

        $finishedCount = (int) ($attempt['finishedCount'] ?? 0);

        $submitted = $result['finished'] !== [];

        $attemptLimit = max(1, (int) ($assessment['attempt_limit'] ?? 1));

        $state = static fn (string $key, string $label, string $tone, bool $actionable = false) => [
            'key' => $key,
            'label' => $label,
            'tone' => $tone,
            'actionable' => $actionable,
            'submitted' => $submitted,
            'score' => $result['score'],
            'attempt' => $result['counted'] ?? $result['latest'],
            'result' => $result,
            'inProgress' => $inProgress !== null,
            'lock' => null,
        ];

        if ($lock) {
            $result = $state('locked', 'Locked', 'muted');
            $result['lock'] = $lock;
            return $result;
        }

        $open = $assessment['status'] === 'Published'
            && (empty($assessment['available_from']) || strtotime($assessment['available_from']) <= $now)
            && (empty($assessment['available_until']) || strtotime($assessment['available_until']) >= $now);

        if ($inProgress && $open) {
            return $state('in_progress', 'In progress', 'warning', true);
        }

        if ($submitted) {

            // Assignment: a newer submission waits while the previous grade stands.
            if ($result['resubmitted']) {
                return $state('resubmitted', 'Resubmitted', 'info');
            }

            // Quiz: the best graded attempt shows even if another one waits.
            if ($result['counted'] !== null) {
                return $state('graded', 'Graded', 'success');
            }

            // Auto-graded in part: show the score so far.
            if ($result['partial'] !== null) {
                $partial = $state('partially_graded', 'Partially graded', 'warning');
                $partial['score'] = (float) $result['partial']['score'];
                return $partial;
            }

            $waiting = $result['pending'] === [] ? $result['latest'] : $result['pending'][count($result['pending']) - 1];

            return ($waiting['status'] ?? '') === 'Grading'
                ? $state('grading', 'Being graded', 'warning')
                : $state('submitted', 'Submitted', 'info');

        }

        $attemptsLeft = $finishedCount < $attemptLimit;

        if ($assessment['status'] === 'Closed' || (!empty($assessment['available_until']) && strtotime($assessment['available_until']) < $now)) {
            return $state('closed', 'Closed', 'muted');
        }

        if (!empty($assessment['available_from']) && strtotime($assessment['available_from']) > $now) {
            return $state('upcoming', 'Opens ' . date('M j', strtotime($assessment['available_from'])), 'muted');
        }

        if ($assessment['due_ts'] !== null && $assessment['due_ts'] < $now) {
            return !empty($assessment['allow_late_submission']) && $attemptsLeft
                ? $state('overdue', 'Overdue', 'danger', true)
                : $state('missed', 'Missed', 'danger');
        }

        if ($assessment['due_ts'] !== null && $assessment['due_ts'] <= $now + self::SOON_DAYS * 86400) {
            return $state('due_soon', 'Due ' . $this->dueLabel($assessment['due_ts'], $now, false), 'info', $attemptsLeft);
        }

        return $state('not_started', 'Not started', 'neutral', $attemptsLeft);
    }

    /**
     * The student's latest graded attempts that carry written feedback.
     */
    private function recentFeedback(int $studentId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $byId = array_column($rows, null, 'id');

        $graded = db_connect()->table('assessment_submissions')
            ->select(['id', 'assessment_id', 'score', 'graded_at'])
            ->select(
                "(SELECT message FROM assessment_feedbacks
                    WHERE submission_id = assessment_submissions.id
                    ORDER BY id DESC LIMIT 1) AS feedback",
                false
            )
            ->select(
                "(SELECT COUNT(*) FROM assessment_feedbacks
                    WHERE submission_id = assessment_submissions.id) AS message_count",
                false
            )
            ->where('student_id', $studentId)
            ->whereIn('assessment_id', array_keys($byId))
            ->where('is_graded', 1)
            ->where('EXISTS (SELECT 1 FROM assessment_feedbacks WHERE submission_id = assessment_submissions.id)', null, false)
            ->orderBy('graded_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(self::FEEDBACK_LIMIT)
            ->get()
            ->getResultArray();

        return array_map(fn (array $g) => [
            'assessment' => $byId[$g['assessment_id']],
            'score' => (float) $g['score'],
            'feedback' => trim((string) $g['feedback']),
            'message_count' => (int) $g['message_count'],
            'when' => $this->relativeTime($g['graded_at']),
        ], $graded);
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor
    |--------------------------------------------------------------------------
    */

    private function forInstructor(int $offeringId, array $assessments): array
    {
        $students = $this->sectionStudentIds($offeringId);

        $attempts = $this->latestAttempts(array_column($assessments, 'id'), $students);

        $now = time();

        $soon = $now + self::SOON_DAYS * 86400;

        $rows = [];

        $toGradeTotal = 0;
        $oldestWaiting = null;

        foreach ($assessments as $assessment) {

            $id = (int) $assessment['id'];

            $submitted = 0;
            $toGrade = 0;
            $waitingSince = null;
            $firstWaitingStudent = null;

            foreach ($attempts[$id] ?? [] as $studentId => $attempt) {

                $result = $this->resultService->resultFor((bool) $assessment['is_quiz'], $attempt['attempts']);

                if ($result['finished'] === []) {
                    continue;
                }

                $submitted++;

                // Attempts that still need grading and can change the grade:
                // any ungraded quiz attempt, or an assignment's latest
                // submission.
                foreach ($result['pending'] as $pendingAttempt) {

                    $toGrade++;

                    $at = strtotime($pendingAttempt['submitted_at'] ?? '') ?: null;

                    if ($at !== null && ($waitingSince === null || $at < $waitingSince)) {
                        $waitingSince = $at;
                        $firstWaitingStudent = (int) $studentId;
                    }

                    $firstWaitingStudent ??= (int) $studentId;

                }

            }

            $assessment['section'] = [
                'students' => count($students),
                'submitted' => $submitted,
                'toGrade' => $toGrade,
                'waitingSince' => $waitingSince,
                'firstWaitingStudent' => $firstWaitingStudent,
            ];

            // Archived assessments are retired: they leave the grading queue.
            if ($assessment['status'] !== 'Archived') {

                $toGradeTotal += $toGrade;

                if ($waitingSince !== null && ($oldestWaiting === null || $waitingSince < $oldestWaiting)) {
                    $oldestWaiting = $waitingSince;
                }

            }

            $rows[] = $assessment;

        }

        /*
        | Metrics
        */

        $published = array_values(array_filter($rows, static fn (array $r) => $r['status'] === 'Published'));

        $dueThisWeek = array_values(array_filter(
            $published,
            static fn (array $r) => $r['due_ts'] !== null && $r['due_ts'] >= $now && $r['due_ts'] <= $soon
        ));

        $pastDue = array_values(array_filter(
            $rows,
            static fn (array $r) => in_array($r['status'], ['Published', 'Closed'], true) && $r['due_ts'] !== null && $r['due_ts'] < $now
        ));

        $expected = count($students) * count($pastDue);

        $handedIn = array_sum(array_map(static fn (array $r) => $r['section']['submitted'], $pastDue));

        $missing = max(0, $expected - $handedIn);

        $studentsMissing = [];

        foreach ($pastDue as $row) {
            foreach ($students as $studentId) {
                $turnedIn = $this->resultService->resultFor(
                    (bool) $row['is_quiz'],
                    $attempts[(int) $row['id']][$studentId]['attempts'] ?? []
                )['finished'];
                if ($turnedIn === []) {
                    $studentsMissing[$studentId] = true;
                }
            }
        }

        $next = $dueThisWeek[0] ?? null;

        // Published with a deadline still ahead: what could become missing.
        $stillOpen = count(array_filter(
            $published,
            static fn (array $r) => $r['due_ts'] !== null && $r['due_ts'] >= $now
        ));

        $metrics = [
            [
                'label' => 'To Grade',
                'icon' => 'tasks',
                'tone' => $toGradeTotal > 0 ? 'warning' : 'neutral',
                'value' => $toGradeTotal . ' ' . ($toGradeTotal === 1 ? 'submission' : 'submissions'),
                'detail' => $oldestWaiting !== null
                    ? 'Oldest waiting ' . $this->waitingLabel($oldestWaiting, $now)
                    : 'Nothing waiting',
            ],
            [
                'label' => 'Due This Week',
                'icon' => 'schedule',
                'tone' => 'neutral',
                'value' => count($dueThisWeek) . ' ' . (count($dueThisWeek) === 1 ? 'assessment' : 'assessments'),
                'detail' => $next
                    ? 'Next: ' . $next['title'] . ' · ' . $this->dueLabel($next['due_ts'], $now)
                    : 'Nothing due in the next 7 days',
            ],
            [
                'label' => 'Submission Rate',
                'icon' => 'score',
                'tone' => 'neutral',
                'value' => $expected > 0 ? (int) round($handedIn / $expected * 100) . '%' : '—',
                'detail' => $expected > 0
                    ? 'on ' . count($pastDue) . ' past-due ' . (count($pastDue) === 1 ? 'assessment' : 'assessments')
                    : 'No past-due assessments yet',
            ],
            // The count behind Submission Rate: work that was due, never
            // arrived, and who it's missing from. Work isn't "missing" until
            // its due date has passed, so a student who hasn't handed in
            // something still open doesn't count — the detail line says so,
            // otherwise "None" reads as a bug next to a student who has
            // submitted nothing. (Drafts that are due but unpublished are
            // flagged in the Drafts list, not here.)
            [
                'label' => 'Missing Work',
                'icon' => 'attention',
                'tone' => $missing > 0 ? 'danger' : 'neutral',
                'value' => $missing > 0
                    ? $missing . ' ' . ($missing === 1 ? 'submission' : 'submissions')
                    : 'None',
                'detail' => $missing > 0
                    ? 'from ' . count($studentsMissing) . ' ' . (count($studentsMissing) === 1 ? 'student' : 'students')
                    : implode(' · ', array_filter([
                        $expected > 0 ? 'Everyone is caught up' : 'Nothing past due yet',
                        $stillOpen > 0
                            ? $stillOpen . ' ' . ($stillOpen === 1 ? 'assessment' : 'assessments') . ' still open'
                            : null,
                    ])),
            ],
        ];

        /*
        | Drafts — due within a week (or already past due) first, since
        | students can't see them yet; then the most recently edited.
        */

        $drafts = array_values(array_filter($rows, static fn (array $r) => $r['status'] === 'Draft'));

        usort($drafts, static function (array $a, array $b) use ($soon): int {
            $urgentA = $a['due_ts'] !== null && $a['due_ts'] <= $soon;
            $urgentB = $b['due_ts'] !== null && $b['due_ts'] <= $soon;

            if ($urgentA !== $urgentB) {
                return $urgentA ? -1 : 1;
            }

            return $urgentA
                ? [$a['due_ts'], (int) $a['id']] <=> [$b['due_ts'], (int) $b['id']]
                : [strtotime($b['updated_at'] ?? $b['created_at'] ?? '') ?: 0, (int) $b['id']]
                    <=> [strtotime($a['updated_at'] ?? $a['created_at'] ?? '') ?: 0, (int) $a['id']];
        });

        $drafts = array_map(function (array $d) use ($now, $soon) {
            $d['edited'] = $this->relativeTime($d['updated_at'] ?? $d['created_at'] ?? null);
            $d['dueWarning'] = $d['due_ts'] !== null && $d['due_ts'] <= $soon
                ? ($d['due_ts'] < $now ? 'Past due — still a draft' : 'Due ' . $this->dueLabel($d['due_ts'], $now, false) . ' — still a draft')
                : null;
            return $d;
        }, $drafts);

        /*
        | Ready for Grading — longest-waiting first.
        */

        $ready = array_values(array_filter(
            $rows,
            static fn (array $r) => $r['section']['toGrade'] > 0 && $r['status'] !== 'Archived'
        ));

        usort($ready, static fn (array $a, array $b) => [$a['section']['waitingSince'] ?? PHP_INT_MAX, (int) $a['id']]
            <=> [$b['section']['waitingSince'] ?? PHP_INT_MAX, (int) $b['id']]);

        $ready = array_map(function (array $r) use ($now) {
            $r['waiting'] = $r['section']['waitingSince'] !== null ? $this->waitingLabel($r['section']['waitingSince'], $now) : null;
            return $r;
        }, $ready);

        return [
            'role' => 'instructor',
            'metrics' => $metrics,
            'assessments' => $rows,
            'drafts' => array_slice($drafts, 0, self::SIDEBAR_LIMIT),
            'draftCount' => count($drafts),
            'readyForGrading' => array_slice($ready, 0, self::SIDEBAR_LIMIT),
            'readyCount' => count($ready),
            'toGradeTotal' => $toGradeTotal,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shared data
    |--------------------------------------------------------------------------
    */

    /**
     * Students enrolled in a section.
     *
     * @return list<int>
     */
    public function sectionStudentIds(int $offeringId): array
    {
        return array_map(
            'intval',
            array_column(
                db_connect()->table('enrollments')
                    ->select('student_user_id')
                    ->where('offering_id', $offeringId)
                    ->whereIn('status', ['enrolled', 'completed'])
                    ->get()
                    ->getResultArray(),
                'student_user_id'
            )
        );
    }

    /**
     * Each student's attempts per assessment, reduced to what the pages
     * need: the latest finished attempt, any in-progress attempt, how many
     * finished attempts they've used, and every attempt (for
     * AssessmentResultService).
     *
     * @return array<int, array<int, array{finished: ?array, inProgress: ?array, finishedCount: int, attempts: list<array>}>>
     *         [assessmentId][studentId]
     */
    public function latestAttempts(array $assessmentIds, array $studentIds): array
    {
        if ($assessmentIds === [] || $studentIds === []) {
            return [];
        }

        $rows = db_connect()->table('assessment_submissions')
            ->select(['id', 'assessment_id', 'student_id', 'attempt_number', 'status', 'score', 'is_graded', 'started_at', 'submitted_at', 'graded_at'])
            ->whereIn('assessment_id', array_map('intval', $assessmentIds))
            ->whereIn('student_id', array_map('intval', $studentIds))
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $map = [];

        foreach ($rows as $row) {

            $slot = &$map[(int) $row['assessment_id']][(int) $row['student_id']];

            $slot ??= ['finished' => null, 'inProgress' => null, 'finishedCount' => 0, 'attempts' => []];

            $slot['attempts'][] = $row;

            if ($row['status'] === 'In Progress') {
                $slot['inProgress'] = $row;
            } else {
                $slot['finished'] = $row;
                $slot['finishedCount']++;
            }

            unset($slot);

        }

        return $map;
    }

    /**
     * This section's assessments with type name, late-submission setting and
     * active question count; due_ts is the due date as a timestamp (or null).
     *
     * Sections of one course share course_id, so the section is found through
     * the assessment's module — otherwise each section would list the other's.
     */
    private function courseAssessments(int $courseId, int $offeringId): array
    {
        $rows = db_connect()->table('assessments a')
            ->select([
                'a.*',
                'at.name AS assessment_type',
                'st.allow_late_submission',
                "(SELECT COUNT(*) FROM assessment_questions q WHERE q.assessment_id = a.id AND q.status = 'Active') AS question_count",
            ])
            ->join('assessment_types at', 'at.id = a.assessment_type_id', 'left')
            ->join('assessment_settings st', 'st.assessment_id = a.id', 'left')
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->where('a.course_id', $courseId)
            ->where('cm.offering_id', $offeringId)
            ->orderBy('a.due_date IS NULL', 'ASC', false)
            ->orderBy('a.due_date', 'ASC')
            ->orderBy('a.id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(static function (array $row) {
            $row['due_ts'] = !empty($row['due_date']) ? strtotime($row['due_date']) : null;
            $row['is_quiz'] = strtolower((string) $row['assessment_type']) === 'quiz';
            return $row;
        }, $rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Labels
    |--------------------------------------------------------------------------
    */

    /** "today 8:00 AM", "tomorrow 8:00 AM", "Fri 8:00 AM", "Oct 3" (or without the time). */
    public function dueLabel(int $timestamp, int $now, bool $withTime = true): string
    {
        $days = (int) floor((strtotime(date('Y-m-d', $timestamp)) - strtotime(date('Y-m-d', $now))) / 86400);

        $time = $withTime ? ' ' . date('g:i A', $timestamp) : '';

        return match (true) {
            $days === 0 => 'today' . $time,
            $days === 1 => 'tomorrow' . $time,
            $days > 1 && $days < 7 => 'in ' . $days . ' days',
            default => date('M j', $timestamp),
        };
    }

    /** "2 hours", "3 days" — how long something has been waiting. */
    private function waitingLabel(int $since, int $now): string
    {
        $minutes = max(0, (int) floor(($now - $since) / 60));

        return match (true) {
            $minutes < 60 => max(1, $minutes) . ' min',
            $minutes < 1440 => (int) floor($minutes / 60) . ' ' . ((int) floor($minutes / 60) === 1 ? 'hour' : 'hours'),
            default => (int) floor($minutes / 1440) . ' ' . ((int) floor($minutes / 1440) === 1 ? 'day' : 'days'),
        };
    }

    /** "just now", "5 min ago", "2h ago", "Yesterday", "3 days ago", "Mar 4". */
    public function relativeTime(?string $datetime): string
    {
        if (!$datetime || !($ts = strtotime($datetime))) {
            return '';
        }

        $minutes = (int) floor((time() - $ts) / 60);

        return match (true) {
            $minutes < 1 => 'just now',
            $minutes < 60 => $minutes . ' min ago',
            $minutes < 1440 => (int) floor($minutes / 60) . 'h ago',
            $minutes < 2880 => 'Yesterday',
            $minutes < 10080 => (int) floor($minutes / 1440) . ' days ago',
            default => date('M j', $ts),
        };
    }
}
