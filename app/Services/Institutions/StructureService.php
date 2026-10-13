<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;

/**
 * An institution's academic structure: departments, sub-departments and
 * programs. Created, renamed, switched off and back on by the institutional
 * administrator; nothing is deleted, because courses, offerings and grades
 * hang below.
 *
 * Rules:
 *  - A code is upper case letters, numbers, dots, dashes or underscores, and
 *    unique where the database says: departments within the institution,
 *    sub-departments within their department, programs within their
 *    sub-department.
 *  - Nothing is created under an inactive parent, and nothing is reactivated
 *    under one.
 *  - A node cannot be switched off while it still has active children, or
 *    offerings in an active term: the message says which.
 *  - Every id is checked against the institution first (a foreign one is a
 *    404, see InstitutionScope), and every change is audited with old and new.
 */
class StructureService
{
    public const PROGRAM_STATUSES = ['active', 'inactive', 'archived'];

    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9._-]{0,49}$/';

    /**
     * The whole structure for one institution: departments, each with its
     * sub-departments, each with its programs, and the counts to show.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(int $institutionId): array
    {
        $db = db_connect();

        $departments = $db->table('departments')->where('institution_id', $institutionId)->orderBy('code')->get()->getResultArray();

        $subs = $db->table('sub_departments sd')
            ->select('sd.*')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->orderBy('sd.code')
            ->get()->getResultArray();

        $programs = $db->table('programs p')
            ->select('p.*, (SELECT COUNT(*) FROM courses c WHERE c.program_id = p.id AND c.status != \'archived\') AS course_count', false)
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->orderBy('p.code')
            ->get()->getResultArray();

        $programsBySub = [];

        foreach ($programs as $program) {
            $programsBySub[(int) $program['sub_department_id']][] = $program;
        }

        $subsByDepartment = [];

        foreach ($subs as $sub) {
            $sub['programs']                              = $programsBySub[(int) $sub['id']] ?? [];
            $subsByDepartment[(int) $sub['department_id']][] = $sub;
        }

        foreach ($departments as &$department) {
            $department['subDepartments'] = $subsByDepartment[(int) $department['id']] ?? [];
        }

        unset($department);

        return $departments;
    }

    /*
    |--------------------------------------------------------------------------
    | Departments
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $input code, name, location, established_year
     *
     * @return int the new department's id
     *
     * @throws StructureException
     */
    public function createDepartment(int $institutionId, array $input, ?User $actor = null): int
    {
        $values = $this->departmentValues($input);

        $this->codeIsFree('departments', ['institution_id' => $institutionId], $values['code'], null, 'department');

        $db = db_connect();
        $db->table('departments')->insert($values + ['institution_id' => $institutionId, 'is_active' => 1] + $this->stamps(true));
        $id = (int) $db->insertID();

        $this->audit('structure.department_created', $actor, $institutionId, 'department', $id, null, $values);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws StructureException
     */
    public function updateDepartment(int $institutionId, int $id, array $input, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('department', $id);

        $values = $this->departmentValues($input);
        $before = $this->row('departments', $id);

        $this->codeIsFree('departments', ['institution_id' => $institutionId], $values['code'], $id, 'department');

        $this->change('departments', $id, $values, $before, ['code', 'name', 'location', 'established_year'], 'structure.department_updated', $actor, $institutionId, 'department');
    }

    /**
     * @throws StructureException
     */
    public function setDepartmentActive(int $institutionId, int $id, bool $active, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('department', $id);

        $row = $this->row('departments', $id);

        if ((int) $row['is_active'] === ($active ? 1 : 0)) {
            return;
        }

        if (! $active) {
            $this->refuseWhileBusy($row['name'], $this->departmentBlockers($id));
        }

        db_connect()->table('departments')->where('id', $id)->update(['is_active' => $active ? 1 : 0] + $this->stamps(false));

        $this->audit($active ? 'structure.department_reactivated' : 'structure.department_deactivated', $actor, $institutionId, 'department', $id, ['is_active' => ! $active], ['is_active' => $active]);
    }

    /*
    |--------------------------------------------------------------------------
    | Sub-departments
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $input code, name
     *
     * @throws StructureException
     */
    public function createSubDepartment(int $institutionId, int $departmentId, array $input, ?User $actor = null): int
    {
        $this->scope($institutionId)->require('department', $departmentId);

        $department = $this->row('departments', $departmentId);

        if ((int) $department['is_active'] !== 1) {
            throw new StructureException("{$department['name']} is inactive. Reactivate it before adding a sub-department.");
        }

        $values = $this->nameAndCode($input, 'sub-department');

        $this->codeIsFree('sub_departments', ['department_id' => $departmentId], $values['code'], null, 'sub-department');

        $db = db_connect();
        $db->table('sub_departments')->insert($values + ['department_id' => $departmentId, 'is_active' => 1] + $this->stamps(true));
        $id = (int) $db->insertID();

        $this->audit('structure.sub_department_created', $actor, $institutionId, 'sub_department', $id, null, $values + ['department_id' => $departmentId]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws StructureException
     */
    public function updateSubDepartment(int $institutionId, int $id, array $input, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('subDepartment', $id);

        $values = $this->nameAndCode($input, 'sub-department');
        $before = $this->row('sub_departments', $id);

        $this->codeIsFree('sub_departments', ['department_id' => (int) $before['department_id']], $values['code'], $id, 'sub-department');

        $this->change('sub_departments', $id, $values, $before, ['code', 'name'], 'structure.sub_department_updated', $actor, $institutionId, 'sub_department');
    }

    /**
     * @throws StructureException
     */
    public function setSubDepartmentActive(int $institutionId, int $id, bool $active, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('subDepartment', $id);

        $row = $this->row('sub_departments', $id);

        if ((int) $row['is_active'] === ($active ? 1 : 0)) {
            return;
        }

        if ($active) {
            $department = $this->row('departments', (int) $row['department_id']);

            if ((int) $department['is_active'] !== 1) {
                throw new StructureException("{$department['name']} is inactive. Reactivate it first.");
            }
        } else {
            $this->refuseWhileBusy($row['name'], $this->subDepartmentBlockers($id));
        }

        db_connect()->table('sub_departments')->where('id', $id)->update(['is_active' => $active ? 1 : 0] + $this->stamps(false));

        $this->audit($active ? 'structure.sub_department_reactivated' : 'structure.sub_department_deactivated', $actor, $institutionId, 'sub_department', $id, ['is_active' => ! $active], ['is_active' => $active]);
    }

    /*
    |--------------------------------------------------------------------------
    | Programs
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $input code, name
     *
     * @throws StructureException
     */
    public function createProgram(int $institutionId, int $subDepartmentId, array $input, ?User $actor = null): int
    {
        $this->scope($institutionId)->require('subDepartment', $subDepartmentId);

        $sub = $this->row('sub_departments', $subDepartmentId);

        if ((int) $sub['is_active'] !== 1) {
            throw new StructureException("{$sub['name']} is inactive. Reactivate it before adding a program.");
        }

        $values = $this->nameAndCode($input, 'program');

        $this->codeIsFree('programs', ['sub_department_id' => $subDepartmentId], $values['code'], null, 'program');

        $db = db_connect();
        $db->table('programs')->insert($values + ['sub_department_id' => $subDepartmentId, 'status' => 'active'] + $this->stamps(true));
        $id = (int) $db->insertID();

        $this->audit('structure.program_created', $actor, $institutionId, 'program', $id, null, $values + ['sub_department_id' => $subDepartmentId]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws StructureException
     */
    public function updateProgram(int $institutionId, int $id, array $input, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('program', $id);

        $values = $this->nameAndCode($input, 'program');
        $before = $this->row('programs', $id);

        $this->codeIsFree('programs', ['sub_department_id' => (int) $before['sub_department_id']], $values['code'], $id, 'program');

        $this->change('programs', $id, $values, $before, ['code', 'name'], 'structure.program_updated', $actor, $institutionId, 'program');
    }

    /**
     * @throws StructureException
     */
    public function setProgramStatus(int $institutionId, int $id, string $status, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('program', $id);

        if (! in_array($status, self::PROGRAM_STATUSES, true)) {
            throw new StructureException('That is not a status a program can have.');
        }

        $row = $this->row('programs', $id);

        if ($row['status'] === $status) {
            return;
        }

        if ($status === 'active') {
            $sub = $this->row('sub_departments', (int) $row['sub_department_id']);

            if ((int) $sub['is_active'] !== 1) {
                throw new StructureException("{$sub['name']} is inactive. Reactivate it first.");
            }
        } elseif ($row['status'] === 'active') {
            $this->refuseWhileBusy($row['name'], $this->programBlockers($id));
        }

        db_connect()->table('programs')->where('id', $id)->update(['status' => $status] + $this->stamps(false));

        $this->audit('structure.program_' . ($status === 'active' ? 'reactivated' : ($status === 'archived' ? 'archived' : 'deactivated')), $actor, $institutionId, 'program', $id, ['status' => $row['status']], ['status' => $status]);
    }

    /*
    |--------------------------------------------------------------------------
    | What still depends on a node
    |--------------------------------------------------------------------------
    */

    /** @return list<string> reasons, such as "2 active sub-departments" */
    public function departmentBlockers(int $id): array
    {
        $db = db_connect();

        return $this->reasons(
            [
                'active sub-department' => (int) $db->table('sub_departments')->where(['department_id' => $id, 'is_active' => 1])->countAllResults(),
                'offering in an active term' => $this->activeTermOfferings('d.id', $id),
            ],
        );
    }

    /** @return list<string> */
    public function subDepartmentBlockers(int $id): array
    {
        $db = db_connect();

        return $this->reasons(
            [
                'active program' => (int) $db->table('programs')->where(['sub_department_id' => $id, 'status' => 'active'])->countAllResults(),
                'offering in an active term' => $this->activeTermOfferings('sd.id', $id),
            ],
        );
    }

    /** @return list<string> */
    public function programBlockers(int $id): array
    {
        $db = db_connect();

        return $this->reasons(
            [
                'active course' => (int) $db->table('courses')->where(['program_id' => $id, 'status' => 'active'])->countAllResults(),
                'offering in an active term' => $this->activeTermOfferings('p.id', $id),
            ],
        );
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

    /** Offerings in a term that is currently active, below the node found by `$column = $id`. */
    private function activeTermOfferings(string $column, int $id): int
    {
        return (int) db_connect()->table('course_offerings o')
            ->join('academic_terms t', 't.id = o.term_id')
            ->join('courses c', 'c.id = o.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('t.status', 'active')
            ->where('o.status', 'active')
            ->where($column, $id)
            ->countAllResults();
    }

    /**
     * @param array<string, int> $counts singular noun => how many
     *
     * @return list<string>
     */
    private function reasons(array $counts): array
    {
        $reasons = [];

        foreach ($counts as $noun => $count) {
            if ($count > 0) {
                $reasons[] = $count . ' ' . $noun . ($count === 1 ? '' : 's');
            }
        }

        return $reasons;
    }

    /**
     * @param list<string> $blockers
     *
     * @throws StructureException
     */
    private function refuseWhileBusy(string $name, array $blockers): void
    {
        if ($blockers === []) {
            return;
        }

        throw new StructureException("{$name} cannot be switched off yet: it still has " . implode(' and ', $blockers) . ". Deal with those first, then try again.");
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{code: string, name: string, location: ?string, established_year: ?int}
     *
     * @throws StructureException
     */
    private function departmentValues(array $input): array
    {
        $values = $this->nameAndCode($input, 'department');

        $location = trim((string) ($input['location'] ?? ''));

        if (mb_strlen($location) > 255) {
            throw StructureException::field('location', 'The location can be at most 255 characters.');
        }

        $year = trim((string) ($input['established_year'] ?? ''));

        if ($year !== '' && (preg_match('/^\d{4}$/', $year) !== 1 || (int) $year < 1800 || (int) $year > (int) (new DateTimeImmutable())->format('Y'))) {
            throw StructureException::field('established_year', 'Enter a four-digit year between 1800 and this year, or leave it empty.');
        }

        return $values + ['location' => $location === '' ? null : $location, 'established_year' => $year === '' ? null : (int) $year];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{code: string, name: string}
     *
     * @throws StructureException
     */
    private function nameAndCode(array $input, string $kind): array
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));

        if ($code === '' || preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw StructureException::field('code', 'The code is required: letters, numbers, dots, dashes or underscores, up to 50 characters, starting with a letter or number.');
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 255) {
            throw StructureException::field('name', "Give the {$kind} a name of 3 to 255 characters.");
        }

        return ['code' => $code, 'name' => $name];
    }

    /**
     * @param array<string, int> $parent column => value that scopes the code
     *
     * @throws StructureException
     */
    private function codeIsFree(string $table, array $parent, string $code, ?int $exceptId, string $kind): void
    {
        $builder = db_connect()->table($table)->where($parent)->where('code', $code);

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw StructureException::field('code', "Another {$kind} already uses the code {$code}.");
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $before
     * @param list<string>         $fields the columns that are editable
     */
    private function change(string $table, int $id, array $values, array $before, array $fields, string $action, ?User $actor, int $institutionId, string $targetType): void
    {
        $old = [];
        $new = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $values) && (string) ($before[$field] ?? '') !== (string) ($values[$field] ?? '')) {
                $old[$field] = $before[$field] ?? null;
                $new[$field] = $values[$field];
            }
        }

        if ($new === []) {
            return;
        }

        db_connect()->table($table)->where('id', $id)->update($new + $this->stamps(false));

        $this->audit($action, $actor, $institutionId, $targetType, $id, $old, $new);
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $id): array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray() ?? [];
    }

    /** @return array<string, string> */
    private function stamps(bool $creating): array
    {
        $now = date('Y-m-d H:i:s');

        return $creating ? ['created_at' => $now, 'updated_at' => $now] : ['updated_at' => $now];
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, ?User $actor, int $institutionId, string $type, int $id, ?array $old, ?array $new): void
    {
        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => $type,
            'target_id'      => $id,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}
