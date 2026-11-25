<?php

declare(strict_types=1);

namespace App\Services\Profiles;

/** Pure FR2 visibility rules: which profile fields a viewer may see. No database. */
final class ProfilePolicy
{
    private const KEYS = [
        'photo', 'names', 'pronouns', 'roles', 'bio', 'staff_info', 'email', 'student_details',
        'courses', 'children', 'parents', 'admin_actions', 'deactivated_badge',
    ];

    private const STAFF_ROLES = ['dean', 'chairperson', 'instructor', 'institutional_admin'];

    private const STAFF_FULL         = ['photo', 'names', 'pronouns', 'roles', 'bio', 'staff_info', 'email', 'courses'];
    private const STUDENT_FOR_STAFF  = ['photo', 'names', 'pronouns', 'roles', 'bio', 'student_details', 'email', 'courses'];
    private const STUDENT_FOR_PEER   = ['photo', 'names', 'pronouns', 'roles', 'bio', 'student_details'];
    private const STUDENT_FOR_PARENT = ['photo', 'names', 'pronouns', 'roles', 'bio', 'student_details', 'courses'];
    private const PARENT_NAME_ONLY   = ['photo', 'names', 'roles'];
    private const BASICS             = ['photo', 'names', 'roles', 'deactivated_badge'];

    /** @return list<string> visible keys in canonical order; [] = not visible */
    public function fields(ViewContext $c): array
    {
        if ($c->isSelf) {
            return $this->canonical($this->ownFields($c));
        }
        if ($c->subjectIsSystemAdmin || ! $c->sameInstitution || $c->viewerRank === null) {
            return [];
        }
        $fields = $this->canonical($this->visibleToViewer($c));
        if ($fields === []) {
            return [];
        }
        // D25: admins manage deactivated people in People, so their admin row stays.
        if ($c->viewerIsInstitutionAdmin) {
            $fields[] = 'admin_actions';
        }
        if (! $c->subjectActive) {
            // only people the viewer could see if active
            return $this->canonical([...self::BASICS, ...($c->viewerIsInstitutionAdmin ? ['admin_actions'] : [])]);
        }

        return $fields;
    }

    /** @return list<string> */
    private function ownFields(ViewContext $c): array
    {
        if ($c->subjectIsSystemAdmin) {
            return ['names', 'email', 'roles'];
        }
        $f = ['photo', 'names', 'pronouns', 'roles', 'bio', 'email'];
        if (array_intersect($c->subjectRoles, self::STAFF_ROLES) !== []) {
            array_push($f, 'staff_info', 'courses');
        }
        if (in_array('student', $c->subjectRoles, true)) {
            array_push($f, 'student_details', 'courses', 'parents');
        }
        if (in_array('parent', $c->subjectRoles, true)) {
            $f[] = 'children';
        }

        return $f;
    }

    /** @return list<string> */
    private function visibleToViewer(ViewContext $c): array
    {
        $isStaff   = array_intersect($c->subjectRoles, self::STAFF_ROLES) !== [];
        $isStudent = in_array('student', $c->subjectRoles, true);
        $viewer    = $c->viewerRank;
        $staffView = in_array($viewer, self::STAFF_ROLES, true);

        $staffGrant = $isStaff ? self::STAFF_FULL : [];
        if ($isStudent) {
            // D31: contact and courses of someone who is a student follow the student column.
            $staffGrant = array_diff($staffGrant, ['email', 'courses']);
        }

        $studentGrant = $isStudent ? $this->studentGrant($c) : [];

        $parentGrant = [];
        if (! $isStaff && ! $isStudent) {
            $parentGrant = ($staffView || ($viewer === 'student' && $c->viewerIsChildOfSubject)) ? self::PARENT_NAME_ONLY : [];
        }

        return [...$staffGrant, ...$studentGrant, ...$parentGrant];
    }

    /** @return list<string> what the viewer's row grants for a subject who is a student; the own-child grant applies whatever the viewer's rank (D28) */
    private function studentGrant(ViewContext $c): array
    {
        $grant = match (true) {
            in_array($c->viewerRank, self::STAFF_ROLES, true) => self::STUDENT_FOR_STAFF,
            $c->viewerRank === 'student'                      => self::STUDENT_FOR_PEER,
            default                                           => [],
        };

        return $c->viewerIsParentOfSubject ? [...$grant, ...self::STUDENT_FOR_PARENT] : $grant;
    }

    /**
     * The subject's roles this viewer may see, in RoleRank order.
     *
     * @return list<string>
     */
    public function roles(ViewContext $c): array
    {
        if (! in_array('roles', $this->fields($c), true)) {
            return [];
        }
        if ($c->isSelf) {
            return $c->subjectIsSystemAdmin ? ['admin'] : $c->subjectRoles;
        }
        $staffView = in_array($c->viewerRank, self::STAFF_ROLES, true);

        return array_values(array_filter($c->subjectRoles, fn (string $r): bool => match (true) {
            $r === 'student' => $this->studentGrant($c) !== [],
            $r === 'parent'  => $staffView || $c->viewerIsChildOfSubject,
            default          => true,
        }));
    }

    /**
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private function canonical(array $fields): array
    {
        return array_values(array_intersect(self::KEYS, $fields));
    }
}
