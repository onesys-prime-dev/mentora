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
}
