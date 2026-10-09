<?php

declare(strict_types=1);

use App\Models\Users\RolePermissionModel;
use App\Services\Admin\PermissionMatrix;
use App\Services\Audit\AuditLogger;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Config\AuthGroups;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Changing one cell of the role/permission matrix: what is stored, the rules
 * that refuse a change, the audit entry, and the undo when auditing fails.
 *
 * @internal
 */
final class PermissionMatrixChangeTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private PermissionMatrix $matrix;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::flush();
        Services::resetSingle('auditLogger');

        $this->matrix = new PermissionMatrix();
        $this->admin  = CourseFactory::user('admin');
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function rowCount(): int
    {
        return db_connect()->table('role_permissions')->countAllResults();
    }

    /** @return list<array<string, mixed>> */
    private function audit(): array
    {
        return db_connect()->table('audit_logs')->where('action', 'roles.permission_changed')->orderBy('id')->get()->getResultArray();
    }

    /*
    |--------------------------------------------------------------------------
    | What is stored
    |--------------------------------------------------------------------------
    */

    public function testGrantingAPermissionStoresAGrantAndTheMatrixChangesAtOnce(): void
    {
        $this->assertTrue($this->matrix->set('student', 'announcements.manage', true, $this->admin));

        $row = db_connect()->table('role_permissions')->get()->getRowArray();

        $this->assertSame('student', $row['role']);
        $this->assertSame('announcements.manage', $row['permission']);
        $this->assertSame(1, (int) $row['granted']);
        $this->assertTrue($this->matrix->has('student', 'announcements.manage'), 'no waiting for the next request');
    }

    public function testRevokingADefaultStoresADenial(): void
    {
        $this->assertTrue($this->matrix->set('instructor', 'grading.manage', false, $this->admin));

        $this->assertSame(0, (int) db_connect()->table('role_permissions')->get()->getRowArray()['granted']);
        $this->assertFalse($this->matrix->has('instructor', 'grading.manage'));
        $this->assertTrue($this->matrix->has('chairperson', 'grading.manage'));
    }

    public function testPuttingACellBackToItsDefaultRemovesTheRow(): void
    {
        $this->matrix->set('instructor', 'grading.manage', false, $this->admin);
        $this->matrix->set('instructor', 'grading.manage', true, $this->admin);

        $this->assertSame(0, $this->rowCount(), 'only differences from the defaults are kept');
        $this->assertSame(AuthGroups::DEFAULT_MATRIX, $this->matrix->effective());
    }

    public function testChangingTheSameCellTwiceLeavesOneRow(): void
    {
        $this->matrix->set('student', 'announcements.manage', true, $this->admin);
        $this->matrix->set('student', 'announcements.manage', false, $this->admin);
        $this->matrix->set('student', 'announcements.manage', true, $this->admin);

        $this->assertSame(1, $this->rowCount());
    }

    public function testAskingForWhatIsAlreadyTrueDoesNothing(): void
    {
        $this->assertFalse($this->matrix->set('instructor', 'courses.view', true, $this->admin));
        $this->assertFalse($this->matrix->set('student', 'grading.manage', false, $this->admin));

        $this->assertSame(0, $this->rowCount());
        $this->assertSame([], $this->audit(), 'a non-change is not an event');
    }

    public function testPermissionNamesAreCaseInsensitive(): void
    {
        $this->assertTrue($this->matrix->set('student', 'Announcements.Manage', true, $this->admin));
        $this->assertTrue($this->matrix->has('student', 'announcements.manage'));
    }

    public function testOneChangeLeavesOtherCellsAlone(): void
    {
        $before = $this->matrix->effective();

        $this->matrix->set('student', 'announcements.manage', true, $this->admin);

        $after = $this->matrix->effective();
        unset($before['student'], $after['student']);

        $this->assertSame($before, $after);
    }

    /*
    |--------------------------------------------------------------------------
    | The rules
    |--------------------------------------------------------------------------
    */

    public function testTheSystemAdministratorRoleCannotBeChangedEitherWay(): void
    {
        foreach ([['roles.manage', false], ['courses.manage', true]] as [$permission, $granted]) {
            try {
                $this->matrix->set('admin', $permission, $granted, $this->admin);
                $this->fail("admin / {$permission} must be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('locked', $e->getMessage());
            }
        }

        $this->assertSame(0, $this->rowCount());
        $this->assertSame([], $this->audit());
        $this->assertSame(AuthGroups::DEFAULT_MATRIX['admin'], $this->matrix->effective()['admin']);
    }

    public function testARoleCannotLoseItsLastPermission(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one permission');

        try {
            $this->matrix->set('student', 'courses.view', false, $this->admin);
        } finally {
            $this->assertSame(0, $this->rowCount());
            $this->assertSame(['courses.view'], $this->matrix->effective()['student']);
        }
    }

    public function testARoleCanSwapItsOnlyPermissionForAnotherInTheRightOrder(): void
    {
        $this->matrix->set('student', 'announcements.manage', true, $this->admin);

        $this->assertTrue($this->matrix->set('student', 'courses.view', false, $this->admin), 'with a second permission the first may go');
        $this->assertSame(['announcements.manage'], $this->matrix->effective()['student']);
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown role');

        $this->matrix->set('wizard', 'courses.view', true, $this->admin);
    }

    public function testAnUnknownPermissionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown permission');

        $this->matrix->set('student', 'rockets.launch', true, $this->admin);
    }

    public function testARefusedChangeStoresAndLogsNothing(): void
    {
        foreach ([['wizard', 'courses.view'], ['student', 'rockets.launch'], ['admin', 'roles.manage']] as [$role, $permission]) {
            try {
                $this->matrix->set($role, $permission, false, $this->admin);
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, $this->rowCount());
        $this->assertSame([], $this->audit());
    }

    /*
    |--------------------------------------------------------------------------
    | The audit trail
    |--------------------------------------------------------------------------
    */

    public function testEveryChangeIsAuditedWithWhoWhatAndBeforeAndAfter(): void
    {
        $this->matrix->set('instructor', 'grading.manage', false, $this->admin);

        $entries = $this->audit();

        $this->assertCount(1, $entries);
        $this->assertSame((string) $this->admin->id, (string) $entries[0]['actor_user_id']);
        $this->assertSame('admin', $entries[0]['acting_role']);
        $this->assertSame('role', $entries[0]['target_type']);
        $this->assertSame('instructor', $entries[0]['target_id']);
        $this->assertSame(['grading.manage' => true], json_decode($entries[0]['old_values'], true));
        $this->assertSame(['grading.manage' => false], json_decode($entries[0]['new_values'], true));
    }

    public function testTheSignedInUserIsTheActorWhenNoneIsGiven(): void
    {
        auth()->login($this->admin);

        $this->matrix->set('student', 'announcements.manage', true);

        auth()->logout();

        $this->assertSame((string) $this->admin->id, (string) $this->audit()[0]['actor_user_id']);
    }

    public function testAChangeIsUndoneWhenItCannotBeAudited(): void
    {
        $this->matrix->set('instructor', 'courses.manage', false, $this->admin);
        $rowsBefore = $this->rowCount();

        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                throw new RuntimeException('the audit log is unavailable');
            }
        });

        try {
            $this->matrix->set('student', 'announcements.manage', true, $this->admin);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('the audit log is unavailable', $e->getMessage());
        }

        $this->assertFalse($this->matrix->has('student', 'announcements.manage'), 'the grant was taken back');
        $this->assertSame($rowsBefore, $this->rowCount());
    }

    public function testAnAuditFailureRestoresTheEarlierValueNotJustTheDefault(): void
    {
        $this->matrix->set('student', 'announcements.manage', true, $this->admin);

        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                throw new RuntimeException('down');
            }
        });

        try {
            $this->matrix->set('student', 'announcements.manage', false, $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertTrue($this->matrix->has('student', 'announcements.manage'), 'still granted, as before the failed attempt');
        $this->assertSame(1, (new RolePermissionModel())->where('role', 'student')->countAllResults());
    }
}
