<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * BC-4: blocks held by someone whose roles are all instructor / institutional_admin stop binding, so the
 * reset migration moves them to user_block_history (reversibly); every other block stays.
 *
 * @internal
 */
final class ResetNonBindingBlocksTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $target;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->institution = PeopleFactory::institution('RB');
        $this->target      = (int) PeopleFactory::person('student', 'Tom', 'Target', null, $this->institution)->id;
        db_connect()->table('audit_logs')->emptyTable();
    }

    private function migration(): object
    {
        require_once APPPATH . 'Database/Migrations/2026-12-03-000002_ResetNonBindingBlocks.php';

        return new App\Database\Migrations\ResetNonBindingBlocks();
    }

    /** A blocker holding $roles (first via the factory, the rest added) with one block on the target. */
    private function blocker(array $roles): int
    {
        $user = PeopleFactory::person(array_shift($roles), 'Bea', 'Blocker', null, $this->institution);

        foreach ($roles as $role) {
            db_connect()->table('auth_groups_users')->insert(['user_id' => $user->id, 'group' => $role, 'created_at' => date('Y-m-d H:i:s')]);
        }

        db_connect()->table('user_blocks')->insert([
            'institution_id' => $this->institution, 'blocker_id' => $user->id, 'blocked_id' => $this->target, 'created_at' => '2026-01-02 03:04:05',
        ]);

        return (int) $user->id;
    }

    private function blocks(): int
    {
        return db_connect()->table('user_blocks')->countAllResults();
    }

    private function history(): int
    {
        return db_connect()->table('user_block_history')->countAllResults();
    }

    public function testMovesBlocksOfInstructorOnlyBlocker(): void
    {
        $id = $this->blocker(['instructor']);
        $this->blocker(['institutional_admin']);

        $this->migration()->up();

        $this->assertSame(0, $this->blocks());
        $row = db_connect()->table('user_block_history')->where('blocker_id', $id)->get()->getRowArray();
        $this->assertSame('reset_non_binding', $row['reason']);
        $this->assertSame('2026-01-02 03:04:05', $row['blocked_at']);
        $this->assertSame($this->target, (int) $row['blocked_id']);
    }

    public function testKeepsBlocksOfStudentInstructor(): void
    {
        $this->blocker(['student', 'instructor']);
        $this->migration()->up();

        $this->assertSame(1, $this->blocks());
        $this->assertSame(0, $this->history());
    }

    public function testKeepsChairpersonBlocks(): void
    {
        $this->blocker(['chairperson']);
        $this->blocker(['instructor', 'chairperson']);
        $this->migration()->up();

        $this->assertSame(2, $this->blocks());
    }

    public function testMovesBlocksOfDeanWithoutAssignmentWhoTeaches(): void
    {
        // A dean group with no dean assignment is not a usable role; the person counts as instructor only.
        $this->blocker(['dean', 'instructor']);
        $this->migration()->up();

        $this->assertSame(0, $this->blocks());
        $this->assertSame(1, $this->history());
    }

    public function testKeepsStudentBlocks(): void
    {
        $this->blocker(['student']);
        $this->migration()->up();

        $this->assertSame(1, $this->blocks());
    }

    public function testCountsAddUp(): void
    {
        $this->blocker(['instructor']);
        $this->blocker(['student']);
        $this->blocker(['institutional_admin']);
        $before = $this->blocks();

        $this->migration()->up();

        $this->assertSame($before, $this->blocks() + $this->history());
        $this->assertSame(2, $this->history());
    }

    public function testDownRestoresRows(): void
    {
        $id = $this->blocker(['instructor']);
        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(0, $this->history());
        $row = db_connect()->table('user_blocks')->where('blocker_id', $id)->get()->getRowArray();
        $this->assertSame('2026-01-02 03:04:05', $row['created_at']);
    }

    public function testIgnoresTheSystemAdminRoleBesideInstructor(): void
    {
        $this->blocker(['instructor', 'admin']);
        $this->blocker(['student', 'admin']);
        $this->blocker(['admin']);
        $this->migration()->up();

        $this->assertSame(2, $this->blocks());
        $this->assertSame(1, $this->history());
    }

    public function testDownKeepsAHistoryRowWhoseBlockWasMadeAgain(): void
    {
        $id = $this->blocker(['instructor']);
        $this->migration()->up();
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $id, 'blocked_id' => $this->target, 'created_at' => '2026-05-05 00:00:00']);

        $this->migration()->down();

        $this->assertSame(1, $this->history(), 'the history row is not lost');
        $this->assertSame('2026-05-05 00:00:00', db_connect()->table('user_blocks')->get()->getRowArray()['created_at']);
    }

    public function testOneAuditEntryPerMovedBlock(): void
    {
        $id = $this->blocker(['instructor']);
        $this->blocker(['instructor']);
        $this->blocker(['student']);

        $this->migration()->up();

        $rows = db_connect()->table('audit_logs')->where('action', 'messages.block_reset')->orderBy('id')->get()->getResultArray();
        $this->assertCount(2, $rows);
        $this->assertSame((string) $this->target, $rows[0]['target_id']);
        $this->assertSame('user', $rows[0]['target_type']);
        $this->assertSame($this->institution, (int) $rows[0]['institution_id']);
        $this->assertSame($id, (int) json_decode($rows[0]['new_values'], true)['blocker_id']);
    }
}
