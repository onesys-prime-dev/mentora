<?php

declare(strict_types=1);

namespace App\Services\Courses\Grades;

use App\Models\Assessments\GradeSignoffModel;
use App\Models\Courses\OfferingDetail;
use App\Services\Auth\ActiveRoleService;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\MonitorProgramService;
use App\Services\Notifications\NotificationService;
use CodeIgniter\Shield\Entities\User;

/**
 * The dean's sign-off of a section's finalized grades.
 *
 * Where the institution has a dean, the instructor finalizes the grades as
 * always (GradeFinalizationService freezes and locks them) and the dean then
 * signs them off. A section is ready for sign-off when its grades are finalized
 * and no override request is open; otherwise it says what is in the way.
 * Sign-off adds no lock of its own, finalization already locks the gradebook, but
 * it takes the power to reopen away from the instructor and the chairperson: only
 * the dean can end a sign-off, with a reason. An institution with no dean is not
 * affected at all.
 */
class GradeSignoffService
{
    public const SIGNED_OFF = 'signed_off';
    public const READY      = 'ready';
    public const BLOCKED    = 'blocked';

    public function __construct(private readonly NotificationService $notifications = new NotificationService())
    {
    }

    /** Does a sign-off apply to this offering? Only in an institution that has a dean. */
    public function appliesTo(int $offeringId): bool
    {
        return (new GradeOverrideRequestService())->appliesTo($offeringId);
    }

    /** @return array<string, mixed>|null the current sign-off with who signed, or null */
    public function current(int $offeringId): ?array
    {
        $row = db_connect()->table('grade_signoffs s')
            ->select("s.*, CONCAT_WS(' ', up.first_name, up.last_name) AS signed_by_name", false)
            ->join('user_profiles up', 'up.user_id = s.signed_by', 'left')
            ->where(['s.offering_id' => $offeringId, 's.open_marker' => 1])
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function isSignedOff(int $offeringId): bool
    {
        return (new GradeSignoffModel())->currentFor($offeringId) !== null;
    }

    /**
     * Where an offering stands: signed off, ready for the dean, or blocked and why.
     *
     * @return array{state: string, blockers: list<string>, counts: array{ungraded_work: int, open_requests: int}, signoff: array<string, mixed>|null}
     */
    public function status(int $offeringId): array
    {
        $signoff = $this->current($offeringId);
        $counts  = ['ungraded_work' => 0, 'open_requests' => 0];

        if ($signoff !== null) {
            return ['state' => self::SIGNED_OFF, 'blockers' => [], 'counts' => $counts, 'signoff' => $signoff];
        }

        $blockers     = [];
        $finalization = new GradeFinalizationService();

        if (! $finalization->isFinalized($offeringId)) {
            $blockers[] = 'The instructor has not finalized the grades yet.';

            // What the instructor still has to do first, so the dean knows what the wait is about.
            $counts['ungraded_work'] = $finalization->readiness($offeringId)['counts']['ungraded_work'];

            if ($counts['ungraded_work'] > 0) {
                $blockers[] = $counts['ungraded_work'] . ($counts['ungraded_work'] === 1 ? ' piece of handed-in work is' : ' pieces of handed-in work are') . ' still waiting to be graded.';
            }
        }

        $counts['open_requests'] = db_connect()->table('grade_override_requests')->where(['offering_id' => $offeringId, 'open_marker' => 1])->countAllResults();

        if ($counts['open_requests'] > 0) {
            $blockers[] = $counts['open_requests'] . ($counts['open_requests'] === 1 ? ' grade-override request is' : ' grade-override requests are') . ' still open.';
        }

        return ['state' => $blockers === [] ? self::READY : self::BLOCKED, 'blockers' => $blockers, 'counts' => $counts, 'signoff' => null];
    }

    /**
     * The dean signs off one section.
     *
     * @return array{success: bool, message: string}
     */
    public function signOff(User $dean, int $offeringId): array
    {
        $refusal = $this->mayDecide($dean, $offeringId);

        if ($refusal !== null) {
            return $this->refused($refusal);
        }

        $status = $this->status($offeringId);

        if ($status['state'] === self::SIGNED_OFF) {
            return $this->refused('These grades are already signed off.');
        }

        if ($status['state'] !== self::READY) {
            return $this->refused($status['blockers'][0]);
        }

        try {
            $id = (int) (new GradeSignoffModel())->insert([
                'offering_id' => $offeringId,
                'signed_by'   => (int) $dean->id,
                'signed_at'   => date('Y-m-d H:i:s'),
                'open_marker' => 1,
            ], true);
        } catch (\Throwable) {
            // Two sign-offs at once: the unique key let only one in.
            return $this->refused('These grades are already signed off.');
        }

        GradeAudit::record('grades.signed_off', $offeringId, ['new' => ['signoff_id' => $id]], $dean);

        $this->tell($offeringId, $dean, "grade-signoff:{$id}:signed", 'Grades signed off', 'the dean signed off the final grades.');

        return ['success' => true, 'message' => 'Signed off.'];
    }

    /**
     * The dean signs off every ready section of a program in a term; the others are left as they are.
     *
     * @return array{success: bool, message: string, signed: int, left: int}
     */
    public function signOffProgram(User $dean, int $programId, int $termId): array
    {
        if (! (new DeanAccessService())->canAccessProgram($dean, $programId)) {
            return ['success' => false, 'message' => 'That program is not in your department.', 'signed' => 0, 'left' => 0];
        }

        $signed = 0;
        $left   = 0;

        foreach (array_column(OfferingDetail::inPrograms([$programId])->where('academic_terms.id', $termId)->get()->getResultArray(), 'offering_id') as $offeringId) {
            $offeringId = (int) $offeringId;

            if ($this->status($offeringId)['state'] === self::SIGNED_OFF) {
                continue;
            }

            $this->signOff($dean, $offeringId)['success'] ? $signed++ : $left++;
        }

        return [
            'success' => true,
            'message' => $signed === 0 ? 'Nothing was ready to sign off.' : "Signed off {$signed} " . ($signed === 1 ? 'section' : 'sections') . ($left > 0 ? ", {$left} not ready were left." : '.'),
            'signed'  => $signed,
            'left'    => $left,
        ];
    }

    /**
     * The dean ends a sign-off, with the reason the instructor and chairperson will read. The grades stay
     * finalized: ending the sign-off gives nobody a way to edit them, it only takes the dean's seal off.
     *
     * @return array{success: bool, message: string}
     */
    public function reopen(User $dean, int $offeringId, string $reason): array
    {
        $refusal = $this->mayDecide($dean, $offeringId);

        if ($refusal !== null) {
            return $this->refused($refusal);
        }

        if (trim($reason) === '') {
            return $this->refused('Give a reason for reopening, so the instructor knows why.');
        }

        $current = $this->current($offeringId);

        if ($current === null) {
            return $this->refused('These grades are not signed off.');
        }

        db_connect()->table('grade_signoffs')->where(['id' => $current['id'], 'open_marker' => 1])->update([
            'reopened_by'   => (int) $dean->id,
            'reopened_at'   => date('Y-m-d H:i:s'),
            'reopen_reason' => trim($reason),
            'open_marker'   => null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        GradeAudit::record('grades.signoff_reopened', $offeringId, ['new' => ['signoff_id' => (int) $current['id'], 'reason' => trim($reason)]], $dean);

        $this->tell($offeringId, $dean, "grade-signoff:{$current['id']}:reopened", 'Sign-off reopened', 'the dean reopened the sign-off of the final grades. Reason: ' . trim($reason));

        return ['success' => true, 'message' => 'Sign-off ended. The grades stay finalized.'];
    }

    /** The dean is told a section is ready for them, once, when its grades are finalized. */
    public function announceReady(int $offeringId): void
    {
        if (! $this->appliesTo($offeringId) || $this->status($offeringId)['state'] !== self::READY) {
            return;
        }

        $section = $this->section($offeringId);

        if ($section === null) {
            return;
        }

        $dean = (new \App\Models\Assignments\DeanAssignmentModel())->currentDeanOfDepartment((int) $section['department_id']);

        if ($dean === null) {
            return;
        }

        $this->notifications->send(
            (int) $dean['dean_user_id'],
            'grade_signoff',
            'Grades ready for sign-off',
            trim($section['course_code'] . ' ' . $section['section_code']) . ': the grades are finalized and ready for your sign-off.',
            false,
            "grade-signoff:ready:{$offeringId}:" . date('YmdHis'),
            (int) $section['institution_id'],
            site_url('dean/signoff'),
        );
    }

    /**
     * How many sections of the dean's department are ready, blocked and signed off in a term (the newest unless
     * one is asked for). The same answer as the sign-off board gives, worked out in a handful of queries for
     * the dashboard instead of one status check per section.
     *
     * @return array{term: ?int, ready: int, blocked: int, signed_off: int}
     */
    public function counts(User $dean, ?int $termId = null): array
    {
        $none       = ['term' => null, 'ready' => 0, 'blocked' => 0, 'signed_off' => 0];
        $programIds = (new DeanAccessService())->programIds($dean);
        $terms      = (new MonitorProgramService())->termsForPrograms($programIds);

        $known  = array_map(static fn (array $t): int => (int) $t['id'], $terms);
        $termId = $termId !== null && in_array($termId, $known, true) ? $termId : ($known[0] ?? null);

        if ($termId === null || $programIds === []) {
            return $none;
        }

        $ids = array_map('intval', array_column(OfferingDetail::inPrograms($programIds)->where('academic_terms.id', $termId)->get()->getResultArray(), 'offering_id'));

        if ($ids === []) {
            return ['term' => $termId] + $none;
        }

        $db     = db_connect();
        $signed = array_flip(array_map('intval', array_column($db->table('grade_signoffs')->select('offering_id')->whereIn('offering_id', $ids)->where('open_marker', 1)->get()->getResultArray(), 'offering_id')));
        $final  = array_flip(array_map('intval', array_column($db->table('grade_finalizations')->select('course_offering_id')->whereIn('course_offering_id', $ids)->where('open_marker', 1)->get()->getResultArray(), 'course_offering_id')));
        $open   = array_flip(array_map('intval', array_column($db->table('grade_override_requests')->select('offering_id')->whereIn('offering_id', $ids)->where('open_marker', 1)->get()->getResultArray(), 'offering_id')));

        $counts = ['term' => $termId, 'ready' => 0, 'blocked' => 0, 'signed_off' => 0];

        foreach ($ids as $id) {
            if (isset($signed[$id])) {
                $counts['signed_off']++;
            } elseif (! isset($final[$id]) || isset($open[$id])) {
                $counts['blocked']++;
            } else {
                $counts['ready']++;
            }
        }

        return $counts;
    }

    /**
     * What the dean's sign-off page shows for a term: the department's programs, each with its sections and
     * where each stands.
     *
     * @return array{terms: list<array<string, mixed>>, term: ?int, programs: list<array<string, mixed>>}
     */
    public function board(User $dean, ?int $termId): array
    {
        $access     = new DeanAccessService();
        $programIds = $access->programIds($dean);
        $terms      = (new MonitorProgramService())->termsForPrograms($programIds);

        // A term that is not one of the department's falls back to the newest.
        $known  = array_map(static fn (array $t): int => (int) $t['id'], $terms);
        $termId = $termId !== null && in_array($termId, $known, true) ? $termId : ($known[0] ?? null);

        if ($termId === null || $programIds === []) {
            return ['terms' => $terms, 'term' => $termId, 'programs' => []];
        }

        $rows = OfferingDetail::inPrograms($programIds)->where('academic_terms.id', $termId)
            ->orderBy('programs.name', 'ASC')->orderBy('courses.course_code', 'ASC')->orderBy('course_offerings.section_code', 'ASC')
            ->get()->getResultArray();

        $programs = [];

        foreach ($rows as $row) {
            $programId = (int) $row['program_id'];
            $status    = $this->status((int) $row['offering_id']);

            $programs[$programId] ??= ['id' => $programId, 'name' => (string) $row['program_name'], 'sections' => [], 'counts' => [self::READY => 0, self::BLOCKED => 0, self::SIGNED_OFF => 0]];

            $programs[$programId]['counts'][$status['state']]++;
            $programs[$programId]['sections'][] = [
                'offering_id'    => (int) $row['offering_id'],
                'course_code'    => $row['course_code'],
                'section_code'   => $row['section_code'],
                'course_title'   => $row['course_title'],
                'slug'           => url_title((string) $row['course_title'], '-', true),
                'instructor'     => trim((string) $row['instructor_name']) !== '' ? $row['instructor_name'] : null,
                'students'       => (int) $row['enrolled'],
                'state'          => $status['state'],
                'blockers'       => $status['blockers'],
                'counts'         => $status['counts'],
                'grades_url'     => site_url('dean/courses/' . (int) $row['offering_id'] . '/' . url_title((string) $row['course_title'], '-', true) . '/grades'),
                'signed_by_name' => $status['signoff']['signed_by_name'] ?? null,
                'signed_at'      => $status['signoff']['signed_at'] ?? null,
            ];
        }

        return ['terms' => $terms, 'term' => $termId, 'programs' => array_values($programs)];
    }

    /** Why this person may not decide on this offering, or null when they may: the dean of its department, acting as dean. */
    private function mayDecide(User $dean, int $offeringId): ?string
    {
        if ((new ActiveRoleService())->resolve($dean) !== 'dean' || ! (new DeanAccessService())->canAccessOffering($dean, $offeringId)) {
            return 'That section is not in your department.';
        }

        if (! $this->appliesTo($offeringId)) {
            return 'Sign-off is not used in this institution.';
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function section(int $offeringId): ?array
    {
        return db_connect()->table('course_offerings co')
            ->select(['d.institution_id', 'd.id AS department_id', 'sd.id AS sub_department_id', 'c.course_code', 'c.course_title', 'co.section_code'])
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray();
    }

    /** Tells the section's instructors and its chairperson what the dean did, except the dean. */
    private function tell(int $offeringId, User $actor, string $key, string $title, string $what): void
    {
        $section = $this->section($offeringId);

        if ($section === null) {
            return;
        }

        $db    = db_connect();
        $label = trim($section['course_code'] . ' ' . $section['section_code']);
        $slug  = url_title((string) $section['course_title'], '-', true);

        $audience = [];

        foreach ($db->table('instructor_assignments')->select('instructor_user_id')->where('offering_id', $offeringId)->get()->getResultArray() as $row) {
            $audience[(int) $row['instructor_user_id']] = 'instructor';
        }

        foreach ($db->table('chairperson_assignments')->select('chairperson_user_id')->where(['sub_department_id' => $section['sub_department_id'], 'status' => 'active'])->get()->getResultArray() as $row) {
            // A person who is both is told once, as the chairperson.
            $audience[(int) $row['chairperson_user_id']] = 'chairperson';
        }

        unset($audience[(int) $actor->id]);

        foreach ($audience as $userId => $area) {
            $this->notifications->send($userId, 'grade_signoff', $title, $label . ': ' . $what, false, $key, (int) $section['institution_id'], site_url("{$area}/courses/{$offeringId}/{$slug}/grades"));
        }
    }

    /** @return array{success: false, message: string} */
    private function refused(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
