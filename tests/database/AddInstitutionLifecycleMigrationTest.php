<?php

declare(strict_types=1);

use Tests\Support\DatabaseTestCase;

/**
 * The migration that gives institutions their lifecycle statuses and a soft
 * delete. It rewrites a table that already holds customers on deploy, so the
 * test puts the schema back to how it was, adds institutions the old way,
 * runs the migration and checks where they ended up (and the way back), then
 * leaves the schema as it found it.
 *
 * DDL commits on its own, so this class cannot use the rolled-back
 * transaction the others do: it removes the institutions it made instead.
 *
 * @internal
 */
final class AddInstitutionLifecycleMigrationTest extends DatabaseTestCase
{
    private const FILE = APPPATH . 'Database/Migrations/2026-10-11-000001_AddInstitutionLifecycle.php';

    /** @var list<int> */
    private array $institutionIds = [];

    private function migration(): App\Database\Migrations\AddInstitutionLifecycle
    {
        require_once self::FILE;

        return new App\Database\Migrations\AddInstitutionLifecycle();
    }

    protected function tearDown(): void
    {
        if ($this->institutionIds !== []) {
            db_connect()->table('institutions')->whereIn('id', $this->institutionIds)->delete();
        }

        parent::tearDown();
    }

    private function hasDeletedAt(): bool
    {
        db_connect()->resetDataCache();

        return db_connect()->fieldExists('deleted_at', 'institutions');
    }

    /** @return list<string> the values the status column allows right now */
    private function allowedStatuses(): array
    {
        $type = db_connect()->query("SHOW COLUMNS FROM institutions LIKE 'status'")->getRowArray()['Type'];

        preg_match_all("/'([^']+)'/", $type, $matches);

        return $matches[1];
    }

    private function institution(string $code, string $status, ?string $deletedAt = null): int
    {
        $db   = db_connect();
        $row  = ['name' => "Institution {$code}", 'code' => $code, 'status' => $status];

        if ($deletedAt !== null) {
            $row['deleted_at'] = $deletedAt;
        }

        $db->table('institutions')->insert($row);
        $this->institutionIds[] = (int) $db->insertID();

        return end($this->institutionIds);
    }

    private function statusOf(int $id): string
    {
        return db_connect()->table('institutions')->where('id', $id)->get()->getRowArray()['status'];
    }

    /**
     * Runs $assertions with the schema as it was before the migration, then migrates and leaves it migrated.
     *
     * @param callable(): array<string, int>      $seed       adds institutions the old way and returns their ids by name
     * @param callable(array<string, int>): void  $assertions checks the migrated schema, given those ids
     */
    private function fromTheOldSchema(callable $seed, callable $assertions): void
    {
        $migration = $this->migration();

        $migration->down();

        try {
            $this->assertFalse($this->hasDeletedAt(), 'the old schema is back');
            $this->assertSame(['active', 'inactive', 'archived'], $this->allowedStatuses());

            $ids = $seed();

            $migration->up();

            $assertions($ids);
        } finally {
            if (! $this->hasDeletedAt()) {
                $migration->up();
            }
        }
    }

    public function testTheSchemaAfterTheMigrationHasTheNewStatusesAndDeletedAt(): void
    {
        $this->assertSame(['pending', 'active', 'read_only', 'past'], $this->allowedStatuses());
        $this->assertTrue($this->hasDeletedAt());
    }

    public function testInactiveAndArchivedInstitutionsBecomePast(): void
    {
        $this->fromTheOldSchema(
            fn (): array => [
                'active'   => $this->institution('LC1', 'active'),
                'inactive' => $this->institution('LC2', 'inactive'),
                'archived' => $this->institution('LC3', 'archived'),
            ],
            function (array $ids): void {
                $this->assertSame('active', $this->statusOf($ids['active']), 'an active institution is untouched');
                $this->assertSame('past', $this->statusOf($ids['inactive']));
                $this->assertSame('past', $this->statusOf($ids['archived']));
            }
        );
    }

    public function testNothingIsMarkedDeletedByTheMigration(): void
    {
        $this->fromTheOldSchema(
            fn (): array => ['archived' => $this->institution('LC4', 'archived')],
            function (array $ids): void {
                $this->assertNull(db_connect()->table('institutions')->where('id', $ids['archived'])->get()->getRowArray()['deleted_at']);
            }
        );
    }

    public function testExistingDataIsKeptAndTheCodeStaysUnique(): void
    {
        $this->fromTheOldSchema(
            fn (): array => ['one' => $this->institution('LC5', 'active')],
            function (array $ids): void {
                $row = db_connect()->table('institutions')->where('id', $ids['one'])->get()->getRowArray();

                $this->assertSame('Institution LC5', $row['name']);
                $this->assertSame('LC5', $row['code']);

                $this->expectException(CodeIgniter\Database\Exceptions\DatabaseException::class);
                $this->expectExceptionMessage('Duplicate entry');

                db_connect()->table('institutions')->insert(['name' => 'Another', 'code' => 'LC5']);
            }
        );
    }

    public function testGoingBackMapsTheNewStatusesToTheNearestOldOnes(): void
    {
        $migration = $this->migration();
        $ids       = [
            'pending'  => $this->institution('LC6', 'pending'),
            'active'   => $this->institution('LC7', 'active'),
            'readonly' => $this->institution('LC8', 'read_only'),
            'past'     => $this->institution('LC9', 'past'),
            'deleted'  => $this->institution('LC10', 'active', '2026-10-01 10:00:00'),
        ];

        try {
            $migration->down();

            $this->assertSame('inactive', $this->statusOf($ids['pending']));
            $this->assertSame('active', $this->statusOf($ids['active']));
            $this->assertSame('active', $this->statusOf($ids['readonly']));
            $this->assertSame('inactive', $this->statusOf($ids['past']));
            $this->assertSame('archived', $this->statusOf($ids['deleted']), 'a deleted institution stays out of use');
            $this->assertFalse($this->hasDeletedAt());
        } finally {
            // Leave the schema migrated, whatever the assertions above did.
            if (! $this->hasDeletedAt()) {
                $migration->up();
            }
        }

        $this->assertTrue($this->hasDeletedAt(), 'the schema is migrated again');
    }

    public function testTheMigrationCanRunAgainAfterGoingBack(): void
    {
        $migration = $this->migration();

        $migration->down();
        $migration->up();

        $this->assertSame(['pending', 'active', 'read_only', 'past'], $this->allowedStatuses());
        $this->assertTrue($this->hasDeletedAt());
    }
}
