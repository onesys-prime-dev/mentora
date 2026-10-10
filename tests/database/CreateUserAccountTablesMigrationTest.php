<?php

declare(strict_types=1);

use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * The tables behind account control and password resets: what they refuse,
 * that they go with their user, and that the migration can be undone and
 * redone.
 *
 * Migrating commits on its own, so this class cannot use the rolled-back
 * transaction the others do: it removes the users it made instead.
 *
 * @internal
 */
final class CreateUserAccountTablesMigrationTest extends DatabaseTestCase
{
    private const FILE = APPPATH . 'Database/Migrations/2026-10-16-000001_CreateUserAccountTables.php';

    /** @var list<int> */
    private array $userIds = [];

    private function migration(): App\Database\Migrations\CreateUserAccountTables
    {
        require_once self::FILE;

        return new App\Database\Migrations\CreateUserAccountTables();
    }

    protected function tearDown(): void
    {
        if ($this->userIds !== []) {
            db_connect()->table('users')->whereIn('id', $this->userIds)->delete();
        }

        parent::tearDown();
    }

    private function user(): int
    {
        $user            = PeopleFactory::person('student');
        $this->userIds[] = (int) $user->id;

        return (int) $user->id;
    }

    /** @return array<string, mixed> */
    private function reset(int $userId, string $hash): array
    {
        return ['user_id' => $userId, 'token_hash' => $hash, 'expires_at' => '2026-12-31 00:00:00'];
    }

    public function testABrokenOrMissingUserCannotHaveARow(): void
    {
        $this->expectException(DatabaseException::class);

        db_connect()->table('user_account_controls')->insert(['user_id' => 999999]);
    }

    public function testAUserHasOnlyOneControlRow(): void
    {
        $id = $this->user();

        db_connect()->table('user_account_controls')->insert(['user_id' => $id]);

        $this->expectException(DatabaseException::class);

        db_connect()->table('user_account_controls')->insert(['user_id' => $id]);
    }

    public function testAResetSecretCanOnlyBeStoredOnce(): void
    {
        $id   = $this->user();
        $hash = hash('sha256', 'once');

        db_connect()->table('password_resets')->insert($this->reset($id, $hash));

        $this->expectException(DatabaseException::class);

        db_connect()->table('password_resets')->insert($this->reset($id, $hash));
    }

    public function testBothTablesGoWhenTheirUserIsRemoved(): void
    {
        $id = $this->user();

        db_connect()->table('user_account_controls')->insert(['user_id' => $id]);
        db_connect()->table('password_resets')->insert($this->reset($id, hash('sha256', 'gone')));

        db_connect()->table('users')->where('id', $id)->delete();

        $this->assertSame(0, db_connect()->table('user_account_controls')->where('user_id', $id)->countAllResults());
        $this->assertSame(0, db_connect()->table('password_resets')->where('user_id', $id)->countAllResults());
    }

    public function testTheMigrationCanBeUndoneAndRedoneWithoutTouchingUsers(): void
    {
        $id        = $this->user();
        $migration = $this->migration();

        $migration->down();
        db_connect()->resetDataCache();

        $this->assertFalse(db_connect()->tableExists('user_account_controls'));
        $this->assertFalse(db_connect()->tableExists('password_resets'));
        $this->assertSame(1, db_connect()->table('users')->where('id', $id)->countAllResults(), 'users are untouched');

        $migration->up();
        db_connect()->resetDataCache();

        $this->assertTrue(db_connect()->tableExists('user_account_controls'));
        $this->assertTrue(db_connect()->tableExists('password_resets'));

        db_connect()->table('user_account_controls')->insert(['user_id' => $id, 'sessions_valid_from' => '2026-10-09 12:00:00']);
        $this->assertSame(1, db_connect()->table('user_account_controls')->where('user_id', $id)->countAllResults());
    }
}
