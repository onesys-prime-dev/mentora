<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Models\Assignments\DeanAssignmentModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * BC-4: a block only binds for someone who holds a student or chairperson/dean/parent role. Blocks held by
 * people whose roles are all instructor / institutional_admin are moved to user_block_history (reason
 * 'reset_non_binding'), one audit entry each; down() moves them back.
 */
class ResetNonBindingBlocks extends Migration
{
    private const REASON = 'reset_non_binding';

    public function up()
    {
        /** @var BaseConnection $db */
        $db   = $this->db;
        $rows = [];

        foreach ($db->table('user_blocks')->get()->getResultArray() as $row) {
            if ($this->nonBinding((int) $row['blocker_id'])) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // A failed query must abort the move (no silent rollback followed by "reset" audits).
        $db->transException(true);
        $db->transStart();

        try {
            $copied = $deleted = 0;

            foreach ($rows as $row) {
                $this->moveRow($row, $now);
                $copied++;
                $deleted += $db->affectedRows();
            }

            if ($copied !== count($rows) || $deleted !== count($rows)) {
                throw new RuntimeException('Block reset aborted: copied or deleted rows do not match the moved rows.');
            }
        } catch (Throwable $e) {
            $db->transRollback();
            $db->transException(false);

            throw $e;
        }

        $db->transComplete();
        $db->transException(false);

        foreach ($rows as $row) {
            service('auditLogger')->log('messages.block_reset', null, [
                'target_type' => 'user', 'target_id' => $row['blocked_id'], 'institution_id' => $row['institution_id'],
                'new'         => ['blocker_id' => (int) $row['blocker_id']],
            ]);
        }
    }

    /** Copy one block to the history and delete it; leaves the delete's affected-row count for the caller. */
    protected function moveRow(array $row, string $now): void
    {
        $db = $this->db;

        $db->table('user_block_history')->insert([
            'institution_id' => $row['institution_id'], 'blocker_id' => $row['blocker_id'], 'blocked_id' => $row['blocked_id'],
            'blocked_at' => $row['created_at'], 'moved_at' => $now, 'reason' => self::REASON,
        ]);
        $db->table('user_blocks')->where(['blocker_id' => $row['blocker_id'], 'blocked_id' => $row['blocked_id']])->delete();
    }

    /**
     * Moves the rows back one by one. A history row is deleted only after its block was restored; when the
     * person has blocked the same user again since, the newer live block wins and the history row is kept
     * (nothing is erased). The messages.block_reset audit entries are not removed.
     */
    public function down()
    {
        /** @var BaseConnection $db */
        $db   = $this->db;
        $rows = $db->table('user_block_history')->where('reason', self::REASON)->get()->getResultArray();

        $db->transException(true);
        $db->transStart();

        try {
            foreach ($rows as $row) {
                $db->table('user_blocks')->ignore(true)->insert([
                    'institution_id' => $row['institution_id'], 'blocker_id' => $row['blocker_id'], 'blocked_id' => $row['blocked_id'],
                    'created_at' => $row['blocked_at'],
                ]);

                if ($db->affectedRows() > 0) {
                    $db->table('user_block_history')->where('id', $row['id'])->delete();
                }
            }
        } catch (Throwable $e) {
            $db->transRollback();
            $db->transException(false);

            throw $e;
        }

        $db->transComplete();
        $db->transException(false);
    }

    /** Held roles (same rule as MessagingContextResolver::roles()) are non-empty and all instructor / institutional_admin. */
    private function nonBinding(int $userId): bool
    {
        $groups = array_column($this->db->table('auth_groups_users')->select('group')->where('user_id', $userId)->get()->getResultArray(), 'group');
        // Beside institution roles the system `admin` is ignored (ruling R10).
        $held   = array_values(array_diff($groups, ['admin']));
        $groups = $held === [] ? $groups : $held;

        if (in_array('dean', $groups, true)) {
            $others = array_values(array_diff($groups, ['dean']));

            if ($others !== [] && (new DeanAssignmentModel())->collegeOfDean($userId) === null) {
                $groups = $others;
            }
        }

        return $groups !== [] && array_diff($groups, ['instructor', 'institutional_admin']) === [];
    }
}
