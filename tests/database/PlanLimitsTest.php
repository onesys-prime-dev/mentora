<?php

declare(strict_types=1);

use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * An institution's plan limits: what the plan allows, how much is used
 * (student seats, uploaded storage, AI generations this term), how full each
 * is, and whether there is room for more.
 *
 * @internal
 */
final class PlanLimitsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const MB = 1048576;

    private PlanLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limits = new PlanLimits();
    }

    private function institution(string $code = 'ACME'): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => 'active']);

        return (int) db_connect()->insertID();
    }

    /** Puts the institution on a plan with the given limits (null = unlimited). */
    private function subscribe(int $institution, ?int $seats = null, ?int $storageMb = null, ?int $ai = null, string $name = 'Gold'): void
    {
        db_connect()->table('plans')->insert(['name' => $name . random_int(1000, 9999), 'seat_limit' => $seats, 'storage_limit_mb' => $storageMb, 'ai_generation_limit' => $ai, 'status' => 'active']);
        $plan = (int) db_connect()->insertID();

        db_connect()->table('subscriptions')->insert(['institution_id' => $institution, 'plan_id' => $plan, 'start_date' => '2025-01-01', 'end_date' => '2099-12-31']);
    }

    private function member(int $institution, string $role = 'student', bool $active = true): User
    {
        $user = CourseFactory::user($role);
        db_connect()->table('institution_users')->insert(['institution_id' => $institution, 'user_id' => $user->id]);

        if (! $active) {
            db_connect()->table('users')->where('id', $user->id)->update(['active' => 0]);
        }

        return $user;
    }

    private function file(User $owner, int $bytes, string $status = 'active'): void
    {
        db_connect()->table('files')->insert([
            'owner_user_id' => $owner->id, 'original_filename' => 'a.pdf', 'stored_filename' => uniqid('f', true) . '.pdf',
            'mime_type' => 'application/pdf', 'file_size' => $bytes, 'storage_key' => 'x/' . uniqid('', true), 'status' => $status,
        ]);
    }

    private function term(int $institution, string $start, string $end): void
    {
        db_connect()->table('academic_terms')->insert(['institution_id' => $institution, 'name' => "Term {$start}", 'academic_year' => '2026', 'start_date' => $start, 'end_date' => $end]);
    }

    /**
     * An AI batch in the institution of $offering.
     *
     * @return int the batch id
     */
    private function batch(int $offering, User $creator, string $status, int $requested, int $generated, string $createdAt): int
    {
        $assessment = CourseFactory::assessment($offering, $creator);
        $course     = (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRowArray()['course_id'];

        db_connect()->table('ai_generation_batches')->insert([
            'assessment_id' => $assessment, 'course_id' => $course, 'source_type' => 'topic', 'topic' => 'x',
            'requested_count' => $requested, 'generated_count' => $generated, 'status' => $status, 'created_by' => $creator->id, 'created_at' => $createdAt,
        ]);

        return (int) db_connect()->insertID();
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

    /*
    |--------------------------------------------------------------------------
    | The plan's limits
    |--------------------------------------------------------------------------
    */

    public function testTheLimitsComeFromThePlanTheInstitutionIsOn(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, 500, 20480, 200, 'Standard');

        $limits = $this->limits->limitsFor($institution);

        $this->assertStringStartsWith('Standard', (string) $limits['plan']);
        $this->assertSame(500, $limits['seats']);
        $this->assertSame(20480, $limits['storage_mb']);
        $this->assertSame(200, $limits['ai']);
    }

    public function testEmptyLimitsOnThePlanMeanUnlimited(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, 100, null, null);

        $limits = $this->limits->limitsFor($institution);

        $this->assertSame(100, $limits['seats']);
        $this->assertNull($limits['storage_mb']);
        $this->assertNull($limits['ai']);
    }

    public function testNoSubscriptionMeansNoLimits(): void
    {
        $this->assertSame(['plan' => null, 'seats' => null, 'storage_mb' => null, 'ai' => null], $this->limits->limitsFor($this->institution()));
    }

    /*
    |--------------------------------------------------------------------------
    | Seats
    |--------------------------------------------------------------------------
    */

    public function testSeatsCountActiveStudentsOfThisInstitutionOnly(): void
    {
        $mine   = $this->institution('MINE');
        $theirs = $this->institution('THEIRS');

        $this->member($mine);
        $this->member($mine);
        $this->member($mine, 'student', false);   // deactivated: no seat
        $this->member($mine, 'instructor');       // not a student
        $this->member($mine, 'chairperson');
        $this->member($theirs);                    // another institution

        $this->assertSame(2, $this->limits->usage($mine)['seats']);
        $this->assertSame(1, $this->limits->usage($theirs)['seats']);
    }

    public function testParentsNeverTakeASeat(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, 1);
        $this->member($institution);

        $this->member($institution, 'parent');
        $this->member($institution, 'parent');

        $this->assertSame(1, $this->limits->usage($institution)['seats']);
        $this->assertNotNull($this->limits->seatBlock($institution), 'the one student seat is used');
        $this->assertNull($this->limits->seatBlock($institution, 0), 'parents alone never block');
    }

    public function testAStudentInTwoInstitutionsTakesASeatInEach(): void
    {
        $a = $this->institution('A');
        $b = $this->institution('B');

        $student = $this->member($a);
        db_connect()->table('institution_users')->insert(['institution_id' => $b, 'user_id' => $student->id]);

        $this->assertSame(1, $this->limits->usage($a)['seats']);
        $this->assertSame(1, $this->limits->usage($b)['seats']);
    }

    public function testASeatIsOnlyCountedOnceEvenIfMembershipIsRepeated(): void
    {
        $institution = $this->institution();
        $student     = $this->member($institution);
        db_connect()->table('institution_users')->insert(['institution_id' => $institution, 'user_id' => $student->id]);

        $this->assertSame(1, $this->limits->usage($institution)['seats']);
    }

    public function testNewStudentsAreAllowedUpToTheSeatLimitAndRefusedBeyondIt(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, 3);
        $this->member($institution);
        $this->member($institution);

        $this->assertNull($this->limits->seatBlock($institution), 'the third seat is free');
        $this->assertNull($this->limits->seatBlock($institution, 1));
        $this->assertNotNull($this->limits->seatBlock($institution, 2), 'two more would be one too many');

        $this->member($institution);

        $message = $this->limits->seatBlock($institution);

        $this->assertNotNull($message, 'now they are all used');
        $this->assertStringContainsString('All 3 student seats', $message);
    }

    public function testNoSeatLimitMeansNoBlock(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, null);
        $this->member($institution);

        $this->assertNull($this->limits->seatBlock($institution, 1000));
        $this->assertNull($this->limits->seatBlock($this->institution('NOSUB'), 1000), 'and so for an institution with no subscription');
    }

    public function testSeatsFreedByDeactivatingAStudentCanBeUsedAgain(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, 1);
        $student = $this->member($institution);

        $this->assertNotNull($this->limits->seatBlock($institution));

        db_connect()->table('users')->where('id', $student->id)->update(['active' => 0]);

        $this->assertNull($this->limits->seatBlock($institution));
    }

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    public function testStorageAddsUpTheFilesOfThisInstitutionsPeople(): void
    {
        $mine   = $this->institution('MINE');
        $theirs = $this->institution('THEIRS');

        $a = $this->member($mine);
        $b = $this->member($mine, 'instructor');
        $c = $this->member($theirs);

        $this->file($a, 1000);
        $this->file($b, 2000);
        $this->file($c, 99999);                       // another institution
        $this->file($a, 500, 'archived');             // archived files do not count
        $this->file(CourseFactory::user('student'), 7777); // belongs to no institution

        $this->assertSame(3000, $this->limits->usage($mine)['storage_bytes']);
        $this->assertSame(99999, $this->limits->usage($theirs)['storage_bytes']);
    }

    public function testNoFilesMeansZero(): void
    {
        $this->assertSame(0, $this->limits->usage($this->institution())['storage_bytes']);
    }

    public function testAnUploadThatJustFitsIsAllowedAndOneByteMoreIsNot(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, null, 10);
        $user = $this->member($institution);
        $this->file($user, 4 * self::MB);

        $this->assertNull($this->limits->storageBlock($user, 6 * self::MB), 'exactly fills it');
        $this->assertNotNull($this->limits->storageBlock($user, 6 * self::MB + 1));
    }

    public function testWhenStorageIsFullEveryFurtherUploadIsRefusedWithAHelpfulMessage(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, null, 5, null, 'Basic');
        $user = $this->member($institution);
        $this->file($user, 5 * self::MB);

        $message = $this->limits->storageBlock($user, 1);

        $this->assertNotNull($message);
        $this->assertStringContainsString('5 of 5 MB', $message);
        $this->assertStringContainsString('Basic', $message);
    }

    public function testNoStorageLimitNoSubscriptionNoInstitutionOrNoUserMeansNoBlock(): void
    {
        $unlimited = $this->institution('UNL');
        $this->subscribe($unlimited, 5, null);
        $user = $this->member($unlimited);
        $this->file($user, 500 * self::MB);

        $this->assertNull($this->limits->storageBlock($user, 500 * self::MB));
        $this->assertNull($this->limits->storageBlock($this->member($this->institution('NOSUB')), 500 * self::MB));
        $this->assertNull($this->limits->storageBlock(CourseFactory::user('admin'), 500 * self::MB), 'a system administrator has no institution');
        $this->assertNull($this->limits->storageBlock(null, 500 * self::MB), 'and nobody signed in is not limited here');
    }

    public function testAnotherInstitutionsFilesDoNotUseUpMyStorage(): void
    {
        $mine   = $this->institution('MINE');
        $theirs = $this->institution('THEIRS');
        $this->subscribe($mine, null, 1);
        $me = $this->member($mine);
        $this->file($this->member($theirs), 50 * self::MB);

        $this->assertNull($this->limits->storageBlock($me, 100));
    }

    /*
    |--------------------------------------------------------------------------
    | Which institution a user belongs to
    |--------------------------------------------------------------------------
    */

    public function testTheInstitutionOfAUser(): void
    {
        $institution = $this->institution();

        $this->assertSame($institution, $this->limits->institutionOf((int) $this->member($institution)->id));
        $this->assertNull($this->limits->institutionOf((int) CourseFactory::user('admin')->id));
        $this->assertNull($this->limits->institutionOf(999999));
    }

    public function testADeletedInstitutionIsNotWhereSomeoneBelongs(): void
    {
        $institution = $this->institution('GONE');
        $user        = $this->member($institution);
        db_connect()->table('institutions')->where('id', $institution)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertNull($this->limits->institutionOf((int) $user->id));
    }

    /*
    |--------------------------------------------------------------------------
    | AI generations
    |--------------------------------------------------------------------------
    */

    public function testTheWindowStartsWhenTheCurrentTermBegan(): void
    {
        $institution = $this->institution();
        $this->term($institution, '2026-01-10', '2026-05-30');
        $this->term($institution, '2026-08-01', '2026-12-20');

        $this->assertSame('2026-08-01', $this->limits->aiWindowStart($institution, new DateTimeImmutable('2026-10-12')));
        $this->assertSame('2026-01-10', $this->limits->aiWindowStart($institution, new DateTimeImmutable('2026-03-01')));
        $this->assertSame('2026-08-01', $this->limits->aiWindowStart($institution, new DateTimeImmutable('2026-08-01')), 'the first day counts');
        $this->assertSame('2026-08-01', $this->limits->aiWindowStart($institution, new DateTimeImmutable('2026-12-20')), 'and the last');
    }

    public function testBetweenTermsTheMostRecentOneStillDefinesTheWindow(): void
    {
        $institution = $this->institution();
        $this->term($institution, '2026-01-10', '2026-05-30');
        $this->term($institution, '2026-08-01', '2026-12-20');

        $this->assertSame('2026-01-10', $this->limits->aiWindowStart($institution, new DateTimeImmutable('2026-06-15')));
    }

    public function testBeforeAnyTermHasBegunTheEarliestUpcomingOneIsUsed(): void
    {
        $institution = $this->institution();
        $this->term($institution, '2027-01-10', '2027-05-30');

        $this->assertSame('2027-01-10', $this->limits->aiWindowStart($institution, new DateTimeImmutable('2026-10-12')));
    }

    public function testWithNoTermsTheWindowIsTheCalendarYear(): void
    {
        $this->assertSame('2026-01-01', $this->limits->aiWindowStart($this->institution(), new DateTimeImmutable('2026-10-12')));
    }

    public function testAnotherInstitutionsTermsDoNotCount(): void
    {
        $mine = $this->institution('MINE');
        $this->term($this->institution('OTHER'), '2026-09-01', '2026-12-20');

        $this->assertSame('2026-01-01', $this->limits->aiWindowStart($mine, new DateTimeImmutable('2026-10-12')));
    }

    public function testGenerationsCountWhatWasMadeOrRequestedSinceTheTermBegan(): void
    {
        $offering    = CourseFactory::offering();
        $institution = $this->institutionOf($offering);
        $teacher     = $this->member($institution, 'instructor');
        $this->term($institution, '2026-08-01', '2026-12-20');
        $now = new DateTimeImmutable('2026-10-12');

        $this->batch($offering, $teacher, 'completed', 20, 18, '2026-09-01 10:00:00');   // 18 made
        $this->batch($offering, $teacher, 'generating', 10, 4, '2026-10-01 10:00:00');   // 10 asked for, still going
        $this->batch($offering, $teacher, 'failed', 30, 0, '2026-10-02 10:00:00');       // failed: free
        $this->batch($offering, $teacher, 'completed', 40, 40, '2026-07-31 23:59:59');   // last term

        $this->assertSame(28, $this->limits->usage($institution, $now)['ai']);
    }

    public function testAnotherInstitutionsGenerationsAreNotCounted(): void
    {
        $mineOffering   = CourseFactory::offering();
        $theirsOffering = CourseFactory::offering();
        $mine           = $this->institutionOf($mineOffering);
        $theirs         = $this->institutionOf($theirsOffering);
        $this->term($mine, '2026-01-01', '2026-12-31');
        $this->term($theirs, '2026-01-01', '2026-12-31');

        $this->batch($theirsOffering, $this->member($theirs, 'instructor'), 'completed', 50, 50, '2026-09-01 10:00:00');

        $this->assertSame(0, $this->limits->usage($mine, new DateTimeImmutable('2026-10-12'))['ai']);
        $this->assertSame(50, $this->limits->usage($theirs, new DateTimeImmutable('2026-10-12'))['ai']);
    }

    public function testGenerationIsAllowedUpToTheCapAndRefusedBeyondIt(): void
    {
        $offering    = CourseFactory::offering();
        $institution = $this->institutionOf($offering);
        $teacher     = $this->member($institution, 'instructor');
        $this->subscribe($institution, null, null, 100);
        $this->term($institution, '2020-01-01', '2099-12-31');
        $this->batch($offering, $teacher, 'completed', 90, 90, date('Y-m-d H:i:s'));

        $this->assertNull($this->limits->generationBlock($teacher, 10), 'exactly uses the last 10');

        $message = $this->limits->generationBlock($teacher, 11);

        $this->assertNotNull($message);
        $this->assertStringContainsString('10 AI question generations left', $message);
        $this->assertStringContainsString('asks for 11', $message);
    }

    public function testWhenTheAllowanceIsUsedUpTheMessageSaysSo(): void
    {
        $offering    = CourseFactory::offering();
        $institution = $this->institutionOf($offering);
        $teacher     = $this->member($institution, 'instructor');
        $this->subscribe($institution, null, null, 50, 'Basic');
        $this->term($institution, '2020-01-01', '2099-12-31');
        $this->batch($offering, $teacher, 'completed', 50, 50, date('Y-m-d H:i:s'));

        $message = $this->limits->generationBlock($teacher, 1);

        $this->assertStringContainsString('used all 50 AI question generations', (string) $message);
        $this->assertStringContainsString('Basic', (string) $message);
    }

    public function testNoAiLimitNoInstitutionOrNoUserMeansNoBlock(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, 5, null, null);
        $teacher = $this->member($institution, 'instructor');

        $this->assertNull($this->limits->generationBlock($teacher, 100000));
        $this->assertNull($this->limits->generationBlock(CourseFactory::user('admin'), 100000));
        $this->assertNull($this->limits->generationBlock(null, 100000));
    }

    /*
    |--------------------------------------------------------------------------
    | The report
    |--------------------------------------------------------------------------
    */

    public function testTheReportGivesEachLimitItsUseAndState(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, 10, 100, null);

        for ($i = 0; $i < 9; $i++) {
            $this->member($institution);        // 9 of 10 seats
        }

        $this->file($this->member($institution, 'instructor'), 100 * self::MB); // 100 of 100 MB

        $report = $this->limits->report($institution, new DateTimeImmutable('2026-10-12'));

        $this->assertSame(['used' => 9, 'limit' => 10, 'percent' => 90, 'state' => 'warning'], $report['seats']);
        $this->assertSame('full', $report['storage']['state']);
        $this->assertSame(100, $report['storage']['used_mb']);
        $this->assertSame(100, $report['storage']['limit_mb']);
        $this->assertSame(['used' => 0, 'limit' => null, 'percent' => null, 'state' => 'unlimited'], $report['ai']);
    }

    public function testStorageUsedIsShownRoundedUpToTheNextMegabyte(): void
    {
        $institution = $this->institution();
        $this->subscribe($institution, null, 100, null);
        $this->file($this->member($institution), 1);

        $this->assertSame(1, $this->limits->report($institution)['storage']['used_mb']);
    }

    public function testAnInstitutionWithNoSubscriptionReportsEverythingUnlimited(): void
    {
        $report = $this->limits->report($this->institution());

        $this->assertNull($report['plan']);
        foreach (['seats', 'storage', 'ai'] as $limit) {
            $this->assertSame('unlimited', $report[$limit]['state']);
        }
    }
}
