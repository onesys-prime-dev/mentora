<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
use App\Services\Flags\FeatureFlagAdmin;
use App\Services\Flags\FeatureFlags;
use CodeIgniter\Config\Factories;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Config\FeatureFlags as FlagConfig;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Changing feature flags: for everyone (including the kill switch) and for
 * one institution; the note and last-day rules; the audit entries; the undo
 * when an entry cannot be written; and the reads the flags pages use.
 *
 * @internal
 */
final class FeatureFlagAdminTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private FeatureFlagAdmin $admin;

    private FeatureFlags $flags;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        config(FlagConfig::class)->flags = [
            'test.off'  => ['description' => 'Off by default', 'default' => false],
            'test.on'   => ['description' => 'On by default', 'default' => true],
            'test.plan' => ['description' => 'Granted by plans', 'default' => false],
        ];

        $this->admin = new FeatureFlagAdmin();
        $this->flags = new FeatureFlags();
        $this->actor = CourseFactory::user('admin');
    }

    protected function tearDown(): void
    {
        Factories::reset('config');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function institution(string $code = 'ACME'): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => 'active']);

        return (int) db_connect()->insertID();
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return db_connect()->table('feature_flag_overrides')->orderBy('id')->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
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
    | For one institution
    |--------------------------------------------------------------------------
    */

    public function testTurningAFlagOnForAnInstitutionStoresARowAndChangesTheAnswer(): void
    {
        $institution = $this->institution();

        $this->assertTrue($this->admin->setForInstitution($institution, 'test.off', 'on', 'Pilot with their IT team', '2027-03-31', $this->actor));

        $row = $this->rows()[0];

        $this->assertSame('test.off', $row['flag_key']);
        $this->assertSame((string) $institution, (string) $row['institution_id']);
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertSame('Pilot with their IT team', $row['note']);
        $this->assertSame('2027-03-31', $row['ends_on']);
        $this->assertSame((string) $this->actor->id, (string) $row['created_by_user_id']);
        $this->assertTrue($this->flags->isEnabled('test.off', $institution));
    }

    public function testTurningAFlagOffForAnInstitutionBeatsItsDefault(): void
    {
        $institution = $this->institution();

        $this->admin->setForInstitution($institution, 'test.on', 'off', 'Trouble with their SIS', null, $this->actor);

        $this->assertFalse($this->flags->isEnabled('test.on', $institution));
        $this->assertTrue($this->flags->isEnabled('test.on', $this->institution('OTHER')), 'another institution is unaffected');
    }

    public function testChangingAnOverrideUpdatesTheSameRow(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'First reason', null, $this->actor);

        $this->admin->setForInstitution($institution, 'test.off', 'off', 'Second reason', '2027-01-31', $this->actor);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]['enabled']);
        $this->assertSame('Second reason', $rows[0]['note']);
        $this->assertSame('2027-01-31', $rows[0]['ends_on']);
    }

    public function testGoingBackToDefaultRemovesTheOverride(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'Pilot', null, $this->actor);

        $this->assertTrue($this->admin->setForInstitution($institution, 'test.off', 'default', null, null, $this->actor));

        $this->assertSame([], $this->rows());
        $this->assertFalse($this->flags->isEnabled('test.off', $institution));
    }

    public function testOneInstitutionsChangeDoesNotTouchAnothers(): void
    {
        $a = $this->institution('A');
        $b = $this->institution('B');

        $this->admin->setForInstitution($a, 'test.off', 'on', 'Pilot', null, $this->actor);
        $this->admin->setForInstitution($b, 'test.off', 'off', 'Not yet', null, $this->actor);

        $this->assertTrue($this->flags->isEnabled('test.off', $a));
        $this->assertFalse($this->flags->isEnabled('test.off', $b));
        $this->assertCount(2, $this->rows());
    }

    public function testAnInstitutionThatDoesNotExistOrIsDeletedIsRefused(): void
    {
        $gone = $this->institution('GONE');
        db_connect()->table('institutions')->where('id', $gone)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        foreach ([999999, $gone] as $id) {
            try {
                $this->admin->setForInstitution($id, 'test.off', 'on', 'Why', null, $this->actor);
                $this->fail('expected a refusal');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('does not exist', $e->getMessage());
            }
        }

        $this->assertSame([], $this->rows());
    }

    /*
    |--------------------------------------------------------------------------
    | For everyone
    |--------------------------------------------------------------------------
    */

    public function testTheKillSwitchTurnsAFlagOffEverywhere(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.on', 'on', 'Pilot', null, $this->actor);

        $this->admin->setGlobal('test.on', 'off', 'Security problem found', null, $this->actor);

        $row = db_connect()->table('feature_flag_overrides')->where('institution_id IS NULL', null, false)->get()->getRowArray();

        $this->assertSame(0, (int) $row['enabled']);
        $this->assertSame(1, (int) $row['is_global']);
        $this->assertFalse($this->flags->isEnabled('test.on', $institution), 'even where it was turned on for them');
        $this->assertFalse($this->flags->isEnabled('test.on', null));
    }

    public function testGlobalOnMakesAnOffByDefaultFlagOnForEveryone(): void
    {
        $this->admin->setGlobal('test.off', 'on', 'Launching to everyone', null, $this->actor);

        $this->assertTrue($this->flags->isEnabled('test.off', $this->institution()));
    }

    public function testGoingBackToDefaultRemovesTheGlobalRow(): void
    {
        $this->admin->setGlobal('test.off', 'on', 'Launch', null, $this->actor);

        $this->admin->setGlobal('test.off', 'default', null, null, $this->actor);

        $this->assertSame([], $this->rows());
        $this->assertFalse($this->flags->isEnabled('test.off', $this->institution()));
    }

    public function testAGlobalChangeLeavesInstitutionOverridesInPlace(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'Pilot', null, $this->actor);

        $this->admin->setGlobal('test.off', 'default', null, null, $this->actor);

        $this->assertCount(1, $this->rows());
    }

    /*
    |--------------------------------------------------------------------------
    | The rules
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{?string}> */
    public static function provideMissingNotes(): iterable
    {
        yield 'none' => [null];
        yield 'empty' => [''];
        yield 'only spaces' => ['    '];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideMissingNotes')]
    public function testANoteIsRequiredToTurnAFlagOnOrOff(?string $note): void
    {
        $institution = $this->institution();

        foreach (['on', 'off'] as $state) {
            try {
                $this->admin->setForInstitution($institution, 'test.off', $state, $note, null, $this->actor);
                $this->fail("a note is needed to turn it {$state}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('note is needed', $e->getMessage());
            }

            try {
                $this->admin->setGlobal('test.off', $state, $note, null, $this->actor);
                $this->fail("a note is needed to turn it {$state} for everyone");
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame([], $this->rows());
        $this->assertSame([], $this->audit('flag.override_changed'));
    }

    public function testNoNoteIsNeededToGoBackToDefault(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'Pilot', null, $this->actor);

        $this->assertTrue($this->admin->setForInstitution($institution, 'test.off', 'default', null, null, $this->actor));
    }

    public function testTheNoteIsTrimmedAndBounded(): void
    {
        $institution = $this->institution();

        $this->admin->setForInstitution($institution, 'test.off', 'on', '  Padded  ', null, $this->actor);
        $this->assertSame('Padded', $this->rows()[0]['note']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 500');

        $this->admin->setForInstitution($institution, 'test.off', 'off', str_repeat('a', 501), null, $this->actor);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideLastDays(): iterable
    {
        yield 'not a date' => ['soon', false];
        yield 'wrong order' => ['31-12-2099', false];
        yield 'not a real day' => ['2099-02-30', false];
        yield 'in the past' => ['2020-01-01', false];
        yield 'far in the future' => ['2099-12-31', true];
        yield 'empty means no end' => ['', true];
        yield 'blank means no end' => ['   ', true];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideLastDays')]
    public function testTheLastDayMustBeARealDayNotInThePast(string $endsOn, bool $accepted): void
    {
        $institution = $this->institution();

        if ($accepted) {
            $this->assertTrue($this->admin->setForInstitution($institution, 'test.off', 'on', 'Why', $endsOn, $this->actor));
            $this->assertSame(trim($endsOn) === '' ? null : $endsOn, $this->rows()[0]['ends_on']);

            return;
        }

        $this->expectException(InvalidArgumentException::class);

        $this->admin->setForInstitution($institution, 'test.off', 'on', 'Why', $endsOn, $this->actor);
    }

    public function testTodayIsAValidLastDay(): void
    {
        $this->assertTrue($this->admin->setForInstitution($this->institution(), 'test.off', 'on', 'Why', date('Y-m-d'), $this->actor));
    }

    public function testAnUnknownFlagOrStateIsRefused(): void
    {
        $institution = $this->institution();

        foreach ([['test.nope', 'on'], ['test.off', 'maybe'], ['test.off', ''], ['test.off', 'ON']] as [$key, $state]) {
            try {
                $this->admin->setForInstitution($institution, $key, $state, 'Why', null, $this->actor);
                $this->fail("{$key} / {$state} must be refused");
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame([], $this->rows());
    }

    public function testSavingWhatIsAlreadyTheCaseChangesNothing(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'Pilot', '2099-01-01', $this->actor);
        $audits = count($this->audit('flag.override_changed'));

        $this->assertFalse($this->admin->setForInstitution($institution, 'test.off', 'on', ' Pilot ', '2099-01-01', $this->actor));
        $this->assertFalse($this->admin->setForInstitution($institution, 'test.on', 'default', null, null, $this->actor), 'and "default" when there is no row');

        $this->assertCount($audits, $this->audit('flag.override_changed'));
        $this->assertCount(1, $this->rows());
    }

    /*
    |--------------------------------------------------------------------------
    | The audit trail
    |--------------------------------------------------------------------------
    */

    public function testAnOverrideChangeIsAuditedWithBeforeAndAfter(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'Pilot', null, $this->actor);
        $this->admin->setForInstitution($institution, 'test.off', 'off', 'Problem', '2099-06-30', $this->actor);

        $entries = $this->audit('flag.override_changed');

        $this->assertCount(2, $entries);
        $this->assertSame((string) $this->actor->id, (string) $entries[1]['actor_user_id']);
        $this->assertSame('feature_flag', $entries[1]['target_type']);
        $this->assertSame('test.off', $entries[1]['target_id']);
        $this->assertSame((string) $institution, (string) $entries[1]['institution_id']);
        $this->assertSame(['state' => 'default', 'note' => null, 'ends_on' => null], json_decode($entries[0]['old_values'], true));
        $this->assertSame(['state' => 'on', 'note' => 'Pilot', 'ends_on' => null], json_decode($entries[1]['old_values'], true));
        $this->assertSame(['state' => 'off', 'note' => 'Problem', 'ends_on' => '2099-06-30'], json_decode($entries[1]['new_values'], true));
    }

    public function testAGlobalChangeIsAuditedSeparately(): void
    {
        $this->admin->setGlobal('test.on', 'off', 'Security problem', null, $this->actor);

        $entry = $this->audit('flag.global_changed')[0];

        $this->assertNull($entry['institution_id']);
        $this->assertSame('test.on', $entry['target_id']);
        $this->assertSame('off', json_decode($entry['new_values'], true)['state']);
        $this->assertSame([], $this->audit('flag.override_changed'));
    }

    public function testRemovingAnOverrideIsAudited(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'Pilot', null, $this->actor);
        $this->admin->setForInstitution($institution, 'test.off', 'default', null, null, $this->actor);

        $entry = $this->audit('flag.override_changed')[1];

        $this->assertSame('on', json_decode($entry['old_values'], true)['state']);
        $this->assertSame('default', json_decode($entry['new_values'], true)['state']);
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing changes without a trace
    |--------------------------------------------------------------------------
    */

    public function testANewOverrideThatCannotBeAuditedLeavesNothingBehind(): void
    {
        $institution = $this->institution();
        $this->breakAuditing();

        try {
            $this->admin->setForInstitution($institution, 'test.off', 'on', 'Why', null, $this->actor);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('the audit log is unavailable', $e->getMessage());
        }

        $this->assertSame([], $this->rows());
        $this->assertFalse($this->flags->isEnabled('test.off', $institution));
    }

    public function testAnEditThatCannotBeAuditedRestoresTheEarlierRow(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'First', '2099-01-01', $this->actor);
        $this->breakAuditing();

        try {
            $this->admin->setForInstitution($institution, 'test.off', 'off', 'Second', null, $this->actor);
        } catch (RuntimeException) {
            // expected
        }

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['enabled']);
        $this->assertSame('First', $rows[0]['note']);
        $this->assertSame('2099-01-01', $rows[0]['ends_on']);
    }

    public function testARemovalThatCannotBeAuditedPutsTheRowBack(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'First', null, $this->actor);
        $this->breakAuditing();

        try {
            $this->admin->setForInstitution($institution, 'test.off', 'default', null, null, $this->actor);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertCount(1, $this->rows());
        $this->assertTrue($this->flags->isEnabled('test.off', $institution));
    }

    public function testAKillSwitchThatCannotBeAuditedIsNotLeftSwitchedOn(): void
    {
        $this->breakAuditing();

        try {
            $this->admin->setGlobal('test.on', 'off', 'Why', null, $this->actor);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertTrue($this->flags->isEnabled('test.on', null), 'the feature is still on');
        $this->assertSame([], $this->rows());
    }

    /*
    |--------------------------------------------------------------------------
    | The reads the pages use
    |--------------------------------------------------------------------------
    */

    public function testInspectShowsTheAnswerTheReasonAndWhatLiesBehindIt(): void
    {
        $institution = $this->institution();
        $this->admin->setForInstitution($institution, 'test.off', 'on', 'Pilot', '2099-01-01', $this->actor);
        $this->admin->setGlobal('test.on', 'off', 'Problem', null, $this->actor);

        $detail = $this->flags->inspect($institution);

        $this->assertTrue($detail['test.off']['enabled']);
        $this->assertSame('override_on', $detail['test.off']['reason']);
        $this->assertSame('Pilot', $detail['test.off']['override']['note']);
        $this->assertFalse($detail['test.off']['override']['ended']);
        $this->assertNull($detail['test.off']['global']);

        $this->assertFalse($detail['test.on']['enabled']);
        $this->assertSame('kill_switch', $detail['test.on']['reason']);
        $this->assertSame(0, (int) $detail['test.on']['global']['enabled']);

        $this->assertSame('default', $detail['test.plan']['reason']);
        $this->assertFalse($detail['test.plan']['plan']);
        $this->assertFalse($detail['test.plan']['default']);
    }

    public function testInspectMarksARowThatHasEndedWithoutApplyingIt(): void
    {
        $institution = $this->institution();
        db_connect()->table('feature_flag_overrides')->insert(['flag_key' => 'test.off', 'institution_id' => $institution, 'enabled' => 1, 'ends_on' => '2026-10-11']);

        $detail = $this->flags->inspect($institution, new DateTimeImmutable('2026-10-12'));

        $this->assertTrue($detail['test.off']['override']['ended']);
        $this->assertFalse($detail['test.off']['enabled']);
        $this->assertSame('default', $detail['test.off']['reason']);
    }

    public function testInspectKnowsWhenThePlanGrantsAFlag(): void
    {
        $institution = $this->institution();
        db_connect()->table('plans')->insert(['name' => 'Gold', 'features' => json_encode(['test.plan']), 'status' => 'active']);
        db_connect()->table('subscriptions')->insert(['institution_id' => $institution, 'plan_id' => (int) db_connect()->insertID(), 'start_date' => '2025-01-01', 'end_date' => '2099-12-31']);

        $detail = $this->flags->inspect($institution);

        $this->assertTrue($detail['test.plan']['plan']);
        $this->assertSame('plan', $detail['test.plan']['reason']);
    }

    public function testInspectForNobodyShowsOnlyTheGlobalSide(): void
    {
        $this->admin->setGlobal('test.off', 'on', 'Launch', null, $this->actor);

        $detail = $this->flags->inspect(null);

        $this->assertSame('global_on', $detail['test.off']['reason']);
        $this->assertNotNull($detail['test.off']['global']);
        $this->assertNull($detail['test.off']['override']);
    }

    public function testAdoptionCountsInstitutionsThatHaveEachFlagOn(): void
    {
        $a = $this->institution('A');
        $b = $this->institution('B');
        $c = $this->institution('C');
        $gone = $this->institution('GONE');
        db_connect()->table('institutions')->where('id', $gone)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $this->admin->setForInstitution($a, 'test.off', 'on', 'Pilot', null, $this->actor);
        $this->admin->setForInstitution($b, 'test.off', 'on', 'Pilot', null, $this->actor);
        $this->admin->setForInstitution($c, 'test.on', 'off', 'Problem', null, $this->actor);

        $adoption = $this->flags->adoption();

        $this->assertSame(['on' => 2, 'total' => 3, 'overrides' => 2], $adoption['test.off']);
        $this->assertSame(['on' => 2, 'total' => 3, 'overrides' => 1], $adoption['test.on'], 'deleted institutions are not counted');
        $this->assertSame(['on' => 0, 'total' => 3, 'overrides' => 0], $adoption['test.plan']);
    }

    public function testAdoptionFollowsTheKillSwitchAndEndedOverrides(): void
    {
        $a = $this->institution('A');
        $this->institution('B');
        db_connect()->table('feature_flag_overrides')->insert(['flag_key' => 'test.off', 'institution_id' => $a, 'enabled' => 1, 'ends_on' => '2026-10-11']);
        $this->admin->setGlobal('test.on', 'off', 'Problem', null, $this->actor);

        $adoption = $this->flags->adoption(new DateTimeImmutable('2026-10-12'));

        $this->assertSame(['on' => 0, 'total' => 2, 'overrides' => 0], $adoption['test.off'], 'an override that ended no longer counts');
        $this->assertSame(0, $adoption['test.on']['on'], 'nobody has it while it is killed');
    }
}
