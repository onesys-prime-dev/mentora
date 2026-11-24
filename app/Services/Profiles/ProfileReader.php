<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Services\Parents\ParentAccessService;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/** Builds the profile model for a subject; keys outside the policy's $fields are never present. */
class ProfileReader
{
    /**
     * The only way to read a profile: null means "not visible", so callers answer 404.
     *
     * @return array<string, mixed>|null
     */
    public function forViewer(User $viewer, int $subjectId): ?array
    {
        $context = (new ProfileContextResolver())->for($viewer, $subjectId);
        if ($context === null) {
            return null;
        }
        $policy = new ProfilePolicy();
        $fields = $policy->fields($context);

        return $fields === [] ? null : $this->build($subjectId, $fields, $policy->roles($context));
    }

    /**
     * What a student of the owner's school, with no tie to them, would see of the owner.
     *
     * @return array<string, mixed>|null null when that student would see nothing
     */
    public function forPreview(User $owner): ?array
    {
        $id    = (int) $owner->id;
        $roles = RoleRank::sort(array_column(db_connect()->table('auth_groups_users')->select('group')->where('user_id', $id)->get()->getResultArray(), 'group'));
        $c      = new ViewContext(false, true, 'student', $roles, true, false, false, false, $owner->inGroup('admin'));
        $policy = new ProfilePolicy();
        $fields = $policy->fields($c);

        return $fields === [] ? null : $this->build($id, $fields, $policy->roles($c));
    }

    /**
     * @param list<string> $fields       from ProfilePolicy::fields()
     * @param list<string> $visibleRoles from ProfilePolicy::roles()
     *
     * @return array<string, mixed>
     */
    private function build(int $subjectId, array $fields, array $visibleRoles): array
    {
        $db  = db_connect();
        $row = $db->table('user_profiles up')
            ->select('up.*, ai.secret AS email, p.name AS program_name, c.name AS college_name')
            ->join('auth_identities ai', "ai.user_id = up.user_id AND ai.type = 'email_password'", 'left', false)
            ->join('programs p', 'p.id = up.program_id', 'left')
            ->join('departments d', 'd.id = p.department_id', 'left')
            ->join('colleges c', 'c.id = d.college_id', 'left')
            ->where('up.user_id', $subjectId)->get()->getRowArray() ?? [];

        $first = trim((string) (($row['preferred_first_name'] ?? '') ?: ($row['first_name'] ?? '')));
        $last  = (string) ($row['last_name'] ?? '');
        $has   = static fn (string $k): bool => in_array($k, $fields, true);

        $m = ['id' => $subjectId];
        if ($has('names')) {
            $m['display_name'] = trim($first . ' ' . $last);
            $m['legal_name']   = implode(' ', array_filter([$row['first_name'] ?? '', $row['middle_name'] ?? '', $last, $row['suffix'] ?? ''], static fn ($p): bool => trim((string) $p) !== ''));
        }
        // "prefer not to say" is kept for the owner's dialog but shown as nothing.
        $m['pronouns'] = $has('pronouns') && ($row['pronouns'] ?? null) !== 'prefer not to say' ? ($row['pronouns'] ?? null) : null;

        $roles = RoleRank::sort(array_column($db->table('auth_groups_users')->select('group')->where('user_id', $subjectId)->get()->getResultArray(), 'group'));

        if ($has('roles')) {
            $m['roles'] = array_map(fn (string $r): array => ['role' => $r, 'detail' => $this->detail($r, $subjectId, $row)], $visibleRoles);
        }
        if ($has('bio')) {
            $m['bio'] = $row['bio'] ?? null;
        }
        if ($has('staff_info')) {
            $m['staff_info'] = ['office_location' => $row['office_location'] ?? null, 'consultation_hours' => $row['consultation_hours'] ?? null, 'work_phone' => $row['work_phone'] ?? null];
        }
        if ($has('email')) {
            $m['email'] = $row['email'] ?? null;
        }
        if ($has('student_details')) {
            $section = $this->section($subjectId);
            $m['student_details'] = ['program' => $row['program_name'] ?? null, 'year' => $section['year'], 'section' => $section['section'], 'college' => $row['college_name'] ?? null];
        }
        if ($has('courses')) {
            $m['courses'] = $this->courses($subjectId, $roles);
        }
        if ($has('children')) {
            $subject       = model(UserModel::class)->findById($subjectId);
            $m['children'] = $subject === null ? [] : array_map(static fn (array $c): array => ['id' => $c['student_id'], 'name' => $c['name']], (new ParentAccessService())->children($subject));
        }
        if ($has('parents')) {
            $ids          = array_column((new ParentAccessService())->parentsOf($subjectId), 'parent_id');
            $m['parents'] = array_values(array_map(static fn (array $a): array => ['id' => $a['id'], 'name' => $a['name']], (new AvatarData())->forMany($ids)));
        }
        if ($has('photo')) {
            $m['photo'] = null; // Phase 4
        }
        if ($has('admin_actions')) {
            $m['admin_actions'] = true;
        }
        if ($has('deactivated_badge')) {
            $m['deactivated_badge'] = true;
        }

        return $m;
    }

    /** @return array{year:?int, section:?string} the student's class section in the current term */
    private function section(int $userId): array
    {
        $termId = $this->currentTermId((new PlanLimits())->institutionOf($userId));
        $row    = $termId === null ? null : db_connect()->table('class_section_students css')->select('cs.year_level, cs.code')
            ->join('class_sections cs', 'cs.id = css.class_section_id')
            ->where('css.student_user_id', $userId)->where('cs.term_id', $termId)->orderBy('cs.id')->limit(1)->get()->getRowArray();

        return ['year' => $row === null ? null : (int) $row['year_level'], 'section' => $row['code'] ?? null];
    }

    /** @param array<string, mixed> $row */
    private function detail(string $role, int $userId, array $row): ?string
    {
        $db = db_connect();

        return match ($role) {
            'instructor'  => $this->instructorDetail($userId, $row['home_department_id'] ?? null),
            'chairperson' => $db->table('chairperson_assignments ca')->select('d.name')->join('departments d', 'd.id = ca.department_id')
                ->where('ca.chairperson_user_id', $userId)->where('ca.status', 'active')->orderBy('d.name')->limit(1)->get()->getRow('name'),
            'dean'        => $db->table('dean_assignments da')->select('c.name')->join('colleges c', 'c.id = da.college_id')
                ->where('da.dean_user_id', $userId)->where('da.status', 'active')->orderBy('c.name')->limit(1)->get()->getRow('name'),
            'institutional_admin' => $db->table('institutions')->select('name')->where('id', (new PlanLimits())->institutionOf($userId))->get()->getRow('name'),
            default       => null,
        };
    }

    private function instructorDetail(int $userId, mixed $homeDepartmentId): ?string
    {
        $db = db_connect();

        if ($homeDepartmentId !== null) {
            $name = $db->table('departments')->select('name')->where('id', $homeDepartmentId)->get()->getRow('name');
            if ($name !== null) {
                return (string) $name;
            }
        }

        $termId = $this->currentTermId((new PlanLimits())->institutionOf($userId));
        if ($termId === null) {
            return null;
        }

        $name = $db->table('instructor_assignments ia')->select('d.name, COUNT(*) AS n')
            ->join('course_offerings o', 'o.id = ia.offering_id')
            ->join('courses c', 'c.id = o.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('departments d', 'd.id = p.department_id')
            ->where('ia.instructor_user_id', $userId)->where('o.term_id', $termId)
            ->groupBy('d.id, d.name')->orderBy('n', 'DESC')->orderBy('d.name')->limit(1)->get()->getRow('name');

        return $name === null ? null : 'Teaches in ' . $name;
    }

    /** The term containing today, else the one that began most recently (as PlanLimits::aiWindowStart does). */
    private function currentTermId(?int $institutionId): ?int
    {
        if ($institutionId === null) {
            return null;
        }
        $today = date('Y-m-d');
        $t     = static fn () => db_connect()->table('academic_terms')->select('id')->where('institution_id', $institutionId)->where('start_date <=', $today)->orderBy('start_date', 'DESC');
        $id    = $t()->where('end_date >=', $today)->get()->getRow('id') ?? $t()->get()->getRow('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param list<string> $roles
     *
     * @return list<array{offering_id:int, code:string, title:string}>
     */
    private function courses(int $userId, array $roles): array
    {
        $termId = $this->currentTermId((new PlanLimits())->institutionOf($userId));
        if ($termId === null) {
            return [];
        }

        $db   = db_connect();
        $rows = [];
        foreach (['instructor' => ['instructor_assignments', 'instructor_user_id'], 'student' => ['enrollments', 'student_user_id']] as $role => [$table, $col]) {
            if (! in_array($role, $roles, true)) {
                continue;
            }
            $q = $db->table("{$table} x")->select('o.id AS offering_id, c.course_code AS code, c.course_title AS title')
                ->join('course_offerings o', 'o.id = x.offering_id')->join('courses c', 'c.id = o.course_id')
                ->where("x.{$col}", $userId)->where('o.term_id', $termId);
            if ($role === 'student') {
                $q->where('x.status', 'enrolled');
            }
            $rows = [...$rows, ...$q->get()->getResultArray()];
        }

        return array_values(array_map(static fn (array $r): array => ['offering_id' => (int) $r['offering_id'], 'code' => (string) $r['code'], 'title' => (string) $r['title']], $rows));
    }
}
