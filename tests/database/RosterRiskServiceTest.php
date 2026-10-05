<?php

declare(strict_types=1);

use App\Services\Courses\RosterRiskService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Who counts as at risk: silent for 14 days (or never active) comes first,
 * then active students under 50% of the published items. Students who are
 * keeping up, unenrolled students, and courses with nothing published are
 * never listed.
 *
 * @internal
 */
final class RosterRiskServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private RosterRiskService $risk;
    private int $offeringId;

    /** @var list<int> ten published items */
    private array $items;

    protected function setUp(): void
    {
        parent::setUp();

        $this->risk = new RosterRiskService();
        $this->offeringId = CourseFactory::offering();
        $unit = CourseFactory::unit(CourseFactory::module($this->offeringId));
        $this->items = CourseFactory::items($unit, array_fill(0, 10, []));
    }

    private function student(string $status = 'enrolled', ?int $offeringId = null): User
    {
        $student = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert([
            'user_id' => $student->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace' . $student->id,
        ]);
        CourseFactory::enroll($offeringId ?? $this->offeringId, $student, $status);

        return $student;
    }

    /** Completes the first $count items, the last one $daysAgo days ago. */
    private function complete(User $student, int $count, int $daysAgo): void
    {
        for ($i = 0; $i < $count; $i++) {
            CourseFactory::progress($this->items[$i], $student);
        }

        db_connect()->table('learning_completions')
            ->where('student_user_id', $student->id)
            ->update(['completed_at' => date('Y-m-d H:i:s', time() - $daysAgo * 86400 - 3600)]);
    }

    private function find(array $list, User $student): ?array
    {
        foreach ($list as $row) {
            if ($row['student_id'] === (int) $student->id) {
                return $row;
            }
        }

        return null;
    }

    public function testNoOfferingsMeansNoRisk(): void
    {
        $this->assertSame([], $this->risk->atRisk([]));
    }

    public function testAStudentKeepingUpIsNotListed(): void
    {
        $student = $this->student();
        $this->complete($student, 6, 1);

        $this->assertNull($this->find($this->risk->atRisk([$this->offeringId]), $student));
    }

    public function testAStudentWhoNeverDidAnythingIsInactive(): void
    {
        $student = $this->student();

        $row = $this->find($this->risk->atRisk([$this->offeringId]), $student);

        $this->assertSame('inactive', $row['reason']);
        $this->assertNull($row['days_since']);
        $this->assertSame(0, $row['progress']);
        $this->assertSame($this->offeringId, $row['offering_id']);
    }

    public function testSilenceForFourteenDaysIsInactiveEvenWithGoodProgress(): void
    {
        $student = $this->student();
        $this->complete($student, 9, 14);

        $row = $this->find($this->risk->atRisk([$this->offeringId]), $student);

        $this->assertSame('inactive', $row['reason'], 'silence outranks a high percentage');
        $this->assertSame(14, $row['days_since']);
        $this->assertSame(90, $row['progress']);
    }

    public function testThirteenDaysOfSilenceIsStillActive(): void
    {
        $student = $this->student();
        $this->complete($student, 9, 13);

        $this->assertNull($this->find($this->risk->atRisk([$this->offeringId]), $student));
    }

    public function testActiveButUnderHalfwayIsBehind(): void
    {
        $student = $this->student();
        $this->complete($student, 4, 2);

        $row = $this->find($this->risk->atRisk([$this->offeringId]), $student);

        $this->assertSame('behind', $row['reason']);
        $this->assertSame(40, $row['progress']);
        $this->assertSame(2, $row['days_since']);
    }

    public function testExactlyHalfwayIsNotBehind(): void
    {
        $student = $this->student();
        $this->complete($student, 5, 2);

        $this->assertNull($this->find($this->risk->atRisk([$this->offeringId]), $student));
    }

    public function testTheNameIsTheProfileName(): void
    {
        $student = $this->student();

        $row = $this->find($this->risk->atRisk([$this->offeringId]), $student);

        $this->assertSame('Ada Lovelace' . $student->id, $row['name']);
    }

    public function testOnlyEnrolledStudentsAreConsidered(): void
    {
        $dropped = $this->student('dropped');

        $this->assertNull($this->find($this->risk->atRisk([$this->offeringId]), $dropped));
    }

    public function testACourseWithNothingPublishedHasNobodyAtRisk(): void
    {
        $empty = CourseFactory::offering('B');
        $unit = CourseFactory::unit(CourseFactory::module($empty));
        CourseFactory::items($unit, [['status' => 'draft']]);
        $this->student('enrolled', $empty);

        $this->assertSame([], $this->risk->atRisk([$empty]));
    }

    public function testUnpublishedItemsAreNotInTheDenominator(): void
    {
        $offering = CourseFactory::offering('C');
        $unit = CourseFactory::unit(CourseFactory::module($offering));
        $items = CourseFactory::items($unit, [[], [], ['status' => 'draft'], ['status' => 'draft']]);
        $student = $this->student('enrolled', $offering);
        CourseFactory::progress($items[0], $student);

        $row = $this->find($this->risk->atRisk([$offering]), $student);

        $this->assertNull($row, '1 of 2 published items is exactly 50%, not behind');
    }

    public function testListIsOrderedLongestSilentFirstWithNeverActiveLeading(): void
    {
        $week = $this->student();
        $this->complete($week, 4, 7);
        $month = $this->student();
        $this->complete($month, 4, 30);
        $never = $this->student();

        $ids = array_column($this->risk->atRisk([$this->offeringId]), 'student_id');

        $this->assertSame([(int) $never->id, (int) $month->id, (int) $week->id], $ids);
    }

    public function testSeveralOfferingsAreJudgedSeparately(): void
    {
        $other = CourseFactory::offering('D');
        $unit = CourseFactory::unit(CourseFactory::module($other));
        $otherItems = CourseFactory::items($unit, [[], []]);
        $student = $this->student('enrolled', $other);
        CourseFactory::progress($otherItems[0], $student);
        CourseFactory::progress($otherItems[1], $student);

        $this->assertNull($this->find($this->risk->atRisk([$this->offeringId, $other]), $student));
    }
}
