<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Sis\ConnectionResult;
use App\Services\Sis\SisBatch;
use App\Services\Sis\SisConnector;
use App\Services\Sis\SisException;

/**
 * A SIS that keeps what it is sent. Like a real one it keys deliveries by
 * batch key, so the same key replaces the delivery instead of adding one.
 * `failTimes` makes the next N deliveries fail first.
 *
 * @internal
 */
final class FakeSisConnector implements SisConnector
{
    /** @var array<string, list<array<string, string>>> batch key => rows */
    public array $deliveries = [];

    public int $calls = 0;

    public int $failTimes = 0;

    public function testConnection(): ConnectionResult
    {
        return new ConnectionResult(true, 'fake');
    }

    public function has(string $dataset): bool
    {
        return false;
    }

    public function read(string $dataset): SisBatch
    {
        throw new SisException('The fake SIS has nothing to read.');
    }

    public function publishGrades(string $batchKey, array $rows): void
    {
        $this->calls++;

        if ($this->failTimes > 0) {
            $this->failTimes--;

            throw new SisException('The SIS did not answer.');
        }

        $this->deliveries[$batchKey] = $rows;
    }
}
