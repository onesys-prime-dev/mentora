<?php

declare(strict_types=1);

/**
 * This file is part of CodeIgniter Shield.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Config;

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

/**
 * Roles, permissions and which role has which permission.
 *
 * DEFAULT_MATRIX is what ships. A system administrator can change it from
 * the roles page; those changes live in the role_permissions table and are
 * applied on top of the defaults when this config is built, so every
 * `$user->can()` and `permission:` route filter sees them on the next
 * request. See PermissionMatrix.
 *
 * The `admin` role (system administrator) is locked: its row below is always
 * used as it is.
 */
class AuthGroups extends ShieldAuthGroups
{
    public string $defaultGroup = 'user';

    public array $groups = [
        'student' => [
            'title'       => 'Student',
            'description' => 'Student Users',
        ],
        'instructor' => [
            'title'       => 'Instructor',
            'description' => 'Instructor Users',
        ],
        'chairperson' => [
            'title'       => 'Chairperson',
            'description' => 'Chairperson Users',
        ],
        'parent' => [
            'title'       => 'Parent',
            'description' => 'Parent Users',
        ],
        'institutional_admin' => [
            'title'       => 'Institutional Admin',
            'description' => 'Institutional Admin Users',
        ],
        'admin' => [
            'title'       => 'Admin',
            'description' => 'System Admin Users.',
        ],
    ];

    /**
     * Platform permissions come as view/manage pairs per area and belong to
     * the system administrator; academic permissions belong to the roles that
     * work inside an institution.
     */
    public array $permissions = [
        // Platform
        'institutions.view'    => 'View institutions',
        'institutions.manage'  => 'Create, edit, suspend, delete and restore institutions',
        'subscriptions.view'   => 'View plans and subscriptions',
        'subscriptions.manage' => 'Manage plans, subscriptions and renewals',
        'users.view'           => 'View users across all institutions',
        'users.manage'         => 'Create, edit, deactivate and sign out users',
        'roles.view'           => 'View roles and permissions',
        'roles.manage'         => 'Change which role has which permission',
        'flags.view'           => 'View feature flags',
        'flags.manage'         => 'Turn feature flags on or off',
        'settings.view'        => 'View platform settings',
        'settings.manage'      => 'Change platform settings',
        'audit.view'           => 'View and export the audit log',
        'audit.manage'         => 'Change audit log settings',
        'monitoring.view'      => 'View system health and performance',
        'monitoring.manage'    => 'Change monitoring settings',
        'backup.view'          => 'View backup history',
        'backup.manage'        => 'Start backups',
        'integrations.view'    => 'View integrations',
        'integrations.manage'  => 'Configure integrations',
        'impersonation.start'  => 'View the platform as another user (view only)',

        // Inside an institution
        'courses.view'          => 'View courses',
        'courses.manage'        => 'Edit course content and settings',
        'grading.manage'        => 'Grade work and manage the gradebook',
        'announcements.manage'  => 'Post and manage announcements',
        'program.monitor'       => 'Monitor a whole program',
    ];

    /**
     * What each role has before any customisation.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULT_MATRIX = [
        // Platform-wide. No academic permissions: academic data is reached only
        // by viewing as a user, never directly.
        'admin' => [
            'institutions.view', 'institutions.manage',
            'subscriptions.view', 'subscriptions.manage',
            'users.view', 'users.manage',
            'roles.view', 'roles.manage',
            'flags.view', 'flags.manage',
            'settings.view', 'settings.manage',
            'audit.view', 'audit.manage',
            'monitoring.view', 'monitoring.manage',
            'backup.view', 'backup.manage',
            'integrations.view', 'integrations.manage',
            'impersonation.start',
        ],
        'institutional_admin' => ['courses.view', 'program.monitor'],
        'chairperson'         => ['courses.view', 'courses.manage', 'grading.manage', 'announcements.manage', 'program.monitor'],
        'instructor'          => ['courses.view', 'courses.manage', 'grading.manage', 'announcements.manage'],
        'student'             => ['courses.view'],
        'parent'              => ['courses.view'],
    ];

    /** @var array<string, list<string>> */
    public array $matrix = self::DEFAULT_MATRIX;

    public function __construct()
    {
        parent::__construct();

        $this->matrix = PermissionMatrix::apply(self::DEFAULT_MATRIX, PermissionMatrix::storedOverrides(), array_keys($this->permissions));
    }
}
