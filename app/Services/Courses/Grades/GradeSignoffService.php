<?php

declare(strict_types=1);

namespace App\Services\Courses\Grades;

use App\Models\Assessments\GradeSignoffModel;
use App\Models\Courses\OfferingDetail;
use App\Services\Auth\ActiveRoleService;
use App\Services\Auth\RolePermissions;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\MonitorProgramService;
use App\Services\Notifications\NotificationService;
use App\Services\Subscriptions\PlanLimits;
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

    /**
     * @return array<string, mixed>|null the current sign-off with who signed, or null. `signed_by_dean` is 1 when the
     *                                   signer is (or was) a dean of the section's department, else an administrator
     *                                   signed it off (QA AMB-ASG-04).
     */
    public function current(int $offeringId): ?array
    {
        $row = db_connect()->table('grade_signoffs s')
            ->select("s.*, CONCAT_WS(' ', up.first_name, up.last_name) AS signed_by_name", false)
            ->select('EXISTS (SELECT 1 FROM dean_assignments da'
                . ' JOIN sub_departments sd ON sd.department_id = da.department_id'
                . ' JOIN programs p ON p.sub_department_id = sd.id'
                . ' JOIN courses c ON c.program_id = p.id'
                . ' JOIN course_offerings co ON co.course_id = c.id'
                . ' WHERE co.id = s.offering_id AND da.dean_user_id = s.signed_by) AS signed_by_dean', false)
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

        $this->tell($offeringId, $dean, "grade-signoff:{$id}:signed", 'Grades signed off', 'the ' . $this->who($dean) . ' signed off the final grades.');

        return ['success' => true, 'message' => 'Signed off.'];
    }

    /**
     * The dean signs off every ready section of a program in a term; the others are left as they are.
     *
     * @return array{success: bool, message: string, signed: int, left: int}
     */
    public function signOffProgram(User $dean, int $programId, int $termId): array
    {
        $mine = (new ActiveRoleService())->resolve($dean) === 'institutional_admin'
            ? in_array($programId, $this->adminProgramIds($dean), true)
            : (new DeanAccessService())->canAccessProgram($dean, $programId);

        if (! $mine) {
            return ['success' => false, 'message' => 'That program is not one you sign off.', 'signed' => 0, 'left' => 0];
        }

        $signed = 0;
        $left   = 0;
        $own    = 0;
        $admin  = (new ActiveRoleService())->resolve($dean) === 'institutional_admin';

        foreach (array_column(OfferingDetail::inPrograms([$programId])->where('academic_terms.id', $termId)->get()->getResultArray(), 'offering_id') as $offeringId) {
            $offeringId = (int) $offeringId;

            if ($this->status($offeringId)['state'] === self::SIGNED_OFF) {
                continue;
            }

            // An administrator's own section is another administrator's to sign off (D4), not one "not ready".
            if ($admin && in_array((int) $dean->id, $this->instructorsOf($offeringId), true)) {
                $own++;

                continue;
            }

            $this->signOff($dean, $offeringId)['success'] ? $signed++ : $left++;
        }

        $rest = ($left > 0 ? ", {$left} not ready were left" : '')
            . ($own > 0 ? ", {$own} you teach " . ($own === 1 ? 'was' : 'were') . ' left for another administrator' : '');

        return [
            'success' => true,
            'message' => $signed === 0 ? 'Nothing was ready to sign off' . ($own > 0 ? ' that you may sign off' : '') . '.' : "Signed off {$signed} " . ($signed === 1 ? 'section' : 'sections') . $rest . '.',
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

        $this->tell($offeringId, $dean, "grade-signoff:{$current['id']}:reopened", 'Sign-off reopened', 'the ' . $this->who($dean) . ' reopened the sign-off of the final grades. Reason: ' . trim($reason));

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

        $dean = GradeAuthority::deanFor((int) $section['department_id'], 'grades.signoff');

        // With no dean who may sign off, the administrators who may (2.1), never one who teaches it (D4).
        $people = $dean !== null
            ? [$dean => 'dean/signoff']
            : array_fill_keys(GradeAuthority::institutionAdmins((int) $section['institution_id'], 'grades.signoff', $this->instructorsOf($offeringId)), 'institution/signoff');

        foreach ($people as $userId => $page) {
            $this->notifications->send(
                (int) $userId,
                'grade_signoff',
                'Grades ready for sign-off',
                trim($section['course_code'] . ' ' . $section['section_code']) . ': the grades are finalized and ready for your sign-off.',
                false,
                "grade-signoff:ready:{$offeringId}:" . date('YmdHis'),
                (int) $section['institution_id'],
                site_url($page),
            );
        }
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
        return $this->boardFor((new DeanAccessService())->programIds($dean), $termId, 'dean');
    }

    /**
     * The sign-off board for a set of programs: the dean's department, or the administrator's departments with
     * no dean who may sign off (adminProgramIds). Its course links point into the area ('dean', 'institution').
     *
     * @param list<int> $programIds
     *
     * @return array{terms: list<array<string, mixed>>, term: ?int, programs: list<array<string, mixed>>}
     */
    public function boardFor(array $programIds, ?int $termId, string $area): array
    {
        $terms = (new MonitorProgramService())->termsForPrograms($programIds);

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

            $own                  = $area === 'institution' && in_array((int) auth()->id(), $this->instructorsOf((int) $row['offering_id']), true);
            $programs[$programId] ??= ['id' => $programId, 'name' => (string) $row['program_name'], 'sections' => [], 'counts' => [self::READY => 0, self::BLOCKED => 0, self::SIGNED_OFF => 0], 'own_ready' => 0];

            $programs[$programId]['counts'][$status['state']]++;

            // Ready, but the administrator's own: another administrator signs it off (D4).
            if ($own && $status['state'] === self::READY) {
                $programs[$programId]['own_ready']++;
            }

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
                'grades_url'     => site_url($area . '/courses/' . (int) $row['offering_id'] . '/' . url_title((string) $row['course_title'], '-', true) . '/grades'),
                'own_section'    => $own,
                'signed_by_name' => $status['signoff']['signed_by_name'] ?? null,
                'signed_at'      => $status['signoff']['signed_at'] ?? null,
            ];
        }

        return ['terms' => $terms, 'term' => $termId, 'programs' => array_values($programs)];
    }

    /** Why this person may not decide on this offering, or null when they may: the dean of its department, acting as dean. */
    private function mayDecide(User $user, int $offeringId): ?string
    {
        if (! $this->appliesTo($offeringId)) {
            return 'Sign-off is not used in this institution.';
        }

        $role        = (new ActiveRoleService())->resolve($user);
        $permissions = new RolePermissions();

        // The dean of the department, who may sign off a section they teach (D3).
        if ($role === 'dean') {
            return (new DeanAccessService())->canAccessOffering($user, $offeringId) && $permissions->allows($user, 'grades.signoff', 'dean')
                ? null
                : 'That section is not in your department.';
        }

        // An administrator of the institution, where the department has no dean who may sign off (2.1, U1e).
        $section = $this->section($offeringId);

        if ($role !== 'institutional_admin' || $section === null
            || (new PlanLimits())->institutionOf((int) $user->id) !== (int) $section['institution_id']
            || GradeAuthority::deanFor((int) $section['department_id'], 'grades.signoff') !== null
            || ! $permissions->allows($user, 'grades.signoff', 'institutional_admin')) {
            return 'That section is not one you sign off.';
        }

        // Never their own section: another administrator signs it off (D4).
        if (in_array((int) $user->id, $this->instructorsOf($offeringId), true)) {
            return 'You teach this section, so another administrator signs it off.';
        }

        return null;
    }

    /**
     * The institution's first dean was just assigned, so grades already finalized now need a sign-off before
     * they go to the SIS (2.3): the administrators who may sign off are told which sections wait.
     */
    public function announceNowNeeded(int $institutionId): void
    {
        $rows = db_connect()->table('grade_finalizations f')
            ->select('co.id, c.course_code, co.section_code, d.id AS department_id')
            ->join('course_offerings co', 'co.id = f.course_offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->where('f.open_marker', 1)
            ->where('NOT EXISTS (SELECT 1 FROM grade_signoffs s WHERE s.offering_id = co.id AND s.open_marker = 1)', null, false)
            // Grades already sent to the SIS are not held back now (2.3: the unsent ones are).
            ->where('NOT EXISTS (SELECT 1 FROM sis_grade_exports x WHERE x.offering_id = co.id)', null, false)
            ->orderBy('c.course_code')->orderBy('co.section_code')
            ->get()->getResultArray();

        // Each section goes to whoever signs it off: its department's dean, or else the administrators.
        $forDean   = [];
        $forAdmins = [];

        foreach ($rows as $row) {
            $deanId = GradeAuthority::deanFor((int) $row['department_id'], 'grades.signoff');

            if ($deanId === null) {
                $forAdmins[] = $row;
            } else {
                $forDean[$deanId][] = $row;
            }
        }

        foreach ($forDean as $deanId => $sections) {
            $this->notifications->send($deanId, 'grade_signoff', 'Grades now need sign-off', $this->nowNeededBody($sections), false, "grade-signoff:now-needed:{$institutionId}:dean:" . date('YmdHis'), $institutionId, site_url('dean/signoff'));
        }

        if ($forAdmins === []) {
            return;
        }

        foreach (GradeAuthority::institutionAdmins($institutionId, 'grades.signoff') as $adminId) {
            $this->notifications->send($adminId, 'grade_signoff', 'Grades now need sign-off', $this->nowNeededBody($forAdmins), false, "grade-signoff:now-needed:{$institutionId}:" . date('YmdHis'), $institutionId, site_url('institution/signoff'));
        }
    }

    /** @param non-empty-list<array{course_code: string, section_code: string}> $sections */
    private function nowNeededBody(array $sections): string
    {
        $count  = count($sections);
        $labels = array_map(static fn (array $r): string => trim($r['course_code'] . ' ' . $r['section_code']), array_slice($sections, 0, 5));

        return $count . ($count === 1 ? ' section' : ' sections') . ' with finalized grades now need a sign-off before the grades go to the SIS: '
            . implode(', ', $labels) . ($count > 5 ? ' and ' . ($count - 5) . ' more' : '') . '.';
    }

    /**
     * The programs of the administrator's institution whose department has no dean who may sign off: what the
     * administrator signs off (2.1).
     *
     * @return list<int>
     */
    public function adminProgramIds(User $admin): array
    {
        $institution = (new PlanLimits())->institutionOf((int) $admin->id);

        if ($institution === null || ! GradeAuthority::usesApproval($institution)) {
            return [];
        }

        $rows = db_connect()->table('programs p')
            ->select('p.id, sd.department_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institution)
            ->orderBy('p.id')
            ->get()->getResultArray();

        $noDean = [];

        return array_values(array_map(
            static fn (array $r): int => (int) $r['id'],
            array_filter($rows, static function (array $r) use (&$noDean): bool {
                $department = (int) $r['department_id'];

                return $noDean[$department] ??= GradeAuthority::deanFor($department, 'grades.signoff') === null;
            })
        ));
    }

    /** @return list<int> who teaches this section */
    private function instructorsOf(int $offeringId): array
    {
        return array_map('intval', array_column(db_connect()->table('instructor_assignments')->select('instructor_user_id')->where('offering_id', $offeringId)->get()->getResultArray(), 'instructor_user_id'));
    }

    /** Who signed off or reopened, in the words the instructor reads. */
    private function who(User $user): string
    {
        return (new ActiveRoleService())->resolve($user) === 'institutional_admin' ? 'administrator' : 'dean';
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
