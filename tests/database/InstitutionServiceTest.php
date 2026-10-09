<?php

declare(strict_types=1);

use App\Models\Academic\InstitutionModel;
use App\Services\Audit\AuditLogger;
use App\Services\Institutions\InstitutionFilters;
use App\Services\Institutions\InstitutionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * What a system administrator can do to an institution: the list, and the
 * five actions with their rules, their audit entries, and the undo when an
 * entry cannot be written.
 *
 * @internal
 */
final class InstitutionServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private InstitutionService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->service = new InstitutionService();
        $this->admin   = CourseFactory::user('admin');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    /** An institution made directly, in any state. */
    private function seeded(string $code, string $status = 'active', ?string $name = null, ?string $createdAt = null): int
    {
        db_connect()->table('institutions')->insert([
            'name'       => $name ?? "Institution {$code}",
            'code'       => $code,
            'status'     => $status,
            'created_at' => $createdAt ?? date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return db_connect()->table('institutions')->where('id', $id)->get()->getRowArray();
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
    }

    /** @param array<string, mixed> $input */
    private function codes(array $input): array
    {
        return array_column($this->service->search(InstitutionFilters::fromInput($input))['rows'], 'code');
    }

    private function breakAuditing(): void
    {
        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                throw new RuntimeException('the audit log is unavailable');
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The list
    |--------------------------------------------------------------------------
    */

    public function testTheListIsAlphabeticalAndLeavesOutDeletedInstitutions(): void
    {
        $this->seeded('B2', 'active', 'Beta University');
        $this->seeded('A1', 'active', 'Alpha University');
        $gone = $this->seeded('C3', 'active', 'Gamma University');
        (new InstitutionModel())->delete($gone);

        $this->assertSame(['A1', 'B2'], $this->codes([]));
    }

    public function testFilterByStatus(): void
    {
        $this->seeded('P1', 'pending');
        $this->seeded('A1', 'active');
        $this->seeded('R1', 'read_only');
        $this->seeded('X1', 'past');

        $this->assertSame(['P1'], $this->codes(['status' => 'pending']));
        $this->assertSame(['A1'], $this->codes(['status' => 'active']));
        $this->assertSame(['R1'], $this->codes(['status' => 'read_only']));
        $this->assertSame(['X1'], $this->codes(['status' => 'past']));
    }

    public function testTheDeletedFilterShowsOnlyDeletedInstitutions(): void
    {
        $this->seeded('KEEP', 'active');
        $gone = $this->seeded('GONE', 'past');
        (new InstitutionModel())->delete($gone);

        $this->assertSame(['GONE'], $this->codes(['status' => 'deleted']));
    }

    public function testSearchMatchesNameOrCode(): void
    {
        $this->seeded('SBU', 'active', 'San Beda University');
        $this->seeded('UPD', 'active', 'University of the Philippines');
        $this->seeded('DLSU', 'active', 'De La Salle');

        $this->assertSame(['SBU'], $this->codes(['q' => 'beda']));
        $this->assertSame(['DLSU'], $this->codes(['q' => 'dls']));
        $this->assertEqualsCanonicalizing(['SBU', 'UPD'], $this->codes(['q' => 'universit']));
    }

    public function testSearchTreatsPercentAndUnderscoreAsPlainCharacters(): void
    {
        $this->seeded('PLAIN', 'active', 'Plain College');
        $this->seeded('PCT', 'active', '100% College');

        $this->assertSame(['PCT'], $this->codes(['q' => '%']));
        $this->assertSame([], $this->codes(['q' => '_']));
    }

    public function testSearchAndStatusCombine(): void
    {
        $this->seeded('A1', 'active', 'Acme North');
        $this->seeded('A2', 'past', 'Acme South');
        $this->seeded('B1', 'past', 'Beta South');

        $this->assertSame(['A2'], $this->codes(['q' => 'acme', 'status' => 'past']));
    }

    public function testSortAndPaging(): void
    {
        $this->seeded('C', 'active', 'Charlie College', '2026-01-03 00:00:00');
        $this->seeded('A', 'active', 'Alpha College', '2026-01-01 00:00:00');
        $this->seeded('B', 'active', 'Bravo College', '2026-01-02 00:00:00');

        $this->assertSame(['C', 'B', 'A'], $this->codes(['sort' => 'name', 'dir' => 'desc']));
        $this->assertSame(['A', 'B', 'C'], $this->codes(['sort' => 'created_at', 'dir' => 'asc']));

        $page2 = $this->service->search(InstitutionFilters::fromInput(['per_page' => '2', 'page' => '2']));

        $this->assertSame(3, $page2['total']);
        $this->assertSame(['C'], array_column($page2['rows'], 'code'));
    }

    /*
    |--------------------------------------------------------------------------
    | Plans and subscriptions
    |--------------------------------------------------------------------------
    */

    private function plan(string $name): int
    {
        db_connect()->table('plans')->insert(['name' => $name, 'status' => 'active']);

        return (int) db_connect()->insertID();
    }

    private function subscribe(int $institutionId, int $planId, string $start, string $end): void
    {
        db_connect()->table('subscriptions')->insert(['institution_id' => $institutionId, 'plan_id' => $planId, 'start_date' => $start, 'end_date' => $end]);
    }

    public function testTheListShowsEachInstitutionsPlan(): void
    {
        $gold = $this->plan('Gold');
        $this->subscribe($this->seeded('A1', 'active', 'Alpha University'), $gold, '2026-01-01', '2026-12-31');
        $this->seeded('B2', 'active', 'Beta University');

        $rows = $this->service->search(InstitutionFilters::fromInput([]))['rows'];

        $this->assertSame('Gold', $rows[0]['plan_name']);
        $this->assertNull($rows[1]['plan_name'], 'no subscription, no plan');
    }

    public function testFilterByPlan(): void
    {
        $gold   = $this->plan('Gold');
        $silver = $this->plan('Silver');
        $this->subscribe($this->seeded('G1'), $gold, '2026-01-01', '2026-12-31');
        $this->subscribe($this->seeded('G2'), $gold, '2026-01-01', '2026-12-31');
        $this->subscribe($this->seeded('S1'), $silver, '2026-01-01', '2026-12-31');
        $this->seeded('NONE');

        $this->assertEqualsCanonicalizing(['G1', 'G2'], $this->codes(['plan' => (string) $gold]));
        $this->assertSame(['S1'], $this->codes(['plan' => (string) $silver]));
        $this->assertSame([], $this->codes(['plan' => '999999']));
    }

    public function testThePlanFilterCombinesWithStatusAndSearch(): void
    {
        $gold = $this->plan('Gold');
        $this->subscribe($this->seeded('G1', 'active', 'Acme North'), $gold, '2026-01-01', '2026-12-31');
        $this->subscribe($this->seeded('G2', 'past', 'Acme South'), $gold, '2026-01-01', '2026-12-31');
        $this->subscribe($this->seeded('G3', 'past', 'Beta South'), $gold, '2026-01-01', '2026-12-31');

        $this->assertSame(['G2'], $this->codes(['plan' => (string) $gold, 'status' => 'past', 'q' => 'acme']));
    }

    public function testPagingStillCountsOnceWhenJoinedToSubscriptions(): void
    {
        $gold = $this->plan('Gold');

        for ($i = 1; $i <= 3; $i++) {
            $this->subscribe($this->seeded("P{$i}"), $gold, '2026-01-01', '2026-12-31');
        }

        $page = $this->service->search(InstitutionFilters::fromInput(['per_page' => '2', 'page' => '2']));

        $this->assertSame(3, $page['total']);
        $this->assertCount(1, $page['rows']);
    }

    public function testAnInstitutionWithARunningSubscriptionCannotBeDeletedEvenWhenSuspended(): void
    {
        $id = $this->seeded('RUN', 'past', 'Running University');
        $this->subscribe($id, $this->plan('Gold'), '2026-01-01', date('Y-m-d', strtotime('+30 days')));

        try {
            $this->service->delete($id, 'Running University', $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('subscription that runs until', $e->getMessage());
            $this->assertStringContainsString(date('Y-m-d', strtotime('+30 days')), $e->getMessage());
        }

        $this->assertNull($this->row($id)['deleted_at']);
    }

    public function testOnceTheSubscriptionHasEndedTheInstitutionCanBeDeleted(): void
    {
        $id = $this->seeded('ENDED', 'past', 'Ended University');
        $this->subscribe($id, $this->plan('Gold'), '2025-01-01', date('Y-m-d', strtotime('-1 day')));

        $this->service->delete($id, 'Ended University', $this->admin);

        $this->assertNotNull($this->row($id)['deleted_at']);
    }

    public function testASubscriptionThatEndsTodayStillBlocksDeletion(): void
    {
        $id = $this->seeded('TODAY', 'past', 'Today University');
        $this->subscribe($id, $this->plan('Gold'), '2025-01-01', date('Y-m-d'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subscription that runs until');

        $this->service->delete($id, 'Today University', $this->admin);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function testACreatedInstitutionStartsPending(): void
    {
        $id = $this->service->create('  Acme University ', ' acme-u ', $this->admin);

        $row = $this->row($id);

        $this->assertSame('Acme University', $row['name']);
        $this->assertSame('ACME-U', $row['code'], 'the code is upper-cased');
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['deleted_at']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function provideBadNewInstitutions(): iterable
    {
        yield 'a short name' => ['Ab', 'GOOD', 'at least 3'];
        yield 'no name' => ['', 'GOOD', 'name'];
        yield 'no code' => ['Good Name', '', 'code'];
        yield 'a one-character code' => ['Good Name', 'A', 'code'];
        yield 'a code with spaces' => ['Good Name', 'HAS SPACE', 'code'];
        yield 'a code with symbols' => ['Good Name', 'A@B', 'code'];
        yield 'a code that is too long' => ['Good Name', str_repeat('A', 51), 'code'];
    }

    #[DataProvider('provideBadNewInstitutions')]
    public function testBadDetailsAreRefusedAndNothingIsCreated(string $name, string $code, string $message): void
    {
        try {
            $this->service->create($name, $code, $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsStringIgnoringCase($message, $e->getMessage());
        }

        $this->assertSame(0, db_connect()->table('institutions')->countAllResults());
        $this->assertSame([], $this->audit('institution.created'));
    }

    public function testADuplicateCodeIsRefusedEvenInAnotherCase(): void
    {
        $this->service->create('First Univ', 'DUP', $this->admin);

        $this->expectException(InvalidArgumentException::class);

        $this->service->create('Second Univ', 'dup', $this->admin);
    }

    public function testTheCodeOfADeletedInstitutionIsStillTaken(): void
    {
        $id = $this->seeded('HELD', 'past');
        (new InstitutionModel())->delete($id);

        $this->expectException(InvalidArgumentException::class);

        $this->service->create('Newcomer', 'HELD', $this->admin);
    }

    public function testCreatingIsAudited(): void
    {
        $id = $this->service->create('Acme University', 'ACME', $this->admin);

        $entry = $this->audit('institution.created')[0];

        $this->assertSame((string) $this->admin->id, (string) $entry['actor_user_id']);
        $this->assertSame('institution', $entry['target_type']);
        $this->assertSame((string) $id, $entry['target_id']);
        $this->assertSame((string) $id, (string) $entry['institution_id']);
        $this->assertSame(['name' => 'Acme University', 'code' => 'ACME', 'status' => 'pending'], json_decode($entry['new_values'], true));
    }

    /*
    |--------------------------------------------------------------------------
    | Rename
    |--------------------------------------------------------------------------
    */

    public function testRenamingKeepsTheCode(): void
    {
        $id = $this->seeded('KEEP', 'active', 'Old Name University');

        $this->service->rename($id, '  New Name University ', $this->admin);

        $row = $this->row($id);

        $this->assertSame('New Name University', $row['name']);
        $this->assertSame('KEEP', $row['code']);
    }

    public function testRenamingIsAuditedWithBeforeAndAfter(): void
    {
        $id = $this->seeded('REN', 'active', 'Old Name University');

        $this->service->rename($id, 'New Name University', $this->admin);

        $entry = $this->audit('institution.updated')[0];

        $this->assertSame(['name' => 'Old Name University'], json_decode($entry['old_values'], true));
        $this->assertSame(['name' => 'New Name University'], json_decode($entry['new_values'], true));
    }

    public function testRenamingToTheSameNameDoesNothing(): void
    {
        $id = $this->seeded('SAME', 'active', 'Same Name University');

        $this->service->rename($id, 'Same Name University', $this->admin);

        $this->assertSame([], $this->audit('institution.updated'));
    }

    public function testATooShortNameIsRefused(): void
    {
        $id = $this->seeded('SHORT', 'active', 'Proper Name University');

        try {
            $this->service->rename($id, 'Ab', $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame('Proper Name University', $this->row($id)['name']);
    }

    public function testRenamingSomethingThatDoesNotExistIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->service->rename(999999, 'Whatever Name', $this->admin);
    }

    /*
    |--------------------------------------------------------------------------
    | Suspend
    |--------------------------------------------------------------------------
    */

    public function testSuspendingAnActiveOrReadOnlyInstitutionMakesItPast(): void
    {
        $active   = $this->seeded('ACT', 'active');
        $readOnly = $this->seeded('RO', 'read_only');

        $this->service->suspend($active, $this->admin);
        $this->service->suspend($readOnly, $this->admin);

        $this->assertSame('past', $this->row($active)['status']);
        $this->assertSame('past', $this->row($readOnly)['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function provideUnsuspendableStatuses(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'past' => ['past'];
    }

    #[DataProvider('provideUnsuspendableStatuses')]
    public function testOnlyActiveOrReadOnlyInstitutionsCanBeSuspended(string $status): void
    {
        $id = $this->seeded('NOPE', $status);

        try {
            $this->service->suspend($id, $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('active or read-only', $e->getMessage());
        }

        $this->assertSame($status, $this->row($id)['status']);
        $this->assertSame([], $this->audit('institution.suspended'));
    }

    public function testSuspendingIsAuditedWithBeforeAndAfter(): void
    {
        $id = $this->seeded('AUD', 'read_only');

        $this->service->suspend($id, $this->admin);

        $entry = $this->audit('institution.suspended')[0];

        $this->assertSame(['status' => 'read_only'], json_decode($entry['old_values'], true));
        $this->assertSame(['status' => 'past'], json_decode($entry['new_values'], true));
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function testDeletingNeedsTheExactNameTyped(): void
    {
        $id = $this->seeded('DEL', 'past', 'Doomed University');

        foreach (['', 'Doomed', 'doomed university', 'Doomed University!'] as $wrong) {
            try {
                $this->service->delete($id, $wrong, $this->admin);
                $this->fail("'{$wrong}' must not confirm the deletion");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('exact name', $e->getMessage());
            }
        }

        $this->assertNull($this->row($id)['deleted_at']);
    }

    public function testTheRightNameDeletesSoftly(): void
    {
        $id = $this->seeded('DEL', 'past', 'Doomed University');

        $this->service->delete($id, '  Doomed University ', $this->admin);

        $this->assertNotNull($this->row($id)['deleted_at'], 'the row is kept');
        $this->assertNull((new InstitutionModel())->find($id), 'but hidden');
    }

    public function testAnActiveInstitutionCannotBeDeletedUntilSuspended(): void
    {
        $id = $this->seeded('LIVE', 'active', 'Live University');

        try {
            $this->service->delete($id, 'Live University', $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Suspend it', $e->getMessage());
        }

        $this->assertNull($this->row($id)['deleted_at']);

        $this->service->suspend($id, $this->admin);
        $this->service->delete($id, 'Live University', $this->admin);

        $this->assertNotNull($this->row($id)['deleted_at']);
    }

    public function testPendingAndReadOnlyInstitutionsCanBeDeleted(): void
    {
        $pending  = $this->seeded('PEND', 'pending', 'Pending University');
        $readOnly = $this->seeded('RO', 'read_only', 'Readonly University');

        $this->service->delete($pending, 'Pending University', $this->admin);
        $this->service->delete($readOnly, 'Readonly University', $this->admin);

        $this->assertNotNull($this->row($pending)['deleted_at']);
        $this->assertNotNull($this->row($readOnly)['deleted_at']);
    }

    public function testDeletingIsAudited(): void
    {
        $id = $this->seeded('DEL', 'past', 'Doomed University');

        $this->service->delete($id, 'Doomed University', $this->admin);

        $entry = $this->audit('institution.deleted')[0];

        $this->assertSame((string) $id, $entry['target_id']);
        $this->assertSame(['name' => 'Doomed University', 'status' => 'past'], json_decode($entry['old_values'], true));
    }

    public function testAnAlreadyDeletedInstitutionCannotBeDeletedAgain(): void
    {
        $id = $this->seeded('TWICE', 'past', 'Twice University');
        $this->service->delete($id, 'Twice University', $this->admin);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->service->delete($id, 'Twice University', $this->admin);
    }

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    */

    public function testRestoringBringsItBackWithItsOldStatus(): void
    {
        $id = $this->seeded('BACK', 'past', 'Returning University');
        $this->service->delete($id, 'Returning University', $this->admin);

        $this->service->restore($id, $this->admin);

        $row = $this->row($id);

        $this->assertNull($row['deleted_at']);
        $this->assertSame('past', $row['status']);
        $this->assertNotNull((new InstitutionModel())->find($id));
    }

    public function testRestoringIsAudited(): void
    {
        $id = $this->seeded('BACK', 'past', 'Returning University');
        $this->service->delete($id, 'Returning University', $this->admin);

        $this->service->restore($id, $this->admin);

        $entry = $this->audit('institution.restored')[0];

        $this->assertSame((string) $id, $entry['target_id']);
        $this->assertSame(['name' => 'Returning University', 'status' => 'past'], json_decode($entry['new_values'], true));
    }

    public function testOnlyADeletedInstitutionCanBeRestored(): void
    {
        $id = $this->seeded('LIVE', 'active');

        try {
            $this->service->restore($id, $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not deleted', $e->getMessage());
        }

        try {
            $this->service->restore(999999, $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not deleted', $e->getMessage());
        }

        $this->assertSame([], $this->audit('institution.restored'));
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing changes without a trace
    |--------------------------------------------------------------------------
    */

    public function testACreationThatCannotBeAuditedLeavesNothingBehind(): void
    {
        $this->breakAuditing();

        try {
            $this->service->create('Ghost University', 'GHOST', $this->admin);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('the audit log is unavailable', $e->getMessage());
        }

        $this->assertSame(0, db_connect()->table('institutions')->where('code', 'GHOST')->countAllResults());
    }

    public function testARenameThatCannotBeAuditedIsTakenBack(): void
    {
        $id = $this->seeded('REN', 'active', 'Original Name University');
        $this->breakAuditing();

        try {
            $this->service->rename($id, 'Changed Name University', $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('Original Name University', $this->row($id)['name']);
    }

    public function testASuspensionThatCannotBeAuditedIsTakenBack(): void
    {
        $id = $this->seeded('SUS', 'read_only');
        $this->breakAuditing();

        try {
            $this->service->suspend($id, $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('read_only', $this->row($id)['status'], 'back to exactly what it was');
    }

    public function testADeletionThatCannotBeAuditedIsTakenBack(): void
    {
        $id = $this->seeded('DEL', 'past', 'Doomed University');
        $this->breakAuditing();

        try {
            $this->service->delete($id, 'Doomed University', $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNull($this->row($id)['deleted_at']);
    }

    public function testARestoreThatCannotBeAuditedIsTakenBack(): void
    {
        $id = $this->seeded('BACK', 'past', 'Returning University');
        $this->service->delete($id, 'Returning University', $this->admin);
        $this->breakAuditing();

        try {
            $this->service->restore($id, $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNotNull($this->row($id)['deleted_at'], 'still deleted');
    }
}
