<?php

declare(strict_types=1);

use App\Services\Grades\GradesSeen;
use CodeIgniter\Events\Events;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * What each viewer last saw of a student's grades: the storage behind the "Changed" badge.
 *
 * @internal
 */
final class GradesSeenTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $viewer;

    private int $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer  = (int) CourseFactory::user('student')->id;
        $this->student = (int) CourseFactory::user('student')->id;
    }

    protected function tearDown(): void
    {
        session()->remove(['impersonator_id', 'impersonation']);

        parent::tearDown();
    }

    private static function signature(?float $percentage, string $tag = 'In progress'): string
    {
        return GradesSeen::signature(['percentage' => $percentage, 'band' => '1.75', 'tag' => $tag, 'finalized' => false, 'signed_off' => false]);
    }

    /** @return list<int> */
    private function offerings(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = CourseFactory::offering('S' . $i);
        }

        return $ids;
    }

    public function testASignatureIsAStableHashOfTheRow(): void
    {
        $row = ['percentage' => 88.5, 'band' => null, 'tag' => 'Finalized', 'finalized' => true, 'signed_off' => false];

        $this->assertSame(hash('sha256', '88.5||Finalized|1|0'), GradesSeen::signature($row));
        $this->assertNotSame(GradesSeen::signature($row), GradesSeen::signature([...$row, 'signed_off' => true]));
    }

    public function testAFirstLookIsNotAChange(): void
    {
        [$a] = $this->offerings(1);

        $this->assertSame([$a => false], (new GradesSeen())->changed($this->viewer, $this->student, [$a => self::signature(80.0)]));
    }

    public function testTheSameGradeIsNotAChange(): void
    {
        [$a] = $this->offerings(1);
        $seen = new GradesSeen();

        $seen->remember($this->viewer, $this->student, [$a => self::signature(80.0)]);

        $this->assertSame([$a => false], $seen->changed($this->viewer, $this->student, [$a => self::signature(80.0)]));
    }

    public function testAChangedGradeIsAChangeOnce(): void
    {
        [$a, $b] = $this->offerings(2);
        $seen    = new GradesSeen();

        $seen->remember($this->viewer, $this->student, [$a => self::signature(80.0), $b => self::signature(70.0)]);

        $now = [$a => self::signature(85.0), $b => self::signature(70.0)];
        $this->assertSame([$a => true, $b => false], $seen->changed($this->viewer, $this->student, $now));

        $seen->remember($this->viewer, $this->student, $now);
        $this->assertSame([$a => false, $b => false], $seen->changed($this->viewer, $this->student, $now));
        $this->assertSame(2, db_connect()->table('grades_seen')->countAllResults(), 'updated in place, not added');
    }

    public function testEachViewerHasTheirOwnMemory(): void
    {
        [$a]   = $this->offerings(1);
        $other = (int) CourseFactory::user('parent')->id;
        $seen  = new GradesSeen();

        $seen->remember($this->viewer, $this->student, [$a => self::signature(80.0)]);

        $this->assertSame([$a => false], $seen->changed($other, $this->student, [$a => self::signature(90.0)]));
    }

    public function testViewingAsSomeoneRemembersNothing(): void
    {
        [$a] = $this->offerings(1);

        session()->set('impersonator_id', 1);
        session()->set('impersonation', ['target_id' => $this->viewer, 'reason' => 'Checking the grades', 'started_at' => time(), 'expires_at' => time() + 600]);

        (new GradesSeen())->remember($this->viewer, $this->student, [$a => self::signature(80.0)]);

        $this->assertSame(0, db_connect()->table('grades_seen')->countAllResults());
    }

    public function testNothingToRememberIsNoQuery(): void
    {
        $queries  = 0;
        $listener = static function () use (&$queries): void {
            $queries++;
        };

        Events::on('DBQuery', $listener);
        (new GradesSeen())->remember($this->viewer, $this->student, []);
        $this->assertSame([], (new GradesSeen())->changed($this->viewer, $this->student, []));
        Events::removeListener('DBQuery', $listener);

        $this->assertSame(0, $queries);
    }

    public function testOneQueryEachWhateverTheCourses(): void
    {
        $seen  = new GradesSeen();
        $costs = [];

        foreach ([2, 10] as $count) {
            $signatures = [];

            foreach ($this->offerings($count) as $id) {
                $signatures[$id] = self::signature(50.0 + $id);
            }

            $queries  = 0;
            $listener = static function () use (&$queries): void {
                $queries++;
            };

            Events::on('DBQuery', $listener);
            $seen->remember($this->viewer, $this->student, $signatures);
            $afterRemember = $queries;
            $seen->changed($this->viewer, $this->student, $signatures);
            Events::removeListener('DBQuery', $listener);

            $costs[$count] = [$afterRemember, $queries - $afterRemember];
        }

        $this->assertSame([1, 1], $costs[2]);
        $this->assertSame([1, 1], $costs[10]);
    }
}
