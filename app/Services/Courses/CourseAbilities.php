<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Services\Auth\ActiveRoleService;
use App\Services\Auth\RolePermissions;
use CodeIgniter\Shield\Entities\User;

/**
 * Which parts of a course this person may change (audit decisions 7.4, D6):
 * its content (courses.manage), the gradebook and grading (grading.manage),
 * announcements (announcements.manage) and moderating discussions
 * (discussions.manage). Someone with a read-only view (the dean, the
 * institution's administrator) changes none. A course tab the person may not
 * change shows what is there in its read-only form, as the dean sees it; the
 * server refuses the change as well (CoursePermissionFilter).
 */
final class CourseAbilities
{
    public const PERMISSIONS = [
        'content'       => 'courses.manage',
        'grading'       => 'grading.manage',
        'announcements' => 'announcements.manage',
        'discussions'   => 'discussions.manage',
    ];

    /** True when this part of the course is to be shown without its controls. */
    public static function viewOnly(?User $user, string $area): bool
    {
        if ($user === null || CourseViewer::isReadOnlyViewer($user)) {
            return true;
        }

        // Judged by the role they are acting as, so an institution's change to one of two roles holds.
        return ! (new RolePermissions())->allows($user, self::PERMISSIONS[$area], (new ActiveRoleService())->resolve($user));
    }

    /** @return array{content: bool, grading: bool, announcements: bool, discussions: bool} what may be changed, for the course page's scripts */
    public static function for(?User $user): array
    {
        $can = [];

        foreach (array_keys(self::PERMISSIONS) as $area) {
            $can[$area] = ! self::viewOnly($user, $area);
        }

        return $can;
    }
}
