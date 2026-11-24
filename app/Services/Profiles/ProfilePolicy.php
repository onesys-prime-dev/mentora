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
        if (! $c->subjectActive) {
            return self::BASICS; // only people the viewer could see if active
        }
        if ($c->viewerIsInstitutionAdmin) {
            $fields[] = 'admin_actions';
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

        $studentGrant = [];
        if ($isStudent) {
            $studentGrant = match (true) {
                $staffView                => self::STUDENT_FOR_STAFF,
                $viewer === 'student'     => self::STUDENT_FOR_PEER,
                $c->viewerIsParentOfSubject => self::STUDENT_FOR_PARENT,
                default                   => [],
            };
        }

        $parentGrant = [];
        if (! $isStaff && ! $isStudent) {
            $parentGrant = ($staffView || ($viewer === 'student' && $c->viewerIsChildOfSubject)) ? self::PARENT_NAME_ONLY : [];
        }

        return [...$staffGrant, ...$studentGrant, ...$parentGrant];
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
