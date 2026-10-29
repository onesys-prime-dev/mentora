<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The platform's permission settings and each institution's live side by side
 * in role_permissions (audit decision U1b): institution 0 is the platform.
 *
 * @internal
 */
final class PermissionMatrixLayersTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    public function testThePlatformMatrixIgnoresAnInstitutionsRows(): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => 7, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);
        PermissionMatrix::flush();

        $this->assertContains('grading.manage', (new PermissionMatrix())->effective()['instructor']);
    }

    public function testTheSameBoxCanBeSetOnceForThePlatformAndOncePerInstitution(): void
    {
        $db = db_connect();
        $db->table('role_permissions')->insert(['institution_id' => 0, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);
        $db->table('role_permissions')->insert(['institution_id' => 7, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 1]);

        $this->assertSame(2, $db->table('role_permissions')->where(['role' => 'instructor', 'permission' => 'grading.manage'])->countAllResults());

        try {
            $again = $db->table('role_permissions')->insert(['institution_id' => 7, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);
        } catch (DatabaseException) {
            $again = false;
        }

        $this->assertFalse($again);
    }

    public function testTheSystemAdministratorsChangeIsStoredOnThePlatformLayer(): void
    {
        (new PermissionMatrix())->set('instructor', 'announcements.manage', false, CourseFactory::user('admin'));

        $row = db_connect()->table('role_permissions')->where(['role' => 'instructor', 'permission' => 'announcements.manage'])->get()->getRowArray();

        $this->assertSame(0, (int) $row['institution_id']);
    }
}
