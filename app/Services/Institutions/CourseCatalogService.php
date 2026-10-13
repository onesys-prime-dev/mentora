<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Sis\SisOwnership;
use CodeIgniter\Shield\Entities\User;

/**
 * An institution's course catalog, as the institutional administrator keeps
 * it: courses belong to a program, are never deleted (they are archived,
 * because offerings, work and grades hang below), and keep their code, title
 * and units locked while the student information system supplies them.
 *
 * Rules:
 *  - The code is unique within its program; capitals, numbers, spaces, dots,
 *    dashes, underscores.
 *  - Nothing is created under an inactive program, and nothing is reactivated
 *    under one.
 *  - A course cannot be switched off while it has offerings in an active term.
 *  - While the SIS connection is enabled, a course the SIS supplied cannot
 *    have its code, title or units changed by hand (its description can).
 *  - Every id is checked against the institution first (404 for a foreign
 *    one), and every change is audited with old and new values.
 */
class CourseCatalogService
{
    public const PER_PAGE = 25;

    public const STATUSES = ['active', 'inactive', 'archived'];

    /** sort key => column */
    private const SORTS = [
        'code'    => 'c.course_code',
        'title'   => 'c.course_title',
        'units'   => 'c.units',
        'status'  => 'c.status',
        'program' => 'p.code',
    ];

    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9 ._-]{0,49}$/';

    /**
     * @param array{q?: string, program?: int, status?: string, sort?: string, dir?: string, page?: int} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function list(int $institutionId, array $filters = []): array
    {
        $db = db_connect();

        $builder = $db->table('courses c')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId);

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $builder->groupStart()->like('c.course_code', $q)->orLike('c.course_title', $q)->groupEnd();
        }

        if (((int) ($filters['program'] ?? 0)) > 0) {
            $builder->where('c.program_id', (int) $filters['program']);
        }

        $status = (string) ($filters['status'] ?? '');

        if (in_array($status, self::STATUSES, true)) {
            $builder->where('c.status', $status);
        }

        $total = (clone $builder)->countAllResults();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, (int) ($filters['page'] ?? 1)), $pages);

        $sort = self::SORTS[$filters['sort'] ?? 'code'] ?? self::SORTS['code'];
        $dir  = ($filters['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $rows = $builder
            ->select('c.*, p.code AS program_code, p.name AS program_name, p.status AS program_status, sd.name AS sub_department_name')
            ->select('(SELECT COUNT(*) FROM course_offerings o WHERE o.course_id = c.id AND o.status != \'archived\') AS offering_count', false)
            ->select('EXISTS (SELECT 1 FROM sis_links l JOIN sis_connections sc ON sc.institution_id = l.institution_id AND sc.enabled = 1 WHERE l.entity = \'course\' AND l.local_id = c.id) AS sis_managed', false)
            ->orderBy($sort, $dir)
            ->orderBy('c.id', 'ASC')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            ->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Programs a course can be filed under (active ones), for the forms.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function programs(int $institutionId, bool $onlyActive = true): array
    {
        $builder = db_connect()->table('programs p')
            ->select('p.id, p.code, p.name')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->orderBy('p.code');

        if ($onlyActive) {
            $builder->where('p.status', 'active');
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']],
            $builder->get()->getResultArray(),
        );
    }

    /**
     * @param array<string, mixed> $input program_id, course_code, course_title, units, description
     *
     * @return int the new course's id
     *
     * @throws StructureException
     */
    public function create(int $institutionId, array $input, ?User $actor = null): int
    {
        $programId = (int) ($input['program_id'] ?? 0);

        $this->scope($institutionId)->require('program', $programId);

        $program = db_connect()->table('programs')->where('id', $programId)->get()->getRowArray();

        if ($program['status'] !== 'active') {
            throw StructureException::field('program_id', "{$program['name']} is not active. Reactivate it before adding a course.");
        }

        $values = $this->values($input);

        $this->codeIsFree($programId, $values['course_code'], null);

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('courses')->insert($values + ['program_id' => $programId, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $db->insertID();

        $this->audit('structure.course_created', $actor, $institutionId, $id, null, $values + ['program_id' => $programId]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input course_code, course_title, units, description
     *
     * @throws StructureException
     */
    public function update(int $institutionId, int $id, array $input, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('course', $id);

        $before = $this->row($id);
        $values = $this->values($input);

        $changed = [];

        foreach ($values as $field => $value) {
            $was = $before[$field] ?? null;

            $same = is_numeric($was) && is_numeric($value)
                ? abs((float) $was - (float) $value) < 0.0001
                : (string) ($was ?? '') === (string) ($value ?? '');

            if (! $same) {
                $changed[$field] = $value;
            }
        }

        if ($changed === []) {
            return;
        }

        $locked = array_intersect(array_keys($changed), SisOwnership::COURSE_FIELDS);

        if ($locked !== [] && (new SisOwnership())->managesCourse($id)) {
            $first = (string) array_values($locked)[0];

            throw StructureException::field($first, 'The student information system manages the code, title and units of this course. Change them there; the next sync brings it here.');
        }

        if (isset($changed['course_code'])) {
            $this->codeIsFree((int) $before['program_id'], $changed['course_code'], $id);
        }

        $old = [];

        foreach (array_keys($changed) as $field) {
            $old[$field] = $before[$field];
        }

        db_connect()->table('courses')->where('id', $id)->update($changed + ['updated_at' => date('Y-m-d H:i:s')]);

        $this->audit('structure.course_updated', $actor, $institutionId, $id, $old, $changed);
    }

    /**
     * @throws StructureException
     */
    public function setStatus(int $institutionId, int $id, string $status, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('course', $id);

        if (! in_array($status, self::STATUSES, true)) {
            throw new StructureException('That is not a status a course can have.');
        }

        $row = $this->row($id);

        if ($row['status'] === $status) {
            return;
        }

        if ($status === 'active') {
            $program = db_connect()->table('programs')->where('id', $row['program_id'])->get()->getRowArray();

            if ($program['status'] !== 'active') {
                throw new StructureException("{$program['name']} is not active. Reactivate it first.");
            }
        } elseif ($row['status'] === 'active') {
            $busy = (int) db_connect()->table('course_offerings o')
                ->join('academic_terms t', 't.id = o.term_id')
                ->where('o.course_id', $id)
                ->where('o.status', 'active')
                ->where('t.status', 'active')
                ->countAllResults();

            if ($busy > 0) {
                throw new StructureException("{$row['course_code']} cannot be switched off yet: it still has {$busy} offering" . ($busy === 1 ? '' : 's') . ' in an active term. Deal with those first, then try again.');
            }
        }

        db_connect()->table('courses')->where('id', $id)->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);

        $word = $status === 'active' ? 'reactivated' : ($status === 'archived' ? 'archived' : 'deactivated');

        $this->audit("structure.course_{$word}", $actor, $institutionId, $id, ['status' => $row['status']], ['status' => $status]);
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
    private function row(int $id): array
    {
        return db_connect()->table('courses')->where('id', $id)->get()->getRowArray() ?? [];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{course_code: string, course_title: string, units: float, description: ?string}
     *
     * @throws StructureException
     */
    private function values(array $input): array
    {
        $code  = strtoupper(trim((string) ($input['course_code'] ?? '')));
        $title = trim((string) ($input['course_title'] ?? ''));
        $units = trim((string) ($input['units'] ?? ''));
        $text  = trim((string) ($input['description'] ?? ''));

        if ($code === '' || preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw StructureException::field('course_code', 'The code is required: letters, numbers, spaces, dots, dashes or underscores, up to 50 characters, starting with a letter or number.');
        }

        if (mb_strlen($title) < 3 || mb_strlen($title) > 255) {
            throw StructureException::field('course_title', 'Give the course a title of 3 to 255 characters.');
        }

        if ($units === '' || ! is_numeric($units) || (float) $units <= 0 || (float) $units > 99.9) {
            throw StructureException::field('units', 'Units must be a number above 0 and at most 99.9.');
        }

        if (mb_strlen($text) > 5000) {
            throw StructureException::field('description', 'The description can be at most 5000 characters.');
        }

        return ['course_code' => $code, 'course_title' => $title, 'units' => round((float) $units, 1), 'description' => $text === '' ? null : $text];
    }

    /**
     * @throws StructureException
     */
    private function codeIsFree(int $programId, string $code, ?int $exceptId): void
    {
        $builder = db_connect()->table('courses')->where('program_id', $programId)->where('course_code', $code);

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw StructureException::field('course_code', "Another course in this program already uses the code {$code}.");
        }
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, ?User $actor, int $institutionId, int $id, ?array $old, ?array $new): void
    {
        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'course',
            'target_id'      => $id,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}
