<?php

declare(strict_types=1);

namespace App\Services\Parents;

use App\Models\Courses\EnrollmentModel;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

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

    /**
     * The parents who can reach a student now: linked in the student's
     * institution, still active, holding the parent role and members of that
     * institution, with the student still a member of it and not deleted.
     * Whether the institution's parent portal is on is a separate question
     * (ParentPortal).
     *
     * @return list<array{parent_id: int, institution_id: int}>
     */
    public function parentsOf(int $studentId): array
    {
        if ($studentId < 1) {
            return [];
        }

        $rows = db_connect()->table('parent_student_links l')
            ->distinct()
            ->select('l.parent_user_id AS parent_id, l.institution_id')
            ->join('users p', 'p.id = l.parent_user_id AND p.deleted_at IS NULL AND p.active = 1', 'inner', false)
            ->join('auth_groups_users g', "g.user_id = p.id AND g.`group` = 'parent'", 'inner', false)
            ->join('institution_users pi', 'pi.user_id = l.parent_user_id AND pi.institution_id = l.institution_id', 'inner', false)
            ->join('institution_users si', 'si.user_id = l.student_user_id AND si.institution_id = l.institution_id', 'inner', false)
            ->join('users s', 's.id = l.student_user_id AND s.deleted_at IS NULL', 'inner', false)
            ->where('l.student_user_id', $studentId)
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => ['parent_id' => (int) $row['parent_id'], 'institution_id' => (int) $row['institution_id']], $rows);
    }

    /**
     * The names of the parents who can see a student's progress right now (for
     * the student to read): those of {@see parentsOf()} whose institution has the
     * parent portal switched on. Names only, in order.
     *
     * @return list<string>
     */
    public function parentNamesWithAccess(int $studentId): array
    {
        $portal = new ParentPortal();
        $names  = [];

        foreach ($this->parentsOf($studentId) as $parent) {
            if (! $portal->enabled($parent['institution_id'])) {
                continue;
            }

            $profile = db_connect()->table('user_profiles')->select('first_name, last_name')->where('user_id', $parent['parent_id'])->get()->getRowArray() ?? [];
            $name    = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));

            $names[] = $name !== '' ? $name : 'A parent';
        }

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
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

    /**
     * Whether the shared course services will read this person as a student. A student who also
     * holds a staff role is read as that staff member (instructor-shaped data, drafts, and for a
     * dean a role lookup that would overwrite the parent's own), so no grade or assessment is
     * read for them.
     */
    public static function readsAsStudent(User $child): bool
    {
        return array_diff($child->getGroups(), ['student', 'parent']) === [];
    }

    /** The child as a user (to ask the student's own services about them), or null when the parent may not reach them. */
    public function childUser(User $parent, int $studentId): ?User
    {
        return $this->canViewChild($parent, $studentId)
            ? model(UserModel::class)->findById($studentId)
            : null;
    }

    /**
     * One of the child's courses as a course card (code, title, term, teacher,
     * `enrollment_status`, `offering_status`...), whatever the enrolment status;
     * null when the parent may not reach the child or the child is not in it.
     *
     * @return ?array<string, mixed>
     */
    public function childOffering(User $parent, int $studentId, int $offeringId): ?array
    {
        if (! $this->canViewOffering($parent, $studentId, $offeringId)) {
            return null;
        }

        foreach ((new EnrollmentModel())->retrieveStudentCourseOfferings($studentId, false, true) as $row) {
            if ((int) $row['offering_id'] === $offeringId) {
                return $row;
            }
        }

        return null;
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
