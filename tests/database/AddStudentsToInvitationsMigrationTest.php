<?php

declare(strict_types=1);

use App\Models\Academic\InstitutionInvitationModel;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * The column that lets an invitation carry the students a parent will be
 * linked to when they accept. It is additive, so existing invitations read
 * "none"; the way back drops only that column.
 *
 * DDL commits on its own, so this class cannot use the rolled-back
 * transaction the others do: it removes the rows it made and leaves the
 * schema as it found it.
 *
 * @internal
 */
final class AddStudentsToInvitationsMigrationTest extends DatabaseTestCase
{
    private const FILE = APPPATH . 'Database/Migrations/2026-11-09-000001_AddStudentsToInstitutionInvitations.php';

    /** @var list<int> */
    private array $ids = [];

    private function migration(): object
    {
        require_once self::FILE;

        return new App\Database\Migrations\AddStudentsToInstitutionInvitations();
    }

    private function hasColumn(): bool
    {
        db_connect()->resetDataCache();

        return db_connect()->fieldExists('student_ids', 'institution_invitations');
    }

    protected function tearDown(): void
    {
        if ($this->ids !== []) {
            db_connect()->table('institution_invitations')->whereIn('id', $this->ids)->delete();
        }

        if (! $this->hasColumn()) {
            $this->migration()->up();
        }

        parent::tearDown();
    }

    private function invitation(?string $studentIds = null): int
    {
        $institution = PeopleFactory::institution('AS' . random_int(100, 999));
        $this->ids[] = $id = (int) (new InstitutionInvitationModel())->insert([
            'institution_id' => $institution, 'role' => 'parent', 'email' => 'p' . random_int(1000, 9999) . '@example.test',
            'first_name' => 'Pam', 'last_name' => 'Parent', 'token_hash' => bin2hex(random_bytes(32)),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        if ($studentIds !== null) {
            db_connect()->table('institution_invitations')->where('id', $id)->update(['student_ids' => $studentIds]);
        }

        return $id;
    }

    public function testTheColumnExistsAndIsOptional(): void
    {
        $this->assertTrue($this->hasColumn());

        $row = db_connect()->table('institution_invitations')->where('id', $this->invitation())->get()->getRowArray();

        $this->assertNull($row['student_ids'], 'an invitation without students reads none');
    }

    public function testItHoldsAJsonListAndTheModelMayWriteIt(): void
    {
        $id = $this->invitation();

        (new InstitutionInvitationModel())->update($id, ['student_ids' => json_encode([4, 9])]);

        $this->assertSame([4, 9], json_decode((string) db_connect()->table('institution_invitations')->where('id', $id)->get()->getRowArray()['student_ids'], true));
    }

    public function testTheWayBackDropsOnlyThatColumn(): void
    {
        $id = $this->invitation('[1]');

        $this->migration()->down();

        $this->assertFalse($this->hasColumn());
        $this->assertSame('parent', db_connect()->table('institution_invitations')->where('id', $id)->get()->getRowArray()['role'], 'the invitation itself is untouched');

        $this->migration()->up();

        $this->assertTrue($this->hasColumn());
        $this->assertNull(db_connect()->table('institution_invitations')->where('id', $id)->get()->getRowArray()['student_ids'], 'the list is gone with the column');
    }
}
