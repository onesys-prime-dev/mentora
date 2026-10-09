<?php

declare(strict_types=1);

use App\Models\Audit\AuditLogModel;
use App\Services\Audit\AuditLogger;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Writing audit entries (what each field holds) and the promise that an
 * entry, once written, can never be changed or removed through the model.
 *
 * @internal
 */
final class AuditLogTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private function logger(): AuditLogger
    {
        return new AuditLogger();
    }

    /** @return array<string, mixed> */
    private function entry(int $id): array
    {
        return (new AuditLogModel())->find($id);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['impersonator_id']);

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | What gets written
    |--------------------------------------------------------------------------
    */

    public function testRecordsTheActorTheirRoleAndTheTime(): void
    {
        $user = CourseFactory::user('instructor');

        $entry = $this->entry($this->logger()->log('course.updated', $user, ['target_type' => 'course', 'target_id' => 42]));

        $this->assertSame('course.updated', $entry['action']);
        $this->assertSame((string) $user->id, (string) $entry['actor_user_id']);
        $this->assertSame('instructor', $entry['acting_role']);
        $this->assertSame('course', $entry['target_type']);
        $this->assertSame('42', $entry['target_id']);
        $this->assertEqualsWithDelta(time(), strtotime($entry['created_at']), 5);
    }

    public function testAGivenActingRoleWinsOverTheFirstGroup(): void
    {
        $user = CourseFactory::user('instructor');

        $entry = $this->entry($this->logger()->log('course.updated', $user, ['acting_role' => 'chairperson']));

        $this->assertSame('chairperson', $entry['acting_role']);
    }

    public function testTheActorsInstitutionIsFilledIn(): void
    {
        $user = CourseFactory::user('instructor');
        $db   = db_connect();
        $db->table('institutions')->insert(['name' => 'Acme', 'code' => 'ACME']);
        $institutionId = (int) $db->insertID();
        $db->table('institution_users')->insert(['institution_id' => $institutionId, 'user_id' => $user->id]);

        $entry = $this->entry($this->logger()->log('x.y', $user));

        $this->assertSame((string) $institutionId, (string) $entry['institution_id']);
    }

    public function testAGivenInstitutionWinsOverTheActorsOwn(): void
    {
        $admin = CourseFactory::user('admin');

        $entry = $this->entry($this->logger()->log('institution.suspended', $admin, ['institution_id' => 9]));

        $this->assertSame('9', (string) $entry['institution_id']);
        $this->assertSame('admin', $entry['acting_role']);
    }

    public function testAnEntryWithNoActorIsAllowed(): void
    {
        $entry = $this->entry($this->logger()->log('backup.failed', null, ['actor_label' => 'system']));

        $this->assertNull($entry['actor_user_id']);
        $this->assertNull($entry['acting_role']);
        $this->assertSame('system', $entry['actor_label']);
    }

    public function testOldAndNewValuesAreStoredAsJson(): void
    {
        $entry = $this->entry($this->logger()->log('settings.changed', CourseFactory::user('admin'), [
            'old' => ['impersonationMinutes' => 30],
            'new' => ['impersonationMinutes' => 45],
        ]));

        $this->assertSame(['impersonationMinutes' => 30], json_decode($entry['old_values'], true));
        $this->assertSame(['impersonationMinutes' => 45], json_decode($entry['new_values'], true));
    }

    public function testSecretsInTheValuesAreNeverStored(): void
    {
        $id = $this->logger()->log('integration.updated', CourseFactory::user('admin'), [
            'old' => ['host' => 'a.example', 'password' => 'old-secret'],
            'new' => ['host' => 'b.example', 'password' => 'new-secret'],
        ]);

        $raw = db_connect()->table('audit_logs')->where('id', $id)->get()->getRowArray();

        $this->assertStringNotContainsString('old-secret', json_encode($raw));
        $this->assertStringNotContainsString('new-secret', json_encode($raw));
        $this->assertSame('b.example', json_decode($raw['new_values'], true)['host']);
    }

    public function testNoValuesMeansNothingIsStored(): void
    {
        $entry = $this->entry($this->logger()->log('auth.login'));

        $this->assertNull($entry['old_values']);
        $this->assertNull($entry['new_values']);
    }

    public function testTheImpersonatingAdministratorIsRecordedWhenThereIsOne(): void
    {
        $admin  = CourseFactory::user('admin');
        $target = CourseFactory::user('student');

        $_SESSION['impersonator_id'] = $admin->id;
        $entry                       = $this->entry($this->logger()->log('course.viewed', $target));

        $this->assertSame((string) $admin->id, (string) $entry['impersonator_user_id']);
        $this->assertSame((string) $target->id, (string) $entry['actor_user_id']);
    }

    public function testNoImpersonatorIsRecordedNormally(): void
    {
        $entry = $this->entry($this->logger()->log('course.viewed', CourseFactory::user('student')));

        $this->assertNull($entry['impersonator_user_id']);
    }

    public function testEntriesKeepTheirLabelEvenIfTheUserIsDeleted(): void
    {
        $user = CourseFactory::user('student');
        $id   = $this->logger()->log('auth.login', $user, ['actor_label' => 'gone@example.com']);

        db_connect()->table('users')->where('id', $user->id)->delete();

        $entry = $this->entry($id);

        $this->assertSame('gone@example.com', $entry['actor_label']);
        $this->assertSame((string) $user->id, (string) $entry['actor_user_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Append-only
    |--------------------------------------------------------------------------
    */

    private function existingEntryId(): int
    {
        return $this->logger()->log('auth.login', CourseFactory::user('student'));
    }

    public function testAnEntryCannotBeUpdated(): void
    {
        $id = $this->existingEntryId();

        $this->expectException(LogicException::class);

        (new AuditLogModel())->update($id, ['action' => 'tampered']);
    }

    public function testAnEntryCannotBeSavedOver(): void
    {
        $id = $this->existingEntryId();

        $this->expectException(LogicException::class);

        (new AuditLogModel())->save(['id' => $id, 'action' => 'tampered']);
    }

    public function testEntriesCannotBeUpdatedInBatches(): void
    {
        $id = $this->existingEntryId();

        $this->expectException(LogicException::class);

        (new AuditLogModel())->updateBatch([['id' => $id, 'action' => 'tampered']], 'id');
    }

    public function testAnEntryCannotBeDeleted(): void
    {
        $id = $this->existingEntryId();

        $this->expectException(LogicException::class);

        (new AuditLogModel())->delete($id);
    }

    public function testEntriesCannotBePurged(): void
    {
        $this->existingEntryId();

        $this->expectException(LogicException::class);

        (new AuditLogModel())->purgeDeleted();
    }

    public function testAnEntryCannotBeReplaced(): void
    {
        $id = $this->existingEntryId();

        $this->expectException(LogicException::class);

        (new AuditLogModel())->replace(['id' => $id, 'action' => 'tampered', 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function testRefusedChangesLeaveTheEntryAsItWas(): void
    {
        $id = $this->existingEntryId();

        foreach ([
            static fn () => (new AuditLogModel())->update($id, ['action' => 'tampered']),
            static fn () => (new AuditLogModel())->delete($id),
        ] as $attempt) {
            try {
                $attempt();
            } catch (LogicException) {
                // expected
            }
        }

        $this->assertSame('auth.login', (new AuditLogModel())->find($id)['action']);
    }

    public function testEntriesCanStillBeAddedAndRead(): void
    {
        $first  = $this->logger()->log('a.one');
        $second = $this->logger()->log('a.two');

        $this->assertGreaterThan($first, $second);
        $this->assertCount(2, (new AuditLogModel())->whereIn('id', [$first, $second])->findAll());
    }
}
