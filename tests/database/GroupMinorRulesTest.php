<?php

declare(strict_types=1);

use App\Services\Messaging\GroupService;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * D26: a minor joins a group only if every current member could message them, and nobody joins a group holding a
 * minor unless they could message that minor. Scope and blocks are otherwise checked against the person adding.
 *
 * @internal
 */
final class GroupMinorRulesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $courseOne;

    private int $courseTwo;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $d                 = DeanFactory::college('GM1');
        $this->institution = $d['institution'];
        $program           = DeanFactory::program(DeanFactory::department($d['college'], 'GMA'), 'GMP');
        $term              = DeanFactory::term($this->institution);
        $this->courseOne   = DeanFactory::offering($program, $term, 'GM101');
        $this->courseTwo   = DeanFactory::offering($program, $term, 'GM102');
    }

    /** @param list<int> $courses */
    private function student(string $name, array $courses, bool $minor = false): User
    {
        $s = PeopleFactory::person('student', $name, 'Minor', null, $this->institution);

        foreach ($courses as $course) {
            CourseFactory::enroll($course, $s);
        }

        if ($minor) {
            $this->flag($s);
        }

        return $s;
    }

    private function flag(User $s): void
    {
        db_connect()->table('institution_users')->where('user_id', $s->id)->update(['is_minor' => 1]);
    }

    private function service(): GroupService
    {
        return new GroupService();
    }

    private function active(int $id, User $u): bool
    {
        return db_connect()->table('conversation_participants')->where(['conversation_id' => $id, 'user_id' => $u->id, 'left_at' => null])->countAllResults() > 0;
    }

    public function testMinorNeedsEveryMemberToReachThem(): void
    {
        $a = $this->student('A', [$this->courseOne, $this->courseTwo]);
        $x = $this->student('X', [$this->courseOne]); // shares a course with A, not with the minor
        $m = $this->student('M', [$this->courseTwo], true);
        $adult = $this->student('Adult', [$this->courseTwo]);

        $id = (int) $this->service()->create($a, 'G', [$x->id])['id'];

        $this->assertSame(['added' => [], 'refused' => [(int) $m->id]], $this->service()->add($a, $id, [$m->id]));
        $this->assertSame([(int) $adult->id], $this->service()->add($a, $id, [$adult->id])['added'], 'an adult needs only the person adding');

        // Creating works the same way: X cannot reach the minor, so the whole group is refused.
        $this->assertNull($this->service()->create($a, 'G2', [$x->id, $m->id]));
        $this->assertNotNull($this->service()->create($a, 'G3', [$adult->id, $m->id]));
    }

    public function testCannotAddUnreachableToGroupWithMinor(): void
    {
        $a  = $this->student('A', [$this->courseOne, $this->courseTwo]);
        $m  = $this->student('M', [$this->courseTwo], true);
        $n  = $this->student('N', [$this->courseOne]); // A reaches N; the minor cannot
        $n2 = $this->student('N2', [$this->courseTwo]);

        $id = (int) $this->service()->create($a, 'G', [$m->id])['id'];

        $this->assertSame(['added' => [], 'refused' => [(int) $n->id]], $this->service()->add($a, $id, [$n->id]));
        $this->assertSame([(int) $n2->id], $this->service()->add($a, $id, [$n2->id])['added']);
    }

    public function testALaterFlagLeavesAnExistingGroupAlone(): void
    {
        $a = $this->student('A', [$this->courseOne]);
        $b = $this->student('B', [$this->courseOne]);
        $id = (int) $this->service()->create($a, 'G', [$b->id])['id'];

        $this->flag($b);

        $this->assertTrue($this->active($id, $a));
        $this->assertTrue($this->active($id, $b));
        $this->assertTrue($this->service()->rename($a, $id, 'Still open'));
        $this->assertNull(db_connect()->table('conversations')->where('id', $id)->get()->getRow('closed_at'));
    }

    private function deactivate(User $u): void
    {
        db_connect()->table('users')->where('id', $u->id)->update(['active' => 0]);
    }

    public function testADeactivatedMemberDoesNotKeepAMinorOut(): void
    {
        $a = $this->student('A', [$this->courseTwo]);
        $d = $this->student('D', [$this->courseTwo]);
        $m = $this->student('M', [$this->courseTwo], true);
        $id = (int) $this->service()->create($a, 'G', [$d->id])['id'];
        $this->deactivate($d);

        $this->assertSame([(int) $m->id], $this->service()->add($a, $id, [$m->id])['added']);
    }

    public function testADeactivatedMinorDoesNotFreezeTheGroup(): void
    {
        $a = $this->student('A', [$this->courseTwo]);
        $m = $this->student('M', [$this->courseTwo], true);
        $n = $this->student('N', [$this->courseTwo]);
        $id = (int) $this->service()->create($a, 'G', [$m->id])['id'];
        $this->deactivate($m);

        $this->assertSame([(int) $n->id], $this->service()->add($a, $id, [$n->id])['added']);
    }

    public function testARestrictedMemberDoesNotKeepAMinorOut(): void
    {
        $a = $this->student('A', [$this->courseTwo]);
        $r = $this->student('R', [$this->courseTwo]);
        $m = $this->student('M', [$this->courseTwo], true);
        $id = (int) $this->service()->create($a, 'G', [$r->id])['id'];
        db_connect()->table('message_restrictions')->insert(['institution_id' => $this->institution, 'user_id' => $r->id, 'kind' => 'no_send', 'source' => 'admin', 'starts_at' => date('Y-m-d H:i:s', strtotime('-1 hour')), 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([(int) $m->id], $this->service()->add($a, $id, [$m->id])['added']);
    }

    public function testABulkAddReadsEachMemberOnce(): void
    {
        $a   = $this->student('A', [$this->courseOne]);
        $new = array_map(fn (int $i): User => $this->student('Bulk' . $i, [$this->courseOne]), range(1, 8));
        $id  = (int) $this->service()->create($a, 'G', [$new[0]->id])['id'];

        $n = 0;
        $l = static function (Query $q) use (&$n): void {
            $n += str_contains($q->getQuery(), 'is_minor') ? 1 : 0;
        };
        Events::on('DBQuery', $l);
        $added = $this->service()->add($a, $id, array_map(static fn (User $u): int => (int) $u->id, array_slice($new, 1)))['added'];
        Events::removeListener('DBQuery', $l);

        $this->assertCount(7, $added);
        // No minors anywhere: the pair checks do not run, so lookups stay linear in the people added, not quadratic.
        $this->assertLessThan(20, $n);
    }
}
