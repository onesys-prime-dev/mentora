<?php

declare(strict_types=1);

use App\Services\Profiles\AvatarData;
use App\Services\Profiles\ProfileContextResolver;
use App\Services\Profiles\ProfilePolicy;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * visibleIds() answers, for a whole list at once, what for() + ProfilePolicy
 * answer one subject at a time, in a fixed number of queries.
 *
 * @internal
 */
final class ProfileContextResolverTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $inst;

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->inst = PeopleFactory::institution('PCR1');
        $other      = PeopleFactory::institution('PCR2');
        $db         = db_connect();
        $p          = fn (string $role, string $first, ?int $inst = null): User => PeopleFactory::person($role, $first, 'Matrix', null, $inst === null ? $this->inst : $inst);

        $this->people = [
            'instructor'    => $p('instructor', 'Ina'),
            'student'       => $p('student', 'Stu'),
            'child'         => $p('student', 'Kid'),
            'parent'        => $p('parent', 'Pat'),
            'otherParent'   => $p('parent', 'Opa'),
            'admin'         => $p('institutional_admin', 'Ada'),
            'chair'         => $p('chairperson', 'Cha'),
            'foreign'       => $p('student', 'Far', $other),
            'foreignStaff'  => $p('instructor', 'Fin', $other),
            'deactivated'   => $p('student', 'Dee'),
            'switchedOff'   => $p('instructor', 'Off'),
            'sysAdmin'      => $p('admin', 'Sys'),
            'staffStudent'  => $p('instructor', 'Duo'),
            'staffParent'   => $p('instructor', 'Tri'),
            'staleParent'   => $p('parent', 'Old'),
            'deleted'       => $p('student', 'Del'),
        ];

        $group = fn (string $who, string $g) => $db->table('auth_groups_users')->insert(['user_id' => $this->people[$who]->id, 'group' => $g, 'created_at' => date('Y-m-d H:i:s')]);
        $link  = fn (string $parent, string $child, ?int $inst = null) => $db->table('parent_student_links')->insert(['institution_id' => $inst ?? $this->inst, 'parent_user_id' => $this->people[$parent]->id, 'student_user_id' => $this->people[$child]->id, 'created_at' => date('Y-m-d H:i:s')]);

        $group('staffStudent', 'student');          // D31: a subject who is both
        $group('staffParent', 'parent');            // D31: a viewer who is both
        $link('parent', 'child');
        $link('staffParent', 'student');
        $link('staleParent', 'child', $other);      // recorded against another institution: not live

        $db->table('user_account_controls')->insert(['user_id' => $this->people['deactivated']->id, 'deactivated_at' => date('Y-m-d H:i:s')]);
        $db->table('users')->where('id', $this->people['switchedOff']->id)->update(['active' => 0]);
        $db->table('users')->where('id', $this->people['deleted']->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** @return list<int> */
    private function allIds(): array
    {
        return [...array_map(static fn (User $u): int => (int) $u->id, array_values($this->people)), 999999];
    }

    public function testVisibleIdsAgreesWithTheSingleSubjectPathForEveryPair(): void
    {
        $resolver = new ProfileContextResolver();
        $policy   = new ProfilePolicy();
        $ids      = $this->allIds();
        $seen     = 0;

        foreach ($this->people as $name => $viewer) {
            $expected = array_values(array_filter($ids, static function (int $id) use ($resolver, $policy, $viewer): bool {
                $c = $resolver->for($viewer, $id);

                return $c !== null && $policy->fields($c) !== [];
            }));

            $got = $resolver->visibleIds($viewer, [...$ids, $ids[0]]); // a repeated id is answered once
            $this->assertSame($expected, $got, "viewer {$name}");
            $seen += count($got);
        }

        $this->assertGreaterThan(0, $seen);
    }

    public function testTheMatrixCoversVisibleAndHiddenCases(): void
    {
        $r   = new ProfileContextResolver();
        $id  = fn (string $who): int => (int) $this->people[$who]->id;
        $sees = fn (string $viewer, string $subject): bool => in_array($id($subject), $r->visibleIds($this->people[$viewer], $this->allIds()), true);

        $this->assertTrue($sees('parent', 'child'));
        $this->assertFalse($sees('parent', 'student'), 'not their child');
        $this->assertFalse($sees('staleParent', 'child'), 'link in another institution');
        $this->assertTrue($sees('staffParent', 'student'));
        $this->assertTrue($sees('student', 'instructor'));
        $this->assertFalse($sees('student', 'parent'));
        $this->assertTrue($sees('child', 'parent'));
        $this->assertFalse($sees('instructor', 'foreign'));
        $this->assertFalse($sees('instructor', 'sysAdmin'));
        $this->assertTrue($sees('instructor', 'deactivated'), 'basics only, but still visible');
        $this->assertTrue($sees('student', 'switchedOff'));
        $this->assertFalse($sees('instructor', 'deleted'));
        $this->assertTrue($sees('sysAdmin', 'sysAdmin'), 'self');
        $this->assertFalse($sees('sysAdmin', 'student'));
        $this->assertTrue($sees('student', 'staffStudent'));
        $this->assertSame([], $r->visibleIds($this->people['student'], []));
    }

    public function testVisibleIdsUsesAFixedNumberOfQueries(): void
    {
        $ids = [];
        for ($i = 0; $i < 40; $i++) {
            $ids[] = (int) PeopleFactory::person($i % 2 ? 'student' : 'instructor', 'Many', 'People', null, $this->inst)->id;
        }

        $queries = 0;
        $l       = static function () use (&$queries): void {
            $queries++;
        };
        Events::on('DBQuery', $l);
        $visible = (new ProfileContextResolver())->visibleIds($this->people['parent'], $ids);
        Events::removeListener('DBQuery', $l);

        $this->assertLessThanOrEqual(8, $queries);
        $this->assertCount(20, $visible, 'a parent sees the staff, not other children');
    }

    public function testForViewerPairsAvatarRowsWithInteractivity(): void
    {
        $student = $this->people['student'];
        $out     = (new AvatarData())->forViewer($student, [(int) $this->people['instructor']->id, (int) $this->people['parent']->id, (int) $student->id]);

        $this->assertTrue($out[(int) $this->people['instructor']->id]['interactive']);
        $this->assertFalse($out[(int) $this->people['parent']->id]['interactive']);
        $this->assertTrue($out[(int) $student->id]['interactive'], 'their own avatar opens their card');
        $this->assertSame('Ina Matrix', $out[(int) $this->people['instructor']->id]['person']['name']);
    }
}
