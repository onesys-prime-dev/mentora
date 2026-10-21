<?php

declare(strict_types=1);

namespace App\Services\Parents;

use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;

/**
 * Which children a parent may reach, and which of their courses.
 *
 * Derived live from `parent_student_links`, never from a stored list, so a
 * link the administrator removes (or a student who leaves the institution)
 * takes effect on the next request. The rules:
 *
 *  - The person must hold the parent role.
 *  - Only the parent's own (current) institution counts; a link made in
 *    another institution is not followed.
 *  - The student must still belong to that institution and not be deleted. A
 *    deactivated student stays reachable: the parent keeps their history.
 *  - A course is reachable when the child is linked and enrolled in it, in
 *    any enrolment status (a dropped or finished course stays visible).
 */
class ParentAccessService
{
    /**
     * @return list<array{student_id: int, name: string, institution_id: int}> sorted by name
     */
    public function children(User $parent): array
    {
        $institutionId = $this->institutionOf($parent);

        if ($institutionId === null) {
            return [];
        }

        $rows = db_connect()->table('parent_student_links l')
            ->select('l.student_user_id AS student_id, up.first_name, up.last_name')
            ->join('institution_users iu', 'iu.user_id = l.student_user_id AND iu.institution_id = l.institution_id', 'inner', false)
            ->join('users u', 'u.id = l.student_user_id AND u.deleted_at IS NULL', 'inner', false)
            ->join('user_profiles up', 'up.user_id = l.student_user_id', 'left')
            ->where('l.institution_id', $institutionId)
            ->where('l.parent_user_id', (int) $parent->id)
            ->get()->getResultArray();

        $children = array_map(static fn (array $row): array => [
            'student_id'     => (int) $row['student_id'],
            'name'           => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'institution_id' => $institutionId,
        ], $rows);

        usort($children, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: $a['student_id'] <=> $b['student_id']);

        return $children;
    }

    public function canViewChild(User $parent, int $studentId): bool
    {
        if ($studentId < 1) {
            return false;
        }

        foreach ($this->children($parent) as $child) {
            if ($child['student_id'] === $studentId) {
                return true;
            }
        }

        return false;
    }

    public function canViewOffering(User $parent, int $studentId, int $offeringId): bool
    {
        if ($offeringId < 1 || ! $this->canViewChild($parent, $studentId)) {
            return false;
        }

        return db_connect()->table('enrollments')
            ->where(['student_user_id' => $studentId, 'offering_id' => $offeringId])
            ->countAllResults() > 0;
    }

    private function institutionOf(User $parent): ?int
    {
        if (! $parent->inGroup('parent')) {
            return null;
        }

        return (new PlanLimits())->institutionOf((int) $parent->id);
    }
}
