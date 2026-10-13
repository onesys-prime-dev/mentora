<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;

/**
 * The one answer to "which institution am I working in, and does this thing
 * belong to it?" for everything in the institutional administrator's area.
 *
 * The institution is always decided on the server from the signed-in person,
 * never from an id in the address or a form. Every id that does arrive (a
 * department, a term, a course, an offering, a person) is checked against it
 * before anything is read or written, and a foreign id answers 404 rather
 * than 403: the page does not confirm that the thing exists elsewhere.
 *
 * Soft-deleted rows do not count as owned.
 */
class InstitutionScope
{
    private ?int $institutionId = null;

    private bool $resolved = false;

    public function __construct(private readonly ?User $user = null)
    {
    }

    /**
     * A scope for an institution that is already known (a service that was
     * handed the institution id by its controller, or a command), rather than
     * one worked out from whoever is signed in.
     */
    public static function forInstitution(int $institutionId): self
    {
        $scope = new self();

        $scope->institutionId = $institutionId;
        $scope->resolved      = true;

        return $scope;
    }

    /** The administrator's institution, or null when they belong to none. */
    public function institutionId(): ?int
    {
        if (! $this->resolved) {
            $user = $this->user ?? auth()->user();

            $this->institutionId = $user === null ? null : (new PlanLimits())->institutionOf((int) $user->id);
            $this->resolved      = true;
        }

        return $this->institutionId;
    }

    /**
     * The institution's id, or a 404 for someone who belongs to none.
     *
     * @throws PageNotFoundException
     */
    public function requireInstitutionId(): int
    {
        return $this->institutionId() ?? throw PageNotFoundException::forPageNotFound();
    }

    public function ownsDepartment(int $id): bool
    {
        return $this->count('departments d', 'd.id', $id, 'd.institution_id') > 0;
    }

    public function ownsSubDepartment(int $id): bool
    {
        return $this->countThrough(
            'sub_departments sd',
            ['departments d' => 'd.id = sd.department_id'],
            'sd.id',
            $id,
        ) > 0;
    }

    public function ownsProgram(int $id): bool
    {
        return $this->countThrough(
            'programs p',
            ['sub_departments sd' => 'sd.id = p.sub_department_id', 'departments d' => 'd.id = sd.department_id'],
            'p.id',
            $id,
        ) > 0;
    }

    public function ownsCourse(int $id): bool
    {
        return $this->countThrough(
            'courses c',
            ['programs p' => 'p.id = c.program_id', 'sub_departments sd' => 'sd.id = p.sub_department_id', 'departments d' => 'd.id = sd.department_id'],
            'c.id',
            $id,
        ) > 0;
    }

    public function ownsOffering(int $id): bool
    {
        return $this->countThrough(
            'course_offerings o',
            [
                'courses c'          => 'c.id = o.course_id',
                'programs p'         => 'p.id = c.program_id',
                'sub_departments sd' => 'sd.id = p.sub_department_id',
                'departments d'      => 'd.id = sd.department_id',
            ],
            'o.id',
            $id,
        ) > 0;
    }

    public function ownsTerm(int $id): bool
    {
        return $this->count('academic_terms t', 't.id', $id, 't.institution_id') > 0;
    }

    /** A member of the institution (not removed). */
    public function ownsPerson(int $id): bool
    {
        $institutionId = $this->institutionId();

        if ($institutionId === null) {
            return false;
        }

        return db_connect()->table('institution_users iu')
            ->join('users u', 'u.id = iu.user_id')
            ->where('iu.institution_id', $institutionId)
            ->where('iu.user_id', $id)
            ->where('u.deleted_at IS NULL', null, false)
            ->countAllResults() > 0;
    }

    /**
     * Passes when the thing is the institution's own, otherwise a 404.
     *
     * @param 'department'|'subDepartment'|'program'|'course'|'offering'|'term'|'person' $kind
     *
     * @throws PageNotFoundException
     */
    public function require(string $kind, int $id): void
    {
        $owned = match ($kind) {
            'department'    => $this->ownsDepartment($id),
            'subDepartment' => $this->ownsSubDepartment($id),
            'program'       => $this->ownsProgram($id),
            'course'        => $this->ownsCourse($id),
            'offering'      => $this->ownsOffering($id),
            'term'          => $this->ownsTerm($id),
            'person'        => $this->ownsPerson($id),
            default         => false,
        };

        if (! $owned) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function count(string $table, string $idColumn, int $id, string $institutionColumn): int
    {
        $institutionId = $this->institutionId();

        if ($institutionId === null) {
            return 0;
        }

        return db_connect()->table($table)->where($idColumn, $id)->where($institutionColumn, $institutionId)->countAllResults();
    }

    /**
     * @param array<string, string> $joins table alias => join condition, ending at the departments table (alias d)
     */
    private function countThrough(string $table, array $joins, string $idColumn, int $id): int
    {
        $institutionId = $this->institutionId();

        if ($institutionId === null) {
            return 0;
        }

        $builder = db_connect()->table($table);

        foreach ($joins as $joinTable => $condition) {
            $builder->join($joinTable, $condition);
        }

        return $builder->where($idColumn, $id)->where('d.institution_id', $institutionId)->countAllResults();
    }
}
