<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Shield\Entities\User;

/**
 * An institution's academic terms, as the institutional administrator keeps
 * them. A term is upcoming, active or completed; here a term is created,
 * edited and moved between upcoming and active. Completing a term (which
 * also completes its offerings and enrolments) is the separate "close a
 * term" flow, so nothing here can leave a completed term with live work in it.
 *
 * Rules:
 *  - A name of 2 to 100 characters and an academic year ("2026-2027" or
 *    "2026"); the three together are unique within the institution.
 *  - Real dates, the end after the start, and not longer than about two years.
 *  - Overlapping terms and more than one active term are allowed but come
 *    back as warnings, because schools do run overlapping terms.
 *  - A completed term is closed: it cannot be edited or reopened here.
 *  - An active term with students enrolled cannot go back to upcoming.
 */
class TermService
{
    public const SETTABLE = ['upcoming', 'active'];

    /**
     * The institution's terms, newest first, with what hangs below each and
     * which other terms overlap it.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $institutionId): array
    {
        $db = db_connect();

        $terms = $db->table('academic_terms t')
            ->select('t.*')
            ->select('(SELECT COUNT(*) FROM course_offerings o WHERE o.term_id = t.id AND o.status != \'archived\') AS offering_count', false)
            ->select('(SELECT COUNT(*) FROM enrollments e JOIN course_offerings o2 ON o2.id = e.offering_id WHERE o2.term_id = t.id AND e.status = \'enrolled\') AS enrolment_count', false)
            ->where('t.institution_id', $institutionId)
            ->orderBy('t.start_date', 'DESC')
            ->orderBy('t.id', 'DESC')
            ->get()->getResultArray();

        foreach ($terms as &$term) {
            $term['overlaps'] = [];

            if ($term['status'] === 'archived') {
                continue;
            }

            foreach ($terms as $other) {
                if ((int) $other['id'] !== (int) $term['id'] && $other['status'] !== 'archived'
                    && TermRules::overlap((string) $term['start_date'], (string) $term['end_date'], (string) $other['start_date'], (string) $other['end_date'])) {
                    $term['overlaps'][] = (string) $other['name'];
                }
            }
        }

        unset($term);

        return $terms;
    }

    /**
     * @param array<string, mixed> $input name, academic_year, start_date, end_date
     *
     * @return array{id: int, warnings: list<string>}
     *
     * @throws StructureException
     */
    public function create(int $institutionId, array $input, ?User $actor = null): array
    {
        $values = $this->values($input);

        $this->nameIsFree($institutionId, $values, null);

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('academic_terms')->insert($values + ['institution_id' => $institutionId, 'status' => 'upcoming', 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $db->insertID();

        $this->audit('structure.term_created', $actor, $institutionId, $id, null, $values);

        return ['id' => $id, 'warnings' => $this->overlapWarnings($institutionId, $id, $values)];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string> warnings
     *
     * @throws StructureException
     */
    public function update(int $institutionId, int $id, array $input, ?User $actor = null): array
    {
        $this->scope($institutionId)->require('term', $id);

        $before = $this->row($id);

        if ($before['status'] === 'completed' || $before['status'] === 'archived') {
            throw new StructureException("{$before['name']} is closed, so it cannot be edited.");
        }

        $values = $this->values($input);

        $this->nameIsFree($institutionId, $values, $id);

        $old = [];
        $new = [];

        foreach ($values as $field => $value) {
            if ((string) $before[$field] !== (string) $value) {
                $old[$field] = $before[$field];
                $new[$field] = $value;
            }
        }

        if ($new === []) {
            return [];
        }

        db_connect()->table('academic_terms')->where('id', $id)->update($new + ['updated_at' => date('Y-m-d H:i:s')]);

        $this->audit('structure.term_updated', $actor, $institutionId, $id, $old, $new);

        return $this->overlapWarnings($institutionId, $id, $values);
    }

    /**
     * Moves a term between upcoming and active.
     *
     * @return list<string> warnings (another term is also active)
     *
     * @throws StructureException
     */
    public function setStatus(int $institutionId, int $id, string $status, ?User $actor = null): array
    {
        $this->scope($institutionId)->require('term', $id);

        if (! in_array($status, self::SETTABLE, true)) {
            throw new StructureException('A term is closed with the "close a term" step, not from here.');
        }

        $row = $this->row($id);

        if (in_array($row['status'], ['completed', 'archived'], true)) {
            throw new StructureException("{$row['name']} is closed, so its status cannot be changed.");
        }

        if ($row['status'] === $status) {
            return [];
        }

        if ($status === 'upcoming') {
            $enrolled = (int) db_connect()->table('enrollments e')
                ->join('course_offerings o', 'o.id = e.offering_id')
                ->where('o.term_id', $id)
                ->where('e.status', 'enrolled')
                ->countAllResults();

            if ($enrolled > 0) {
                throw new StructureException("{$row['name']} has {$enrolled} student" . ($enrolled === 1 ? '' : 's') . ' enrolled, so it cannot go back to upcoming.');
            }
        }

        db_connect()->table('academic_terms')->where('id', $id)->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit($status === 'active' ? 'structure.term_started' : 'structure.term_set_upcoming', $actor, $institutionId, $id, ['status' => $row['status']], ['status' => $status]);

        if ($status !== 'active') {
            return [];
        }

        $others = db_connect()->table('academic_terms')->select('name')->where(['institution_id' => $institutionId, 'status' => 'active'])->where('id !=', $id)->get()->getResultArray();

        return $others === [] ? [] : ['Another term is also active: ' . implode(', ', array_column($others, 'name')) . '. That is allowed, but check it is what you meant.'];
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
        return db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray() ?? [];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, academic_year: string, start_date: string, end_date: string}
     *
     * @throws StructureException
     */
    private function values(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $year = TermRules::academicYear((string) ($input['academic_year'] ?? ''));
        $from = TermRules::date((string) ($input['start_date'] ?? ''));
        $to   = TermRules::date((string) ($input['end_date'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw StructureException::field('name', 'Give the term a name of 2 to 100 characters, such as "First Semester".');
        }

        if ($year === null) {
            throw StructureException::field('academic_year', 'Write the academic year as 2026-2027 (the second year follows the first), or just 2026.');
        }

        if ($from === null) {
            throw StructureException::field('start_date', 'Choose the date the term starts.');
        }

        if ($to === null) {
            throw StructureException::field('end_date', 'Choose the date the term ends.');
        }

        if ($to <= $from) {
            throw StructureException::field('end_date', 'The term must end after it starts.');
        }

        if (TermRules::days($from, $to) > TermRules::MAX_DAYS) {
            throw StructureException::field('end_date', 'That term would run for more than two years. Check the dates.');
        }

        return ['name' => $name, 'academic_year' => $year, 'start_date' => $from, 'end_date' => $to];
    }

    /**
     * @param array<string, string> $values
     *
     * @throws StructureException
     */
    private function nameIsFree(int $institutionId, array $values, ?int $exceptId): void
    {
        $builder = db_connect()->table('academic_terms')->where([
            'institution_id' => $institutionId,
            'name'           => $values['name'],
            'academic_year'  => $values['academic_year'],
        ]);

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw StructureException::field('name', "There is already a term called {$values['name']} in {$values['academic_year']}.");
        }
    }

    /**
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    private function overlapWarnings(int $institutionId, int $id, array $values): array
    {
        $names = [];

        $rows = db_connect()->table('academic_terms')
            ->select('name, start_date, end_date')
            ->where('institution_id', $institutionId)
            ->where('id !=', $id)
            ->where('status !=', 'archived')
            ->get()->getResultArray();

        foreach ($rows as $row) {
            if (TermRules::overlap($values['start_date'], $values['end_date'], (string) $row['start_date'], (string) $row['end_date'])) {
                $names[] = (string) $row['name'];
            }
        }

        return $names === [] ? [] : ['This term overlaps ' . implode(', ', $names) . '. That is allowed, but check the dates.'];
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, ?User $actor, int $institutionId, int $id, ?array $old, ?array $new): void
    {
        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'term',
            'target_id'      => $id,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}
