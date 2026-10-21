<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Services\Audit\AuditLogger;
use App\Services\Auth\ActiveRoleService;
use App\Services\Notifications\NotificationService;
use CodeIgniter\Shield\Entities\User;

/**
 * A dean's announcements to their department, a sub-department or one program.
 *
 * Who receives one is never stored: it is worked out from the hierarchy every time. Students receive it
 * through their program and their current enrolments, instructors through the sections they teach and
 * chairpersons through the sub-departments they chair, so a person who teaches in two departments gets the
 * announcements of each, each for the sections they have there, and a student who moves program stops
 * receiving the old program's. The audience can be narrowed to everyone, staff only (instructors and
 * chairpersons) or students only.
 *
 * recipients() (an announcement to its people) and forUser() (a person to their announcements) are two
 * routes through the same rules; a test holds them to agreeing.
 */
class DepartmentAnnouncementService
{
    public const SCOPES = ['department', 'sub_department', 'program'];

    public const AUDIENCES = [
        'everyone' => 'Everyone',
        'staff'    => 'Instructors and chairpersons',
        'students' => 'Students',
    ];

    public const MAX_TITLE   = 160;
    public const MAX_MESSAGE = 2000;

    public function __construct(
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly DeanAccessService $access = new DeanAccessService(),
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | The dean's side
    |--------------------------------------------------------------------------
    */

    /**
     * Posts an announcement and tells the people it is for.
     *
     * @param array{scope?: mixed, audience?: mixed, title?: mixed, message?: mixed, ends_on?: mixed} $input scope is "department", "sub_department:ID" or "program:ID"
     *
     * @return array{success: bool, message: string, id?: int, errors?: array<string, string>}
     */
    public function post(User $dean, array $input): array
    {
        $refusal = $this->mayAnnounce($dean);

        if ($refusal !== null) {
            return $this->refused($refusal);
        }

        $department = (int) $this->access->departmentFor($dean)['department_id'];
        $errors     = [];

        [$type, $scopeId] = $this->parseScope((string) ($input['scope'] ?? ''), $dean, $department);

        if ($type === null) {
            $errors['scope'] = 'Choose who it is for: your department, a sub-department or a program of it.';
        }

        $audience = (string) ($input['audience'] ?? 'everyone');

        if (! array_key_exists($audience, self::AUDIENCES)) {
            $errors['audience'] = 'Choose who should read it.';
        }

        $errors += $this->validateContent($input);

        if ($errors !== []) {
            return $this->refused(reset($errors), $errors);
        }

        $title   = trim((string) $input['title']);
        $message = trim((string) $input['message']);
        $endsOn  = $this->date($input['ends_on'] ?? null);
        $now     = date('Y-m-d H:i:s');
        $db      = db_connect();

        $db->table('department_announcements')->insert([
            'institution_id' => $this->institutionOf($department),
            'department_id'  => $department,
            'created_by'     => (int) $dean->id,
            'scope_type'     => $type,
            'scope_id'       => $scopeId,
            'audience'       => $audience,
            'title'          => $title,
            'message'        => $message,
            'ends_on'        => $endsOn,
            'status'         => 'active',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $id  = (int) $db->insertID();
        $row = $this->find($id);

        $this->audit('department_announcement.posted', $dean, $id, null, ['scope' => "{$type}:{$scopeId}", 'audience' => $audience, 'title' => $title, 'ends_on' => $endsOn]);

        $reached = $this->tell($row, $dean);

        return ['success' => true, 'message' => $reached === 0 ? 'Posted. Nobody is in that audience yet.' : 'Posted to ' . $reached . ($reached === 1 ? ' person.' : ' people.'), 'id' => $id];
    }

    /**
     * Changes the wording or the last day. Who it is for stays as it was posted: end it and post a new one
     * for a different audience, so nobody is told something that was not meant for them.
     *
     * @param array{title?: mixed, message?: mixed, ends_on?: mixed} $input
     *
     * @return array{success: bool, message: string, errors?: array<string, string>}
     */
    public function edit(User $dean, int $id, array $input): array
    {
        $row = $this->ownRow($dean, $id);

        if (is_string($row)) {
            return $this->refused($row);
        }

        if ($row['status'] !== 'active') {
            return $this->refused('That announcement has ended. Post a new one instead.');
        }

        $errors = $this->validateContent($input);

        if ($errors !== []) {
            return $this->refused(reset($errors), $errors);
        }

        $new = ['title' => trim((string) $input['title']), 'message' => trim((string) $input['message']), 'ends_on' => $this->date($input['ends_on'] ?? null)];

        db_connect()->table('department_announcements')->where('id', $id)->update($new + ['updated_at' => date('Y-m-d H:i:s')]);

        $this->audit('department_announcement.edited', $dean, $id, ['title' => $row['title'], 'message' => $row['message'], 'ends_on' => $row['ends_on']], $new);

        return ['success' => true, 'message' => 'Announcement updated.'];
    }

    /** The dean ends an announcement: it stops showing at once and stays in their list as history. @return array{success: bool, message: string} */
    public function end(User $dean, int $id): array
    {
        $row = $this->ownRow($dean, $id);

        if (is_string($row)) {
            return $this->refused($row);
        }

        if ($row['status'] !== 'active') {
            return $this->refused('That announcement has already ended.');
        }

        $now = date('Y-m-d H:i:s');

        db_connect()->table('department_announcements')->where(['id' => $id, 'status' => 'active'])->update(['status' => 'ended', 'ended_by' => (int) $dean->id, 'ended_at' => $now, 'updated_at' => $now]);

        $this->audit('department_announcement.ended', $dean, $id, null, ['title' => $row['title']]);

        return ['success' => true, 'message' => 'Announcement ended.'];
    }

    /**
     * The department's announcements for the dean's list: the ones still showing, or the ones that ended
     * (by the dean or by their last day).
     *
     * @param 'active'|'ended' $tab
     *
     * @return array{rows: list<array<string, mixed>>, counts: array{active: int, ended: int}}
     */
    public function listForDean(User $dean, string $tab = 'active', ?string $today = null): array
    {
        $department = $this->access->departmentFor($dean);

        if ($department === null) {
            return ['rows' => [], 'counts' => ['active' => 0, 'ended' => 0]];
        }

        $today ??= date('Y-m-d');
        $all     = db_connect()->table('department_announcements')->where('department_id', (int) $department['department_id'])->orderBy('id', 'DESC')->get()->getResultArray();
        $labels  = $this->scopeLabels((int) $department['department_id']);
        $names   = $this->names(array_map('intval', array_column($all, 'created_by')));

        $counts = ['active' => 0, 'ended' => 0];
        $rows   = [];

        foreach ($all as $row) {
            $showing = self::isShowing($row, $today);
            $counts[$showing ? 'active' : 'ended']++;

            if (($tab === 'active') !== $showing) {
                continue;
            }

            $rows[] = $row + [
                'scope_label'    => $labels[$row['scope_type'] . ':' . $row['scope_id']] ?? 'Removed',
                'audience_label' => self::AUDIENCES[$row['audience']] ?? 'Everyone',
                'author'         => $names[(int) $row['created_by']] ?? '',
                'showing'        => $showing,
                'reach'          => $showing ? $this->reachCount($row) : null,
            ];
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * Where an announcement can be aimed, for the dean's form: the department, then its sub-departments and
     * programs.
     *
     * @return array<string, string> "department", "sub_department:ID" or "program:ID" => label
     */
    public function scopeOptions(User $dean): array
    {
        $department = $this->access->departmentFor($dean);

        if ($department === null) {
            return [];
        }

        $labels = $this->scopeLabels((int) $department['department_id']);
        $out    = ['department' => $labels['department:' . $department['department_id']] ?? 'My department'];

        foreach ($labels as $key => $label) {
            if (! str_starts_with($key, 'department:')) {
                $out[$key] = $label;
            }
        }

        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | Who an announcement reaches
    |--------------------------------------------------------------------------
    */

    /** Showing means active, and not past its last day (that whole day counts). @param array<string, mixed> $row */
    public static function isShowing(array $row, string $today): bool
    {
        return $row['status'] === 'active' && ($row['ends_on'] === null || $row['ends_on'] === '' || $today <= substr((string) $row['ends_on'], 0, 10));
    }

    /**
     * The people an announcement is for, by role. Active accounts only.
     *
     * @param array<string, mixed> $row
     *
     * @return array{students: list<int>, instructors: list<int>, chairpersons: list<int>}
     */
    public function recipients(array $row): array
    {
        $programs = $this->programsInScope((string) $row['scope_type'], (int) $row['scope_id']);
        $subs     = $this->subDepartmentsInScope((string) $row['scope_type'], (int) $row['scope_id']);
        $db       = db_connect();
        $audience = (string) $row['audience'];

        $students = $instructors = $chairpersons = [];

        if ($programs !== [] && in_array($audience, ['everyone', 'students'], true)) {
            $enrolled = $db->table('enrollments e')
                ->select('e.student_user_id AS id')
                ->join('course_offerings co', 'co.id = e.offering_id')
                ->join('courses c', 'c.id = co.course_id')
                ->join('users u', 'u.id = e.student_user_id')
                ->where('e.status', 'enrolled')->where('co.status', 'active')->where('u.active', 1)
                ->whereIn('c.program_id', $programs)
                ->get()->getResultArray();

            $enrolledIn = $db->table('user_profiles up')
                ->select('up.user_id AS id')
                ->join('users u', 'u.id = up.user_id')
                ->join('auth_groups_users g', "g.user_id = up.user_id AND g.group = 'student'")
                ->where('u.active', 1)->whereIn('up.program_id', $programs)
                ->get()->getResultArray();

            $students = $this->ids(array_merge($enrolled, $enrolledIn));
        }

        if ($programs !== [] && in_array($audience, ['everyone', 'staff'], true)) {
            $instructors = $this->ids($db->table('instructor_assignments ia')
                ->select('ia.instructor_user_id AS id')
                ->join('course_offerings co', 'co.id = ia.offering_id')
                ->join('courses c', 'c.id = co.course_id')
                ->join('users u', 'u.id = ia.instructor_user_id')
                ->where('co.status', 'active')->where('u.active', 1)
                ->whereIn('c.program_id', $programs)
                ->get()->getResultArray());
        }

        if ($subs !== [] && in_array($audience, ['everyone', 'staff'], true)) {
            $chairpersons = $this->ids($db->table('chairperson_assignments ca')
                ->select('ca.chairperson_user_id AS id')
                ->join('users u', 'u.id = ca.chairperson_user_id')
                ->where('ca.status', 'active')->where('u.active', 1)
                ->whereIn('ca.sub_department_id', $subs)
                ->get()->getResultArray());
        }

        return ['students' => $students, 'instructors' => $instructors, 'chairpersons' => $chairpersons];
    }

    /**
     * The announcements that are showing and reach this person, newest first. A student is reached through
     * their program and enrolments, an instructor through the sections they teach and a chairperson through
     * the sub-departments they chair.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, ?int $limit = null, ?string $today = null): array
    {
        // An account that is switched off is nobody's audience, as in recipients().
        if (db_connect()->table('users')->where(['id' => (int) $user->id, 'active' => 1])->countAllResults() === 0) {
            return [];
        }

        $today ??= date('Y-m-d');
        $reach   = $this->reachOf($user);

        if ($reach['students'] === [] && $reach['staff'] === [] && $reach['chairs'] === []) {
            return [];
        }

        $rows = db_connect()->table('department_announcements')
            ->where('status', 'active')
            ->groupStart()->where('ends_on IS NULL', null, false)->orWhere('ends_on >=', $today)->groupEnd()
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $audience = $row['audience'];

            $reached = ($audience !== 'staff' && $this->inScope($row, $reach['students'], null))
                || ($audience !== 'students' && ($this->inScope($row, $reach['staff'], null) || $this->inScope($row, null, $reach['chairs'])));

            if ($reached) {
                $out[] = $row;
            }
        }

        $out = $limit === null ? $out : array_slice($out, 0, $limit);

        $labels = [];
        $names  = $this->names(array_map('intval', array_column($out, 'created_by')));

        foreach ($out as &$row) {
            foreach (['id', 'institution_id', 'department_id', 'created_by', 'scope_id'] as $column) {
                $row[$column] = (int) $row[$column];
            }

            $labels[$row['department_id']] ??= $this->scopeLabels((int) $row['department_id']);
            $row['scope_label'] = $labels[$row['department_id']][$row['scope_type'] . ':' . $row['scope_id']] ?? 'Your department';
            $row['author']      = $names[(int) $row['created_by']] ?? '';
        }
        unset($row);

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return db_connect()->table('department_announcements')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The programs (students, instructors) and sub-departments (chairpersons) a person is reached through.
     *
     * @return array{students: list<int>, staff: list<int>, chairs: list<int>} program ids for the first two, sub-department ids for the third
     */
    private function reachOf(User $user): array
    {
        $db = db_connect();
        $id = (int) $user->id;

        $students = array_merge(
            array_column($db->table('enrollments e')->select('c.program_id')->join('course_offerings co', 'co.id = e.offering_id')->join('courses c', 'c.id = co.course_id')->where(['e.student_user_id' => $id, 'e.status' => 'enrolled', 'co.status' => 'active'])->get()->getResultArray(), 'program_id'),
            $user->inGroup('student') ? array_filter([(int) ($db->table('user_profiles')->select('program_id')->where('user_id', $id)->get()->getRow('program_id') ?? 0)]) : []
        );

        $staff = array_column($db->table('instructor_assignments ia')->select('c.program_id')->join('course_offerings co', 'co.id = ia.offering_id')->join('courses c', 'c.id = co.course_id')->where(['ia.instructor_user_id' => $id, 'co.status' => 'active'])->get()->getResultArray(), 'program_id');

        $chairs = array_column($db->table('chairperson_assignments')->select('sub_department_id')->where(['chairperson_user_id' => $id, 'status' => 'active'])->get()->getResultArray(), 'sub_department_id');

        return ['students' => $this->ids($students, 'program_id'), 'staff' => $this->ids($staff, 'program_id'), 'chairs' => $this->ids($chairs, 'sub_department_id')];
    }

    /**
     * Is an announcement's scope among these programs or sub-departments?
     *
     * @param array<string, mixed> $row
     * @param list<int>|null       $programIds
     * @param list<int>|null       $subDepartmentIds
     */
    private function inScope(array $row, ?array $programIds, ?array $subDepartmentIds): bool
    {
        $type = (string) $row['scope_type'];
        $id   = (int) $row['scope_id'];

        if ($subDepartmentIds !== null) {
            if ($subDepartmentIds === []) {
                return false;
            }

            return match ($type) {
                'sub_department' => in_array($id, $subDepartmentIds, true),
                'department'     => db_connect()->table('sub_departments')->where('department_id', $id)->whereIn('id', $subDepartmentIds)->countAllResults() > 0,
                'program'        => db_connect()->table('programs')->where('id', $id)->whereIn('sub_department_id', $subDepartmentIds)->countAllResults() > 0,
                default          => false,
            };
        }

        if ($programIds === null || $programIds === []) {
            return false;
        }

        return match ($type) {
            'program'        => in_array($id, $programIds, true),
            'sub_department' => db_connect()->table('programs')->where('sub_department_id', $id)->whereIn('id', $programIds)->countAllResults() > 0,
            'department'     => db_connect()->table('programs p')->join('sub_departments sd', 'sd.id = p.sub_department_id')->where('sd.department_id', $id)->whereIn('p.id', $programIds)->countAllResults() > 0,
            default          => false,
        };
    }

    /** @return list<int> */
    private function programsInScope(string $type, int $id): array
    {
        $builder = db_connect()->table('programs p')->select('p.id')->join('sub_departments sd', 'sd.id = p.sub_department_id');

        match ($type) {
            'program'        => $builder->where('p.id', $id),
            'sub_department' => $builder->where('sd.id', $id),
            default          => $builder->where('sd.department_id', $id),
        };

        return $this->ids($builder->get()->getResultArray());
    }

    /** @return list<int> */
    private function subDepartmentsInScope(string $type, int $id): array
    {
        $builder = db_connect()->table('sub_departments sd')->select('sd.id');

        match ($type) {
            'program'        => $builder->join('programs p', 'p.sub_department_id = sd.id')->where('p.id', $id),
            'sub_department' => $builder->where('sd.id', $id),
            default          => $builder->where('sd.department_id', $id),
        };

        return $this->ids($builder->get()->getResultArray());
    }

    /**
     * Tells everyone an announcement is for, except the dean who posted it.
     *
     * @param array<string, mixed> $row
     */
    private function tell(array $row, User $dean): int
    {
        $people = $this->recipients($row);

        // Each one is sent to their own page for it, by the highest role they are reached as, and told once.
        $byArea = ['chairperson' => $people['chairpersons'], 'instructor' => $people['instructors'], 'student' => $people['students']];
        $told   = [];
        $count  = 0;

        foreach ($byArea as $area => $users) {
            $users = array_values(array_diff($users, $told, [(int) $dean->id]));
            $told  = array_merge($told, $users);

            $count += $this->notifications->sendToMany($users, 'department_announcement', $row['title'], mb_strimwidth((string) $row['message'], 0, 140, '…'), "department-announcement:{$row['id']}", (int) $row['institution_id'], site_url($area . '/announcements'));
        }

        return $count;
    }

    private function reachCount(array $row): int
    {
        $p = $this->recipients($row);

        return count(array_unique(array_merge($p['students'], $p['instructors'], $p['chairpersons'])));
    }

    /**
     * "department", "sub_department:ID" or "program:ID" of this dean's department; type null when it is
     * not one of theirs.
     *
     * @return array{0: ?string, 1: int}
     */
    private function parseScope(string $scope, User $dean, int $departmentId): array
    {
        if ($scope === 'department') {
            return ['department', $departmentId];
        }

        if (preg_match('/^(sub_department|program):(\d{1,9})$/', $scope, $m) !== 1) {
            return [null, 0];
        }

        $id = (int) $m[2];

        if ($m[1] === 'program') {
            return $this->access->canAccessProgram($dean, $id) ? ['program', $id] : [null, 0];
        }

        $ok = db_connect()->table('sub_departments')->where(['id' => $id, 'department_id' => $departmentId])->countAllResults() > 0;

        return $ok ? ['sub_department', $id] : [null, 0];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function validateContent(array $input): array
    {
        $errors  = [];
        $title   = trim((string) ($input['title'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $endsOn  = trim((string) ($input['ends_on'] ?? ''));

        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'Give it a title of up to ' . self::MAX_TITLE . ' characters.';
        }

        if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE) {
            $errors['message'] = 'Write the message, in up to ' . self::MAX_MESSAGE . ' characters.';
        }

        if ($endsOn !== '' && ($this->date($endsOn) === null || $endsOn < date('Y-m-d'))) {
            $errors['ends_on'] = 'Choose the last day it is shown, today or later.';
        }

        return $errors;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** Why this person may not post or change announcements, or null when they may: the dean of a department, acting as dean. */
    private function mayAnnounce(User $dean): ?string
    {
        if ((new ActiveRoleService())->resolve($dean) !== 'dean' || $this->access->departmentFor($dean) === null) {
            return 'Only the dean of a department posts these.';
        }

        return null;
    }

    /** @return array<string, mixed>|string the dean's own department's row, or why not */
    private function ownRow(User $dean, int $id): array|string
    {
        $refusal = $this->mayAnnounce($dean);

        if ($refusal !== null) {
            return $refusal;
        }

        $row = $this->find($id);

        return $row !== null && (int) $row['department_id'] === (int) $this->access->departmentFor($dean)['department_id'] ? $row : 'That announcement is not in your department.';
    }

    /** @return array<string, string> "department:ID", "sub_department:ID" and "program:ID" => name */
    private function scopeLabels(int $departmentId): array
    {
        $db     = db_connect();
        $labels = [];

        $name = $db->table('departments')->select('name')->where('id', $departmentId)->get()->getRow('name');
        $labels['department:' . $departmentId] = 'Whole ' . ($name ?? 'department');

        foreach ($db->table('sub_departments')->select(['id', 'name'])->where('department_id', $departmentId)->orderBy('name', 'ASC')->get()->getResultArray() as $sd) {
            $labels['sub_department:' . $sd['id']] = (string) $sd['name'];

            foreach ($db->table('programs')->select(['id', 'name'])->where('sub_department_id', (int) $sd['id'])->orderBy('name', 'ASC')->get()->getResultArray() as $p) {
                $labels['program:' . $p['id']] = $sd['name'] . ' · ' . $p['name'];
            }
        }

        return $labels;
    }

    private function institutionOf(int $departmentId): int
    {
        return (int) db_connect()->table('departments')->select('institution_id')->where('id', $departmentId)->get()->getRow('institution_id');
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, string>
     */
    private function names(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return [];
        }

        $names = [];

        foreach (db_connect()->table('user_profiles')->select(['user_id', "TRIM(CONCAT_WS(' ', first_name, last_name)) AS name"], false)->whereIn('user_id', $userIds)->get()->getResultArray() as $row) {
            $names[(int) $row['user_id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * @param list<array<string, mixed>>|list<mixed> $rows rows with an "id", or bare values when $key is given
     *
     * @return list<int>
     */
    private function ids(array $rows, ?string $key = null): array
    {
        $values = $key === null ? array_column($rows, 'id') : $rows;

        return array_values(array_unique(array_filter(array_map('intval', $values))));
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, User $dean, int $id, ?array $old, ?array $new): void
    {
        (new AuditLogger())->log($action, $dean, [
            'target_type' => 'department_announcement',
            'target_id'   => $id,
            'acting_role' => 'dean',
            'old'         => $old,
            'new'         => $new,
        ]);
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array{success: false, message: string, errors: array<string, string>}
     */
    private function refused(string $message, array $errors = []): array
    {
        return ['success' => false, 'message' => $message, 'errors' => $errors];
    }
}
