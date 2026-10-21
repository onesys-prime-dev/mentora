<?php

declare(strict_types=1);

use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\DatabaseTestCase;

/**
 * The table behind institution invitations: what it holds, what it refuses
 * (a reused link secret, an invitation to no institution), that it goes with
 * its institution, and that the migration can be undone and redone.
 *
 * Migrating commits on its own, so this class cannot use the rolled-back
 * transaction the others do: it removes the institutions it made instead
 * (their invitations go with them).
 *
 * @internal
 */
final class CreateInstitutionInvitationsMigrationTest extends DatabaseTestCase
{
    private const FILE = APPPATH . 'Database/Migrations/2026-10-15-000001_CreateInstitutionInvitationsTable.php';

    /** @var list<int> */
    private array $institutionIds = [];

    private function migration(): App\Database\Migrations\CreateInstitutionInvitationsTable
    {
        require_once self::FILE;

        return new App\Database\Migrations\CreateInstitutionInvitationsTable();
    }

    protected function tearDown(): void
    {
        if ($this->institutionIds !== [] && db_connect()->tableExists('institution_invitations')) {
            db_connect()->table('institutions')->whereIn('id', $this->institutionIds)->delete();
        }

        $this->restoreLaterColumns();

        parent::tearDown();
    }

    /**
     * Redoing this migration builds the table as it first was. Columns that
     * later migrations added are put back, so the tests that run after this
     * one find the table as the application expects it.
     */
    private function restoreLaterColumns(): void
    {
        db_connect()->resetDataCache();

        if (db_connect()->tableExists('institution_invitations') && ! db_connect()->fieldExists('student_ids', 'institution_invitations')) {
            require_once APPPATH . 'Database/Migrations/2026-11-09-000001_AddStudentsToInstitutionInvitations.php';

            (new App\Database\Migrations\AddStudentsToInstitutionInvitations())->up();
        }
    }

    private function institution(string $code): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => 'active']);
        $this->institutionIds[] = (int) db_connect()->insertID();

        return end($this->institutionIds);
    }

    /** @return array<string, mixed> */
    private function invitation(int $institutionId, string $hash): array
    {
        return [
            'institution_id' => $institutionId, 'role' => 'institutional_admin', 'email' => 'a@example.com', 'first_name' => 'Ana', 'last_name' => 'Cruz',
            'token_hash' => $hash, 'expires_at' => '2026-12-31 00:00:00',
        ];
    }

    public function testTheTableHoldsEverythingTheServiceWrites(): void
    {
        db_connect()->resetDataCache();

        foreach (['id', 'institution_id', 'role', 'email', 'first_name', 'last_name', 'token_hash', 'expires_at', 'sent_at', 'accepted_at', 'accepted_user_id', 'revoked_at', 'invited_by_user_id', 'invited_by_label', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(db_connect()->fieldExists($column, 'institution_invitations'), "column {$column}");
        }
    }

    public function testALinkSecretCanOnlyBeStoredOnce(): void
    {
        $institution = $this->institution('INV1');
        $hash        = hash('sha256', 'one');

        db_connect()->table('institution_invitations')->insert($this->invitation($institution, $hash));

        $this->expectException(DatabaseException::class);

        db_connect()->table('institution_invitations')->insert($this->invitation($institution, $hash));
    }

    public function testAnInvitationNeedsAnInstitution(): void
    {
        $this->expectException(DatabaseException::class);

        db_connect()->table('institution_invitations')->insert($this->invitation(999999, hash('sha256', 'orphan')));
    }

    public function testInvitationsGoWhenTheirInstitutionIsRemoved(): void
    {
        $keep   = $this->institution('INV2');
        $remove = $this->institution('INV3');

        db_connect()->table('institution_invitations')->insert($this->invitation($keep, hash('sha256', 'keep')));
        db_connect()->table('institution_invitations')->insert($this->invitation($remove, hash('sha256', 'remove')));

        db_connect()->table('institutions')->where('id', $remove)->delete();

        $this->assertSame(1, db_connect()->table('institution_invitations')->whereIn('institution_id', [$keep, $remove])->countAllResults());
        $this->assertSame($keep, (int) db_connect()->table('institution_invitations')->whereIn('institution_id', [$keep, $remove])->get()->getRowArray()['institution_id']);
    }

    public function testTheMigrationCanBeUndoneAndRedoneWithoutTouchingInstitutions(): void
    {
        $institution = $this->institution('INV4');

        $migration = $this->migration();

        $migration->down();
        db_connect()->resetDataCache();
        $this->assertFalse(db_connect()->tableExists('institution_invitations'), 'down removes the table');
        $this->assertSame(1, db_connect()->table('institutions')->where('id', $institution)->countAllResults(), 'and leaves institutions alone');

        $migration->up();
        db_connect()->resetDataCache();
        $this->assertTrue(db_connect()->tableExists('institution_invitations'), 'up brings it back');
        $this->assertSame(1, db_connect()->table('institutions')->where('id', $institution)->countAllResults());

        db_connect()->table('institution_invitations')->insert($this->invitation($institution, hash('sha256', 'after')));
        $this->assertSame(1, db_connect()->table('institution_invitations')->where('institution_id', $institution)->countAllResults(), 'and it works');
    }
}
