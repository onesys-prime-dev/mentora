<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;

/**
 * For models whose rows are a record, not a state: rows can be added and
 * read, never changed or removed. Every route to a change through the model
 * is closed and throws, so a bug or a careless future feature cannot quietly
 * rewrite history. (A database cascade from a parent row, such as purging an
 * institution, is a deliberate exception and does not go through the model.)
 *
 * Use it in a Model and implement appendOnlyName().
 */
trait AppendOnly
{
    /** What the rows are called in the refusal message, e.g. "Audit log entries". */
    abstract protected function appendOnlyName(): string;

    /**
     * @param array<string, mixed>|object|null $row
     */
    public function update($id = null, $row = null): bool
    {
        throw $this->refuse('changed');
    }

    /** @param array<int, array<string, mixed>>|null $set */
    public function updateBatch(?array $set = null, ?string $index = null, int $batchSize = 100, bool $returnSQL = false)
    {
        throw $this->refuse('changed');
    }

    public function delete($id = null, bool $purge = false)
    {
        throw $this->refuse('deleted');
    }

    public function purgeDeleted()
    {
        throw $this->refuse('deleted');
    }

    /** @param array<string, mixed>|object|null $row */
    public function replace($row = null, bool $returnSQL = false)
    {
        throw $this->refuse('changed');
    }

    private function refuse(string $what): LogicException
    {
        return new LogicException("{$this->appendOnlyName()} can never be {$what}.");
    }
}
