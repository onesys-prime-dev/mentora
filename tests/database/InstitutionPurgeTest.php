<?php

declare(strict_types=1);

use App\Models\Academic\InstitutionModel;
use App\Services\Admin\PlatformSettings;
use App\Services\Audit\AuditLogger;
use App\Services\Institutions\InstitutionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Purging: what a purge removes and what it must not touch, the audit entry
 * that survives it, the 90-day rule and the scheduled command.
 *
 * @internal
 */
final class InstitutionPurgeTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    protected bool $isolateTests = true;

    private const NOW = '2026-10-12 12:00:00';

    private InstitutionService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->service = new InstitutionService();
        $this->admin   = CourseFactory::user('admin');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** A complete institution (department down to an enrolled student), already deleted $daysAgo days before NOW. */
    private function deletedInstitution(int $daysAgo, int &$offering = 0): int
    {
        $offering = CourseFactory::offering();

        $institutionId = $this->institutionOf($offering);

        $deletedAt = (new DateTimeImmutable(self::NOW))->modify("-{$daysAgo} days")->format('Y-m-d H:i:s');
        db_connect()->table('institutions')->where('id', $institutionId)->update(['status' => 'past', 'deleted_at' => $deletedAt]);

        return $institutionId;
    }

    private function liveInstitution(): int
    {
        return $this->institutionOf(CourseFactory::offering());
    }

    private function institutionOf(int $offering): int
    {
        return (int) db_connect()->table('course_offerings')
            ->select('departments.institution_id')
            ->join('courses', 'courses.id = course_offerings.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('course_offerings.id', $offering)
            ->get()->getRowArray()['institution_id'];
    }

    private function member(int $institutionId, ?User $user = null): User
    {
        $user ??= CourseFactory::user('student');
        db_connect()->table('institution_users')->insert(['institution_id' => $institutionId, 'user_id' => $user->id]);

        return $user;
    }

    private function exists(string $table, string $column, int $id): bool
    {
        return db_connect()->table($table)->where($column, $id)->countAllResults() > 0;
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    /*
    |--------------------------------------------------------------------------
    | What a purge removes
    |--------------------------------------------------------------------------
    */

    public function testPurgingRemovesTheInstitutionAndEverythingUnderIt(): void
    {
        $offering    = 0;
        $institution = $this->deletedInstitution(10, $offering);
        $student     = CourseFactory::user('student');
        CourseFactory::enroll($offering, $student);
        $module = CourseFactory::module($offering);

        $this->assertTrue($this->exists('course_offerings', 'id', $offering));

        $this->service->purge($institution, null, $this->admin);

        $this->assertFalse($this->exists('institutions', 'id', $institution));
        $this->assertSame(0, db_connect()->table('departments')->where('institution_id', $institution)->countAllResults());
        $this->assertFalse($this->exists('course_offerings', 'id', $offering));
        $this->assertFalse($this->exists('course_modules', 'id', $module));
        $this->assertSame(0, db_connect()->table('enrollments')->where('offering_id', $offering)->countAllResults());
        $this->assertSame(0, db_connect()->table('academic_terms')->where('institution_id', $institution)->countAllResults());
    }

    public function testPurgingRemovesTheInstitutionsInvitationsToo(): void
    {
        $institution = $this->deletedInstitution(10);
        $other       = $this->deletedInstitution(11);

        foreach ([$institution => 'doomed', $other => 'kept'] as $id => $word) {
            db_connect()->table('institution_invitations')->insert([
                'institution_id' => $id, 'role' => 'institutional_admin', 'email' => "{$word}@example.com", 'first_name' => 'Ana', 'last_name' => 'Cruz',
                'token_hash' => hash('sha256', $word), 'expires_at' => '2026-12-31 00:00:00',
            ]);
        }

        $this->service->purge($institution, null, $this->admin);

        $this->assertSame(0, db_connect()->table('institution_invitations')->where('institution_id', $institution)->countAllResults());
        $this->assertSame(1, db_connect()->table('institution_invitations')->where('institution_id', $other)->countAllResults());
    }

    public function testAnotherInstitutionIsNotTouched(): void
    {
        $doomed = $this->deletedInstitution(10);

        $otherOffering = CourseFactory::offering();
        $other         = $this->institutionOf($otherOffering);
        $module        = CourseFactory::module($otherOffering);
        $student       = CourseFactory::user('student');
        CourseFactory::enroll($otherOffering, $student);

        $this->service->purge($doomed, null, $this->admin);

        $this->assertTrue($this->exists('institutions', 'id', $other));
        $this->assertTrue($this->exists('course_offerings', 'id', $otherOffering));
        $this->assertTrue($this->exists('course_modules', 'id', $module));
        $this->assertSame(1, db_connect()->table('enrollments')->where('offering_id', $otherOffering)->countAllResults());
    }

    public function testUserAccountsAreKeptButTheirMembershipGoes(): void
    {
        $institution = $this->deletedInstitution(10);
        $alone       = $this->member($institution);

        $this->service->purge($institution, null, $this->admin);

        $this->assertTrue($this->exists('users', 'id', (int) $alone->id), 'the account is not deleted');
        $this->assertSame(0, db_connect()->table('institution_users')->where('institution_id', $institution)->countAllResults());
    }

    public function testASystemAdministratorAccountIsNeverAffected(): void
    {
        $institution = $this->deletedInstitution(10);

        $this->service->purge($institution, null, $this->admin);

        $this->assertTrue($this->exists('users', 'id', (int) $this->admin->id));
    }

    /*
    |--------------------------------------------------------------------------
    | What may be purged
    |--------------------------------------------------------------------------
    */

    public function testAnInstitutionThatIsNotDeletedCannotBePurged(): void
    {
        $live = $this->liveInstitution();

        try {
            $this->service->purge($live, null, $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('deleted', $e->getMessage());
        }

        $this->assertTrue($this->exists('institutions', 'id', $live));
        $this->assertSame([], $this->audit('institution.purged'));
    }

    public function testAMissingInstitutionCannotBePurged(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->purge(999999, null, $this->admin);
    }

    public function testAPersonMustTypeTheExactName(): void
    {
        $institution = $this->deletedInstitution(10);
        $name        = db_connect()->table('institutions')->where('id', $institution)->get()->getRowArray()['name'];

        foreach (['', 'wrong', strtolower($name), $name . '!'] as $wrong) {
            try {
                $this->service->purge($institution, $wrong, $this->admin);
                $this->fail("'{$wrong}' must not confirm a purge");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('exact name', $e->getMessage());
            }
        }

        $this->assertTrue($this->exists('institutions', 'id', $institution));

        $this->service->purge($institution, "  {$name} ", $this->admin);

        $this->assertFalse($this->exists('institutions', 'id', $institution));
    }

    public function testAnInstitutionCanBePurgedBeforeTheRetentionPeriodEnds(): void
    {
        $institution = $this->deletedInstitution(1);

        $this->service->purge($institution, null, $this->admin);

        $this->assertFalse($this->exists('institutions', 'id', $institution));
    }

    /*
    |--------------------------------------------------------------------------
    | The audit entry
    |--------------------------------------------------------------------------
    */

    public function testThePurgeIsRecordedAndTheEntrySurvivesTheInstitution(): void
    {
        $institution = $this->deletedInstitution(10);
        $this->member($institution);
        $this->member($institution);
        $row = db_connect()->table('institutions')->where('id', $institution)->get()->getRowArray();

        $this->service->purge($institution, null, $this->admin);

        $entries = $this->audit('institution.purged');

        $this->assertCount(1, $entries);
        $this->assertSame((string) $this->admin->id, (string) $entries[0]['actor_user_id']);
        $this->assertSame((string) $institution, $entries[0]['target_id']);
        $this->assertSame((string) $institution, (string) $entries[0]['institution_id']);

        $old = json_decode($entries[0]['old_values'], true);

        $this->assertSame($row['name'], $old['name']);
        $this->assertSame($row['code'], $old['code']);
        $this->assertSame('past', $old['status']);
        $this->assertSame(1, $old['departments']);
        $this->assertSame(1, $old['programs']);
        $this->assertSame(1, $old['courses']);
        $this->assertSame(2, $old['members']);
        $this->assertSame(2, $old['members_left_without_an_institution']);
    }

    public function testNothingIsPurgedIfTheAuditEntryCannotBeWritten(): void
    {
        $institution = $this->deletedInstitution(10);

        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                throw new RuntimeException('the audit log is unavailable');
            }
        });

        try {
            $this->service->purge($institution, null, $this->admin);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertTrue($this->exists('institutions', 'id', $institution), 'an irreversible step never happens untraced');
        $this->assertNotNull((new InstitutionModel())->withDeleted()->find($institution));
    }

    /*
    |--------------------------------------------------------------------------
    | The scheduled purge
    |--------------------------------------------------------------------------
    */

    public function testOnlyInstitutionsDeletedLongEnoughAreCollected(): void
    {
        $old      = $this->deletedInstitution(120);
        $boundary = $this->deletedInstitution(90);
        $recent   = $this->deletedInstitution(89);
        $live     = $this->liveInstitution();

        $result = $this->service->purgeExpired($this->now());

        $this->assertEqualsCanonicalizing([$old, $boundary], $result['purged']);
        $this->assertSame([], $result['failed']);
        $this->assertFalse($this->exists('institutions', 'id', $old));
        $this->assertFalse($this->exists('institutions', 'id', $boundary), 'exactly 90 days is due');
        $this->assertTrue($this->exists('institutions', 'id', $recent));
        $this->assertTrue($this->exists('institutions', 'id', $live));
    }

    public function testADryRunListsButRemovesNothing(): void
    {
        $old = $this->deletedInstitution(120);

        $result = $this->service->purgeExpired($this->now(), true);

        $this->assertSame([$old], $result['purged']);
        $this->assertTrue($this->exists('institutions', 'id', $old));
        $this->assertSame([], $this->audit('institution.purged'));
    }

    public function testTheRetentionSettingDecidesWhatIsDue(): void
    {
        $forty = $this->deletedInstitution(40);

        $this->assertSame([], $this->service->purgeExpired($this->now())['purged'], '40 days is inside the default 90');

        (new PlatformSettings())->set('deletedInstitutionRetentionDays', 30);
        Services::resetSingle('settings');

        $this->assertSame([$forty], $this->service->purgeExpired($this->now())['purged']);
    }

    public function testTheScheduledPurgeIsRecordedAsTheSystem(): void
    {
        $this->deletedInstitution(120);

        $this->service->purgeExpired($this->now());

        $entry = $this->audit('institution.purged')[0];

        $this->assertNull($entry['actor_user_id']);
        $this->assertSame('system (scheduled purge)', $entry['actor_label']);
    }

    public function testNothingDueMeansNothingHappens(): void
    {
        $this->deletedInstitution(5);

        $this->assertSame(['purged' => [], 'failed' => []], $this->service->purgeExpired($this->now()));
    }

    public function testOneFailureDoesNotStopTheRest(): void
    {
        $first  = $this->deletedInstitution(120);
        $second = $this->deletedInstitution(110);

        $calls = 0;
        Services::injectMock('auditLogger', new class ($calls) extends AuditLogger {
            public function __construct(private int &$calls)
            {
            }

            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('first one fails');
                }

                return parent::log($action, $actor, $details);
            }
        });

        $result = $this->service->purgeExpired($this->now());

        $this->assertSame([$first => 'first one fails'], $result['failed']);
        $this->assertSame([$second], $result['purged']);
        $this->assertTrue($this->exists('institutions', 'id', $first));
        $this->assertFalse($this->exists('institutions', 'id', $second));
    }

    /*
    |--------------------------------------------------------------------------
    | The spark command
    |--------------------------------------------------------------------------
    */

    public function testTheCommandPurgesWhatIsDue(): void
    {
        $due = $this->deletedInstitution(0);
        db_connect()->table('institutions')->where('id', $due)->update(['deleted_at' => date('Y-m-d H:i:s', strtotime('-100 days'))]);

        command('institutions:purge-expired');

        $this->assertStringContainsString('Purged 1 institution(s)', $this->getStreamFilterBuffer());
        $this->assertFalse($this->exists('institutions', 'id', $due));
    }

    public function testTheCommandSaysWhenThereIsNothingToDo(): void
    {
        $this->deletedInstitution(0);
        db_connect()->table('institutions')->update(['deleted_at' => date('Y-m-d H:i:s')]);

        command('institutions:purge-expired');

        $this->assertStringContainsString('Purged 0 institution(s)', $this->getStreamFilterBuffer());
    }

    public function testTheCommandDryRunRemovesNothing(): void
    {
        $due = $this->deletedInstitution(0);
        db_connect()->table('institutions')->where('id', $due)->update(['deleted_at' => date('Y-m-d H:i:s', strtotime('-100 days'))]);

        command('institutions:purge-expired --dry-run');

        $this->assertStringContainsString('Would purge 1 institution(s)', $this->getStreamFilterBuffer());
        $this->assertTrue($this->exists('institutions', 'id', $due));
    }

    /*
    |--------------------------------------------------------------------------
    | The archive lists
    |--------------------------------------------------------------------------
    */

    public function testThePastListHoldsPastInstitutionsThatAreNotDeleted(): void
    {
        $past = $this->liveInstitution();
        db_connect()->table('institutions')->where('id', $past)->update(['status' => 'past']);
        $this->liveInstitution();
        $deletedPast = $this->deletedInstitution(5);

        $ids = array_map('intval', array_column($this->service->pastInstitutions(), 'id'));

        $this->assertSame([$past], $ids);
        $this->assertNotContains($deletedPast, $ids);
    }

    public function testTheDeletedListShowsWhenEachIsPurgedSoonestFirst(): void
    {
        $later   = $this->deletedInstitution(10);
        $sooner  = $this->deletedInstitution(80);
        $overdue = $this->deletedInstitution(100);

        $rows = $this->service->deletedInstitutions($this->now());

        $this->assertSame([$overdue, $sooner, $later], array_map('intval', array_column($rows, 'id')));
        $this->assertSame(0, $rows[0]['days_left']);
        $this->assertSame(10, $rows[1]['days_left']);
        $this->assertSame(80, $rows[2]['days_left']);
        $this->assertSame('2026-10-22', $rows[1]['purge_on'], 'deleted 80 days before 12 Oct, purged 90 days after that');
    }
}
