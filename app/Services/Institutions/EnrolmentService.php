<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Sis\SisOwnership;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;

/**
 * Who is enrolled in an offering, as the institutional administrator keeps
 * it.
 *
 * Rules:
 *  - Students are people of the institution who hold the student role and
 *    whose account is active. They are named by student number or email.
 *  - Only an active offering takes changes (a closed or archived one is
 *    history).
 *  - Capacity is a guide with a deliberate override: going over it needs
 *    the administrator to say so, and the message says by how many.
 *  - Nothing is deleted. Dropping or withdrawing keeps the student's work
 *    and grades; the enrolment only changes status, and can be reinstated.
 *  - While the SIS connection is enabled, a place in a course the SIS knows
 *    for a student the SIS knows is the SIS's: not changed by hand here.
 *  - Seats count student accounts, not places, so enrolling an existing
 *    student never uses one.
 *  - Every id is checked against the institution first (404 for a foreign
 *    one) and every change is audited.
 */
class EnrolmentService
{
    public const PER_PAGE = 25;

    public const STATUSES = ['enrolled', 'dropped', 'withdrawn', 'completed'];

    private const SORTS = [
        'name'   => 'up.last_name',
        'number' => 'up.student_number',
        'status' => 'e.status',
        'since'  => 'e.enrolled_at',
    ];

    private readonly SisOwnership $sis;

    public function __construct(?SisOwnership $sis = null)
    {
        $this->sis = $sis ?? new SisOwnership();
    }

    /**
     * One offering's roster, filtered, sorted and paged.
     *
     * @param array<string, mixed> $filters q, status, sort, dir, page
     *
     * @return array{offering: array<string, mixed>, rows: list<array<string, mixed>>, total: int, pages: int, page: int, counts: array<string, int>, courseLocked: bool}
     */
    public function roster(int $institutionId, int $offeringId, array $filters = []): array
    {
        $this->scope($institutionId)->require('offering', $offeringId);

        $db       = db_connect();
        $offering = $this->offering($offeringId);

        $base = static function () use ($db, $offeringId, $filters) {
            $builder = $db->table('enrollments e')
                ->join('user_profiles up', 'up.user_id = e.student_user_id', 'left')
                ->join('auth_identities ai', "ai.user_id = e.student_user_id AND ai.type = 'email_password'", 'left', false)
                ->where('e.offering_id', $offeringId);

            $q = trim((string) ($filters['q'] ?? ''));

            if ($q !== '') {
                $like = $db->escapeLikeString($q);
                $builder->groupStart()
                    ->where("CONCAT(COALESCE(up.first_name, ''), ' ', COALESCE(up.last_name, '')) LIKE '%{$like}%'", null, false)
                    ->orWhere("up.student_number LIKE '%{$like}%'", null, false)
                    ->orWhere("ai.secret LIKE '%{$like}%'", null, false)
                    ->groupEnd();
            }

            $status = (string) ($filters['status'] ?? '');

            if (in_array($status, self::STATUSES, true)) {
                $builder->where('e.status', $status);
            }

            return $builder;
        };

        $total = (int) $base()->countAllResults();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, (int) ($filters['page'] ?? 1)), $pages);

        $sort = self::SORTS[(string) ($filters['sort'] ?? '')] ?? self::SORTS['name'];
        $dir  = strtolower((string) ($filters['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

        $builder = $base()
            ->select('e.id, e.student_user_id, e.status, e.enrolled_at, up.first_name, up.last_name, up.student_number, ai.secret AS email')
            ->orderBy($sort, $dir);

        if ($sort !== 'up.last_name') {
            $builder->orderBy('up.last_name', 'ASC');
        }

        $rows = $builder->orderBy('up.first_name', 'ASC')->orderBy('e.id', 'ASC')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            ->get()->getResultArray();

        $courseLocked = $this->sis->managesCourse((int) $offering['course_id']);

        $out = [];

        foreach ($rows as $r) {
            $studentId = (int) $r['student_user_id'];

            $out[] = [
                'id'             => (int) $r['id'],
                'student_id'     => $studentId,
                'name'           => trim($r['first_name'] . ' ' . $r['last_name']) ?: (string) $r['email'],
                'email'          => (string) $r['email'],
                'student_number' => (string) ($r['student_number'] ?? ''),
                'status'         => (string) $r['status'],
                'enrolled_at'    => (string) $r['enrolled_at'],
                'locked'         => $courseLocked && $this->sis->managesPerson($studentId),
            ];
        }

        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($db->table('enrollments')->select('status, COUNT(*) AS total', false)->where('offering_id', $offeringId)->groupBy('status')->get()->getResultArray() as $c) {
            $counts[(string) $c['status']] = (int) $c['total'];
        }

        return ['offering' => $offering, 'rows' => $out, 'total' => $total, 'pages' => $pages, 'page' => $page, 'counts' => $counts, 'courseLocked' => $courseLocked];
    }

    /**
     * Enrol the students named in the pasted text. Names that cannot be
     * enrolled are skipped with the reason; the rest go in together, or
     * none do when capacity would be exceeded without the override.
     *
     * @return array{added: int, reinstated: int, skipped: list<array{identifier: string, reason: string}>, warnings: list<string>}
     *
     * @throws StructureException
     */
    public function add(int $institutionId, int $offeringId, string $text, bool $overCapacity, ?User $actor = null): array
    {
        $this->scope($institutionId)->require('offering', $offeringId);

        $offering = $this->offering($offeringId);
        $this->mustBeOpen($offering);

        $names = EnrolmentList::parse($text);

        if ($names === []) {
            throw StructureException::field('students', 'Enter at least one student number or email address.');
        }

        if (count($names) > EnrolmentList::MAX) {
            throw StructureException::field('students', 'That is more than ' . EnrolmentList::MAX . ' at once. Add them in smaller groups, or upload a CSV file.');
        }

        $db      = db_connect();
        $skipped = [];
        $new     = [];
        $back    = [];

        foreach ($names as $name) {
            $student = $this->findStudent($institutionId, $name);

            if (is_string($student)) {
                $skipped[] = ['identifier' => $name, 'reason' => $student];

                continue;
            }

            $studentId = (int) $student['id'];

            if ($this->sis->managesEnrolment((int) $offering['course_id'], $studentId)) {
                $skipped[] = ['identifier' => $name, 'reason' => 'is managed by your student information system'];

                continue;
            }

            $existing = $db->table('enrollments')->where(['offering_id' => $offeringId, 'student_user_id' => $studentId])->get()->getRowArray();

            if ($existing === null) {
                $new[$studentId] = $name;
            } elseif ($existing['status'] === 'enrolled') {
                $skipped[] = ['identifier' => $name, 'reason' => 'is already enrolled'];
            } elseif ($existing['status'] === 'completed') {
                $skipped[] = ['identifier' => $name, 'reason' => 'has already completed this course'];
            } else {
                $back[(int) $existing['id']] = ['student' => $studentId, 'old' => (string) $existing['status']];
            }
        }

        $adding = count($new) + count($back);

        if ($adding === 0) {
            throw StructureException::field('students', $this->skippedText($skipped, 'Nobody was enrolled.'));
        }

        $this->checkCapacity($offering, $adding, $overCapacity);

        $now = date('Y-m-d H:i:s');

        $db->transStart();

        foreach (array_keys($new) as $studentId) {
            $db->table('enrollments')->insert(['offering_id' => $offeringId, 'student_user_id' => $studentId, 'status' => 'enrolled', 'enrolled_at' => $now, 'updated_at' => $now]);
            $this->audit('enrolment.added', $actor, $institutionId, (int) $db->insertID(), null, ['offering_id' => $offeringId, 'student_user_id' => $studentId, 'status' => 'enrolled']);
        }

        foreach ($back as $enrollmentId => $change) {
            $db->table('enrollments')->where('id', $enrollmentId)->update(['status' => 'enrolled', 'updated_at' => $now]);
            $this->audit('enrolment.status_changed', $actor, $institutionId, $enrollmentId, ['status' => $change['old']], ['status' => 'enrolled', 'offering_id' => $offeringId, 'student_user_id' => $change['student']]);
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new StructureException('The students could not be enrolled. Nothing was changed.');
        }

        return [
            'added'      => count($new),
            'reinstated' => count($back),
            'skipped'    => $skipped,
            'warnings'   => $skipped === [] ? [] : [$this->skippedText($skipped, count($skipped) === 1 ? '1 was skipped.' : count($skipped) . ' were skipped.')],
        ];
    }

    /**
     * Change one enrolment's status. Nothing is deleted: the student's work
     * and grades stay with every status.
     *
     * @throws StructureException
     * @throws PageNotFoundException when the enrolment is not this institution's
     */
    public function setStatus(int $institutionId, int $enrollmentId, string $status, bool $overCapacity, ?User $actor = null): void
    {
        $db  = db_connect();
        $row = $db->table('enrollments')->where('id', $enrollmentId)->get()->getRowArray();

        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->scope($institutionId)->require('offering', (int) $row['offering_id']);

        if (! in_array($status, self::STATUSES, true)) {
            throw StructureException::field('status', 'Choose one of the listed statuses.');
        }

        $offering = $this->offering((int) $row['offering_id']);
        $this->mustBeOpen($offering);

        if ($row['status'] === $status) {
            throw new StructureException('The student already has that status.');
        }

        if ($this->sis->managesEnrolment((int) $offering['course_id'], (int) $row['student_user_id'])) {
            throw new StructureException('This place is managed by your student information system. Change it there; the next sync brings it here.');
        }

        if ($status === 'enrolled') {
            $this->checkCapacity($offering, 1, $overCapacity);
        }

        $db->table('enrollments')->where('id', $enrollmentId)->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit('enrolment.status_changed', $actor, $institutionId, $enrollmentId, ['status' => $row['status']], ['status' => $status, 'offering_id' => (int) $row['offering_id'], 'student_user_id' => (int) $row['student_user_id']]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function scope(int $institutionId): InstitutionScope
    {
        return InstitutionScope::forInstitution($institutionId);
    }

    /** @return array<string, mixed> */
    private function offering(int $id): array
    {
        $row = db_connect()->table('course_offerings o')
            ->select('o.*, c.course_code, c.course_title, t.name AS term_name, t.academic_year')
            ->join('courses c', 'c.id = o.course_id')
            ->join('academic_terms t', 't.id = o.term_id')
            ->where('o.id', $id)
            ->get()->getRowArray();

        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $row['enrolled_count'] = $this->enrolled($id);

        return $row;
    }

    private function enrolled(int $offeringId): int
    {
        return (int) db_connect()->table('enrollments')->where(['offering_id' => $offeringId, 'status' => 'enrolled'])->countAllResults();
    }

    /**
     * @param array<string, mixed> $offering
     *
     * @throws StructureException
     */
    private function mustBeOpen(array $offering): void
    {
        if ($offering['status'] !== 'active') {
            throw new StructureException('This offering is closed, so its enrolments cannot be changed.');
        }
    }

    /**
     * @param array<string, mixed> $offering
     *
     * @throws StructureException
     */
    private function checkCapacity(array $offering, int $adding, bool $overCapacity): void
    {
        $capacity = (int) $offering['capacity'];
        $enrolled = (int) $offering['enrolled_count'];
        $over     = $enrolled + $adding - $capacity;

        if ($over > 0 && ! $overCapacity) {
            throw StructureException::field(
                'over_capacity',
                sprintf('This would put the section over capacity by %d (%d of %d places are taken). Tick the box to enrol anyway.', $over, $enrolled, $capacity),
            );
        }
    }

    /**
     * The student a student number or an email names, or why there is none.
     *
     * @return array<string, mixed>|string
     */
    private function findStudent(int $institutionId, string $name): array|string
    {
        $builder = db_connect()->table('users u')
            ->select('u.id, u.active, u.deleted_at')
            ->join('institution_users iu', 'iu.user_id = u.id')
            ->where('iu.institution_id', $institutionId);

        if (str_contains($name, '@')) {
            $builder->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'inner', false)
                ->where('LOWER(ai.secret)', mb_strtolower($name));
        } else {
            $builder->join('user_profiles up', 'up.user_id = u.id', 'inner')
                ->where('up.student_number', $name);
        }

        $found = $builder->get()->getResultArray();

        if ($found === []) {
            return 'is not a student number or email in your institution';
        }

        if (count($found) > 1) {
            return 'matches more than one person; use their email address';
        }

        $user = $found[0];

        if ($user['deleted_at'] !== null || (int) $user['active'] !== 1) {
            return 'has an inactive account';
        }

        $isStudent = db_connect()->table('auth_groups_users')->where(['user_id' => (int) $user['id'], 'group' => 'student'])->countAllResults() > 0;

        return $isStudent ? $user : 'does not hold the student role';
    }

    /**
     * @param list<array{identifier: string, reason: string}> $skipped
     */
    private function skippedText(array $skipped, string $lead): string
    {
        $parts = array_map(static fn (array $s): string => $s['identifier'] . ' ' . $s['reason'], array_slice($skipped, 0, 5));
        $more  = count($skipped) > 5 ? ' and ' . (count($skipped) - 5) . ' more' : '';

        return $skipped === [] ? $lead : $lead . ' Skipped: ' . implode('; ', $parts) . $more . '.';
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, ?User $actor, int $institutionId, int $id, ?array $old, ?array $new): void
    {
        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'enrollment',
            'target_id'      => $id,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}
