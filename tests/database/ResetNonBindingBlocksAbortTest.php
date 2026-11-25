<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * A failing query during the reset aborts it: an exception, nothing moved, no "reset" audit entries.
 * Not isolated by a wrapping transaction (the rollback has to be real), so the tables are emptied per test.
 *
 * @internal
 */
final class ResetNonBindingBlocksAbortTest extends DatabaseTestCase
{
    public function testAFailedQueryAbortsWithoutAuditsOrMovedRows(): void
    {
        Services::resetSingle('auditLogger');
        $institution = PeopleFactory::institution('RA');
        $target      = (int) PeopleFactory::person('student', 'Tom', 'Target', null, $institution)->id;

        foreach (['Bea', 'Cal'] as $name) {
            $user = PeopleFactory::person('instructor', $name, 'Blocker', null, $institution);
            db_connect()->table('user_blocks')->insert(['institution_id' => $institution, 'blocker_id' => $user->id, 'blocked_id' => $target, 'created_at' => '2026-01-02 03:04:05']);
        }

        db_connect()->table('audit_logs')->emptyTable();

        require_once APPPATH . 'Database/Migrations/2026-12-03-000002_ResetNonBindingBlocks.php';

        // The second block's move fails on a real SQL error, after the first one was already moved.
        $migration = new class extends App\Database\Migrations\ResetNonBindingBlocks {
            private int $calls = 0;

            protected function moveRow(array $row, string $now): void
            {
                if (++$this->calls === 2) {
                    $this->db->table('user_block_history')->insert(['no_such_column' => 1]);
                }

                parent::moveRow($row, $now);
            }
        };

        try {
            $migration->up();
            $this->fail('the reset should have thrown');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $this->assertSame(2, db_connect()->table('user_blocks')->countAllResults());
        $this->assertSame(0, db_connect()->table('user_block_history')->countAllResults());
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'messages.block_reset')->countAllResults());
    }
}
