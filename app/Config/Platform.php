<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Defaults for the platform settings the system administrator can change.
 *
 * A value saved through App\Services\Admin\PlatformSettings wins over the
 * default here; deleting the saved value brings the default back. Which keys
 * exist, their types and limits, and whether an institution may override them
 * are defined in PlatformSettings::DEFINITIONS.
 */
class Platform extends BaseConfig
{
    /** Audit entries older than this are archived (SA3). */
    public int $auditRetentionDays = 730;

    /** A deleted institution can be restored for this long, then it is purged (SA4). */
    public int $deletedInstitutionRetentionDays = 90;

    /** Whether administrators can see the source IP stored with audit entries (SA3). */
    public bool $auditShowSourceIp = false;

    /** How long an impersonation session may last (SA5). */
    public int $impersonationMinutes = 30;

    /** A backup older than this raises an alert (SA11). */
    public int $backupAlertHours = 24;

    /** Days before the end date that an institution is warned (SA13). */
    public int $expiryWarningDays = 30;

    /** Days of full access after the end date (SA13). */
    public int $expiryGraceDays = 14;

    /** Days of read-only access after the grace period, before the institution becomes past (SA13). */
    public int $expiryReadOnlyDays = 30;

    /** How long an invitation link works before a new one has to be sent (SA4). */
    public int $invitationValidityDays = 7;

    /** How long a password reset link works (SA5). */
    public int $passwordResetValidityHours = 24;

    /** The platform's name, shown in the admin header and in emails (SA12). */
    public string $systemName = 'Mentora';

    /** Who people are told to contact for help: an email address or a line of text (SA12). */
    public string $supportContact = '';

    /** The timezone times are shown and calculated in, an IANA name such as Asia/Manila (SA12). */
    public string $timezone = 'UTC';

    /** While on, only system administrators can use the platform (SA12). */
    public bool $maintenanceMode = false;

    /** What people see while maintenance mode is on (SA12). */
    public string $maintenanceMessage = 'Mentora is down for maintenance. Please try again shortly.';

    /** A line shown at the top of every page for everyone; empty for none (SA12). */
    public string $announcement = '';

    /** Shortest password accepted when someone sets one (SA8). */
    public int $passwordMinLength = 8;

    /** Minutes of inactivity after which a signed-in person is signed out (SA8). */
    public int $sessionTimeoutMinutes = 120;

    /** Failed sign-ins in a row that lock an account's sign-in; 0 turns the lockout off (SA8). */
    public int $lockoutAttempts = 5;

    /** Minutes a locked account's sign-in stays locked (SA8). */
    public int $lockoutMinutes = 15;

    /** Roles that must confirm a code sent by email when signing in, comma separated. `admin` is always included (SA8). */
    public string $twoFactorRoles = 'admin';

    /** An institution's own banner text (only ever set per institution); empty for none. */
    public string $institutionAnnouncement = '';

    /** The last day (YYYY-MM-DD) that banner is shown; empty for no end. */
    public string $institutionAnnouncementUntil = '';

    /** Whether an institution's administrator has left the parent portal on (only ever switched off per institution). */
    public bool $parentPortalEnabled = true;
}
