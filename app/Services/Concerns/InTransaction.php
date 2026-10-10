<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use Throwable;

/**
 * For services that must write several rows as one: all of them happen or none do.
 *
 * Transactions nest: when this runs inside another transaction (onboarding
 * wraps the subscription it starts), only the outermost one really commits or
 * rolls back, and an exception thrown here reaches it, so the whole lot is
 * undone together.
 */
trait InTransaction
{
    /**
     * Every write in $work must check its own result and throw when it failed;
     * that is what triggers the rollback.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    protected function inTransaction(callable $work)
    {
        $db = db_connect();

        $db->transBegin();

        try {
            $result = $work();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $db->transCommit();

        return $result;
    }
}
