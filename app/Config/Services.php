<?php

namespace Config;

use App\Services\Audit\AuditLogger;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\MysqldumpRunner;
use App\Services\Monitoring\AdminHome;
use App\Services\Sis\CredentialVault;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /** The shared audit log writer; tests can replace it with injectMock('auditLogger', ...). */
    public static function auditLogger(bool $getShared = true): AuditLogger
    {
        if ($getShared) {
            return static::getSharedInstance('auditLogger');
        }

        return new AuditLogger();
    }

    /** What the admin home page works out; tests replace it with injectMock('adminHome', ...) to fix the health checks. */
    public static function adminHome(bool $getShared = true): AdminHome
    {
        if ($getShared) {
            return static::getSharedInstance('adminHome');
        }

        return new AdminHome();
    }

    /** Whatever makes database dumps; tests replace it with injectMock('backupRunner', ...). */
    public static function backupRunner(bool $getShared = true): BackupRunner
    {
        if ($getShared) {
            return static::getSharedInstance('backupRunner');
        }

        return new MysqldumpRunner();
    }

    /** Seals integration credentials; tests give it their own key with injectMock('credentialVault', ...). */
    public static function credentialVault(bool $getShared = true): CredentialVault
    {
        if ($getShared) {
            return static::getSharedInstance('credentialVault');
        }

        return new CredentialVault();
    }

    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
}
