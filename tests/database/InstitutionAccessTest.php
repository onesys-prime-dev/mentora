<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionAccess;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Whether the people of an institution may change things: writable, or
 * read-only because the subscription lapsed, or past because it ended, was
 * suspended or the institution was deleted.
 *
 * @internal
 */
final class InstitutionAccessTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private InstitutionAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = new InstitutionAccess();
    }

    private function institution(string $code, string $status, ?string $deletedAt = null): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => $status, 'deleted_at' => $deletedAt]);

        return (int) db_connect()->insertID();
    }

    private function member(int ...$institutionIds): int
    {
        $user = CourseFactory::user('student');

        foreach ($institutionIds as $id) {
            db_connect()->table('institution_users')->insert(['institution_id' => $id, 'user_id' => $user->id]);
        }

        return (int) $user->id;
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function provideSingleInstitutions(): iterable
    {
        yield 'active' => ['active', null, 'writable'];
        yield 'pending' => ['pending', null, 'writable'];
        yield 'read-only' => ['read_only', null, 'read_only'];
        yield 'past' => ['past', null, 'past'];
        yield 'active but deleted' => ['active', '2026-10-01 10:00:00', 'past'];
        yield 'read-only and deleted' => ['read_only', '2026-10-01 10:00:00', 'past'];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideSingleInstitutions')]
    public function testEachInstitutionStateGivesTheRightAccess(string $status, ?string $deletedAt, string $expected): void
    {
        $user = $this->member($this->institution('ONE', $status, $deletedAt));

        $this->assertSame($expected, $this->access->stateFor($user));
        $this->assertSame($expected === 'writable', $this->access->canWrite($user));
    }

    public function testSomeoneWithNoInstitutionIsNeverRestricted(): void
    {
        $user = CourseFactory::user('admin');

        $this->assertSame('writable', $this->access->stateFor((int) $user->id));
        $this->assertTrue($this->access->canWrite((int) $user->id));
    }

    public function testAnUnknownUserIsNotRestricted(): void
    {
        $this->assertTrue($this->access->canWrite(999999));
    }

    public function testSomeoneInSeveralInstitutionsIsRestrictedOnlyIfNoneAllowsChanges(): void
    {
        $active   = $this->institution('ACT', 'active');
        $readOnly = $this->institution('RO', 'read_only');
        $past     = $this->institution('PST', 'past');

        $this->assertSame('writable', $this->access->stateFor($this->member($active, $past)));
        $this->assertSame('writable', $this->access->stateFor($this->member($readOnly, $active)));
        $this->assertSame('read_only', $this->access->stateFor($this->member($readOnly, $this->institution('RO2', 'read_only'))));
        $this->assertSame('past', $this->access->stateFor($this->member($readOnly, $past)), 'the more final state wins when none allows changes');
    }

    public function testItFollowsAStatusChangeStraightAway(): void
    {
        $institution = $this->institution('SWING', 'read_only');
        $user        = $this->member($institution);

        $this->assertFalse($this->access->canWrite($user));

        db_connect()->table('institutions')->where('id', $institution)->update(['status' => 'active']);

        $this->assertTrue($this->access->canWrite($user), 'nothing is remembered between calls');
    }

    public function testAnotherInstitutionsStateDoesNotLeak(): void
    {
        $mine  = $this->member($this->institution('MINE', 'active'));
        $this->institution('THEIRS', 'past');

        $this->assertTrue($this->access->canWrite($mine));
    }
}
