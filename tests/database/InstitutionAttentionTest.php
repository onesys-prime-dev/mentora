<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionAttention;
use App\Services\Notifications\NotificationTriggers;
use CodeIgniter\Config\Services;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * What needs the institution's administrator's attention (IA2) and what they
 * are told about the calendar and invitations (IA13): each rule on its own
 * with the day passed in, that nothing leaks between institutions, and that
 * running the notifications again tells nobody twice.
 *
 * @internal
 */
final class InstitutionAttentionTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $boss;

    private InstitutionAttention $attention;

    /** The term runs 1 Aug to 20 Dec 2026. */
    private function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 09:00:00');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->institution = SisFixture::institution('ATTA');
        $this->other       = SisFixture::institution('ATTB');
        $this->boss        = (int) PeopleFactory::person('institutional_admin', 'Bo', 'Boss', 'boss@atta.example', $this->institution)->id;
        $this->attention   = new InstitutionAttention();

        // A recent good backup keeps the platform's own alerts out of these cases.
        db_connect()->table('backups')->insert(['status' => 'succeeded', 'trigger' => 'scheduled', 'created_at' => '2026-10-10 06:00:00', 'finished_at' => '2026-10-10 06:00:00']);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** @return list<string> the keys of what needs attention */
    private function keys(string $date = '2026-09-15'): array
    {
        return array_column($this->attention->items($this->institution, $this->day($date)), 'key');
    }

    private function invitation(string $expires, ?string $accepted = null, ?string $revoked = null, ?int $institution = null): int
    {
        db_connect()->table('institution_invitations')->insert([
            'institution_id' => $institution ?? $this->institution, 'email' => 'inv' . random_int(1000, 99999) . '@example.com',
            'role' => 'student', 'first_name' => 'In', 'last_name' => 'Vitee', 'token_hash' => bin2hex(random_bytes(16)),
            'expires_at' => $expires, 'accepted_at' => $accepted, 'revoked_at' => $revoked, 'created_at' => '2026-09-01 00:00:00',
        ]);

        return (int) db_connect()->insertID();
    }

    private function sisRun(string $status, string $startedAt, string $direction = 'inbound', string $trigger = 'manual', int $dry = 0, ?int $institution = null): void
    {
        db_connect()->table('sis_runs')->insert([
            'institution_id' => $institution ?? $this->institution, 'direction' => $direction, 'trigger' => $trigger,
            'status' => $status, 'dry_run' => $dry, 'message' => 'm', 'started_at' => $startedAt,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function notes(int $userId): array
    {
        $rows = db_connect()->table('notifications')->where('user_id', $userId)->orderBy('id')->get()->getResultArray();

        return array_column($rows, null, 'type');
    }

    public function testAQuietInstitutionHasNothingToDo(): void
    {
        $this->assertSame([], $this->keys());
    }

    public function testNoActiveTermIsSaid(): void
    {
        db_connect()->table('academic_terms')->where('institution_id', $this->institution)->update(['status' => 'completed']);

        $this->assertContains('no-term', $this->keys());
    }

    public function testATermEndingWithinFourteenDaysIsSaid(): void
    {
        $this->assertNotContains('term-ending', $this->keys('2026-12-05'));
        $this->assertContains('term-ending', $this->keys('2026-12-06'));
        $this->assertContains('term-ending', $this->keys('2026-12-20'), 'on the last day');
        $this->assertNotContains('term-ending', $this->keys('2026-12-21'), 'after it ended');
    }

    public function testOfferingsWithNoInstructorAreCountedForTheActiveTermOnly(): void
    {
        SisFixture::course($this->institution, 'C1', 'IT101');
        SisFixture::course($this->institution, 'C2', 'IT102');

        $term = (int) db_connect()->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $this->assertSame(2, $this->attention->offeringsWithoutInstructor($this->institution, $term));
        $this->assertContains('no-instructor', $this->keys());

        // One gets a teacher; an archived one is not counted.
        $offerings = array_column(db_connect()->table('course_offerings')->select('id')->get()->getResultArray(), 'id');
        $teacher   = PeopleFactory::person('instructor', 'Ti', 'Tee', 'ti@atta.example', $this->institution);
        db_connect()->table('instructor_assignments')->insert(['offering_id' => $offerings[0], 'instructor_user_id' => $teacher->id, 'assigned_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00']);
        db_connect()->table('course_offerings')->where('id', $offerings[1])->update(['status' => 'archived']);

        $this->assertSame(0, $this->attention->offeringsWithoutInstructor($this->institution, $term));
        $this->assertNotContains('no-instructor', $this->keys());
    }

    public function testAnotherInstitutionsOfferingsAreNotCounted(): void
    {
        SisFixture::course($this->other, 'C1', 'IT101');

        $this->assertNotContains('no-instructor', $this->keys());
        $this->assertNotContains('no-offering', $this->keys());
    }

    public function testCoursesWithNoOfferingThisTermAreCounted(): void
    {
        $program = (int) db_connect()->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;

        db_connect()->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT201', 'course_title' => 'Unoffered', 'units' => 3]);

        $term = (int) db_connect()->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $this->assertSame(1, $this->attention->coursesWithoutOffering($this->institution, $term));
        $this->assertContains('no-offering', $this->keys());

        db_connect()->table('courses')->where('course_code', 'IT201')->update(['status' => 'archived']);

        $this->assertNotContains('no-offering', $this->keys());
    }

    public function testInvitationsAboutToExpireAndExpiredAreToldApart(): void
    {
        $now = $this->day('2026-09-15');

        $soon    = $this->invitation('2026-09-17 09:00:00');
        $later   = $this->invitation('2026-09-30 09:00:00');
        $expired = $this->invitation('2026-09-14 09:00:00');
        $this->invitation('2026-09-16 09:00:00', accepted: '2026-09-15 08:00:00');
        $this->invitation('2026-09-16 09:00:00', revoked: '2026-09-15 08:00:00');
        $this->invitation('2026-09-16 09:00:00', institution: $this->other);

        $expiring = $this->attention->expiringInvitations($this->institution, $now);

        $this->assertSame([$soon], array_column($expiring, 'id'));
        $this->assertSame(1, $this->attention->expiredInvitations($this->institution, $now));
        $this->assertContains('invites-expiring', $this->keys('2026-09-15'));
        $this->assertContains('invites-expired', $this->keys('2026-09-15'));
        $this->assertNotContains($later, array_column($expiring, 'id'));
        $this->assertNotContains($expired, array_column($expiring, 'id'));
    }

    public function testAFailedRunCountsUntilAGoodOneFollowsAndIgnoresTestsAndPreviews(): void
    {
        $this->sisRun('failed', '2026-09-14 10:00:00', trigger: 'test');
        $this->sisRun('failed', '2026-09-14 10:00:00', dry: 1);
        $this->sisRun('failed', '2026-09-14 10:00:00', institution: $this->other);
        $this->assertSame(0, $this->attention->failedRuns($this->institution, $this->day('2026-09-15')));

        $this->sisRun('failed', '2026-09-14 10:00:00');
        $this->assertSame(1, $this->attention->failedRuns($this->institution, $this->day('2026-09-15')));
        $this->assertContains('sis-failed', $this->keys('2026-09-15'));

        // A good run after it clears it; one in the other direction does not.
        $this->sisRun('succeeded', '2026-09-14 12:00:00', direction: 'outbound');
        $this->assertSame(1, $this->attention->failedRuns($this->institution, $this->day('2026-09-15')));

        $this->sisRun('succeeded', '2026-09-14 12:00:00');
        $this->assertSame(0, $this->attention->failedRuns($this->institution, $this->day('2026-09-15')));
    }

    public function testAnOldFailureIsHistory(): void
    {
        $this->sisRun('failed', '2026-08-20 10:00:00');

        $this->assertSame(0, $this->attention->failedRuns($this->institution, $this->day('2026-09-15')));
    }

    public function testSubscriptionAndLimitStatesAreSaidMostUrgentFirst(): void
    {
        $plan = PeopleFactory::plan('Small', 2);
        PeopleFactory::subscribe($this->institution, $plan);
        db_connect()->table('subscriptions')->where('institution_id', $this->institution)->update(['end_date' => '2026-09-25']);

        PeopleFactory::person('student', 'S', 'One', 's1@atta.example', $this->institution);
        PeopleFactory::person('student', 'S', 'Two', 's2@atta.example', $this->institution);

        $items = $this->attention->items($this->institution, $this->day('2026-09-15'));
        $tones = array_column($items, 'tone');

        $this->assertContains('subscription', array_column($items, 'key'));
        $this->assertContains('limit-seats', array_column($items, 'key'));
        $this->assertSame('danger', $tones[0], 'a full limit comes before a warning');

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        $own  = array_map(static fn (string $t): int => $rank[$t], $tones);
        $sorted = $own;
        sort($sorted);

        $this->assertSame($sorted, $own);
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    private function subscribed(): void
    {
        PeopleFactory::subscribe($this->institution, PeopleFactory::plan('Std' . random_int(1000, 9999)));
        db_connect()->table('subscriptions')->where('institution_id', $this->institution)->update(['end_date' => '2027-06-30']);
    }

    public function testTheTermEndingIsToldOnceToTheInstitutionsAdministratorsOnly(): void
    {
        $this->subscribed();
        $rootAdmin = (int) PeopleFactory::person('admin', 'Root', 'Admin', 'root@atta.example')->id;
        $otherBoss = (int) PeopleFactory::person('institutional_admin', 'Ob', 'Boss', 'ob@attb.example', $this->other)->id;

        $first = (new NotificationTriggers())->run($this->day('2026-12-10'));

        $this->assertGreaterThan(0, $first);
        $this->assertArrayHasKey('term.ending', $this->notes($this->boss));
        $this->assertSame('institution/terms', $this->notes($this->boss)['term.ending']['link']);
        $this->assertArrayNotHasKey('term.ending', $this->notes($rootAdmin));
        $this->assertArrayNotHasKey('term.ending', $this->notes($otherBoss));

        // Again, the same day and the next: nothing new.
        $count = db_connect()->table('notifications')->countAllResults();
        (new NotificationTriggers())->run($this->day('2026-12-10'));
        (new NotificationTriggers())->run($this->day('2026-12-11'));

        $this->assertSame($count, db_connect()->table('notifications')->countAllResults());
    }

    public function testOfferingsWithoutAnInstructorAreToldOnceTheTermHasStarted(): void
    {
        $this->subscribed();
        SisFixture::course($this->institution, 'C1', 'IT101');

        (new NotificationTriggers())->run($this->day('2026-07-20'));
        $this->assertArrayNotHasKey('term.no_instructor', $this->notes($this->boss), 'before it starts');

        (new NotificationTriggers())->run($this->day('2026-08-03'));
        $note = $this->notes($this->boss)['term.no_instructor'] ?? null;

        $this->assertNotNull($note);
        $this->assertStringContainsString('1 offering has no instructor', $note['title']);
        $this->assertStringContainsString('instructor=none', (string) $note['link']);

        (new NotificationTriggers())->run($this->day('2026-08-04'));
        $this->assertSame(1, db_connect()->table('notifications')->where(['user_id' => $this->boss, 'type' => 'term.no_instructor'])->countAllResults());
    }

    public function testInvitationsAboutToExpireAreToldAndAChangedSetIsToldAgain(): void
    {
        $this->subscribed();
        $this->invitation('2026-09-17 09:00:00');

        (new NotificationTriggers())->run($this->day('2026-09-15'));
        (new NotificationTriggers())->run($this->day('2026-09-15'));

        $this->assertSame(1, db_connect()->table('notifications')->where(['user_id' => $this->boss, 'type' => 'invitation.expiring'])->countAllResults());

        $this->invitation('2026-09-18 09:00:00');
        (new NotificationTriggers())->run($this->day('2026-09-15'));

        $this->assertSame(2, db_connect()->table('notifications')->where(['user_id' => $this->boss, 'type' => 'invitation.expiring'])->countAllResults());
    }

    public function testAFailedSisRunIsToldToTheInstitutionsAdministratorsToo(): void
    {
        $rootAdmin = (int) PeopleFactory::person('admin', 'Root', 'Admin', 'root@atta.example')->id;
        $otherBoss = (int) PeopleFactory::person('institutional_admin', 'Ob', 'Boss', 'ob@attb.example', $this->other)->id;

        (new NotificationTriggers())->syncFailed($this->institution, 'ATTA College', 'The import failed.', 77, $this->day('2026-09-15'));

        $mine = $this->notes($this->boss)['sis.sync_failed'] ?? null;

        $this->assertNotNull($mine);
        $this->assertSame('institution/integrations', $mine['link']);
        $this->assertStringContainsString('admin/institutions/', (string) $this->notes($rootAdmin)['sis.sync_failed']['link']);
        $this->assertArrayNotHasKey('sis.sync_failed', $this->notes($otherBoss));
    }
}
