<?php

declare(strict_types=1);

use App\Services\Messaging\MessagingPolicy;
use App\Services\Messaging\PostContext;
use App\Services\Messaging\StartContext;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Messages permission rules, compared cell by cell with the spec table
 * (docs/superpowers/specs/2026-11-02-messages-design.md, section 3.1). Pure
 * logic: every fact the policy needs is passed in.
 *
 * @internal
 */
final class MessagingPolicyTest extends CIUnitTestCase
{
    private const ROLES = ['student', 'instructor', 'chairperson', 'dean', 'parent', 'institutional_admin', 'admin'];

    private const FACTS = ['sharedCourse', 'sameDepartment', 'inScope', 'linkedChild'];

    /**
     * Spec 3.1 written out as data: sender => recipient => the facts that
     * unlock the cell. [] means "nothing more", null means "never".
     * 'studentDmEnabled' is the institution switch (on by default).
     *
     * @var array<string, array<string, list<string>|null>>
     */
    private const TABLE = [
        'student' => [
            'student' => ['sharedCourse', 'studentDmEnabled'], 'instructor' => ['sharedCourse'], 'chairperson' => null,
            'dean' => null, 'parent' => null, 'institutional_admin' => null, 'admin' => null,
        ],
        'instructor' => [
            'student' => ['sharedCourse'], 'instructor' => ['sameDepartment'], 'chairperson' => ['sameDepartment'],
            'dean' => ['sameDepartment'], 'parent' => ['linkedChild'], 'institutional_admin' => null, 'admin' => null,
        ],
        'chairperson' => [
            'student' => ['inScope'], 'instructor' => ['inScope'], 'chairperson' => ['sameDepartment'],
            'dean' => ['sameDepartment'], 'parent' => null, 'institutional_admin' => null, 'admin' => null,
        ],
        'dean' => [
            'student' => ['inScope'], 'instructor' => ['inScope'], 'chairperson' => ['inScope'],
            'dean' => [], 'parent' => null, 'institutional_admin' => null, 'admin' => null,
        ],
        'parent' => [
            'student' => null, 'instructor' => ['linkedChild'], 'chairperson' => ['linkedChild'],
            'dean' => null, 'parent' => null, 'institutional_admin' => null, 'admin' => null,
        ],
        'institutional_admin' => [
            'student' => [], 'instructor' => [], 'chairperson' => [],
            'dean' => [], 'parent' => [], 'institutional_admin' => [], 'admin' => null,
        ],
        'admin' => [
            'student' => null, 'instructor' => null, 'chairperson' => null,
            'dean' => null, 'parent' => null, 'institutional_admin' => null, 'admin' => null,
        ],
    ];

    /**
     * @param list<string>        $facts    context facts that are true
     * @param array<string, bool> $override constructor arguments set explicitly
     */
    private static function startContext(array $facts, bool $switchOn = true, array $override = []): StartContext
    {
        return new StartContext(...array_merge([
            'sharedCourse'     => in_array('sharedCourse', $facts, true),
            'sameDepartment'   => in_array('sameDepartment', $facts, true),
            'inScope'          => in_array('inScope', $facts, true),
            'linkedChild'      => in_array('linkedChild', $facts, true),
            'studentDmEnabled' => $switchOn,
        ], $override));
    }

    /** @return iterable<string, array{string, string, list<string>, bool, bool}> */
    public static function provideStartMatrix(): iterable
    {
        foreach (self::ROLES as $sender) {
            foreach (self::ROLES as $recipient) {
                $needs = self::TABLE[$sender][$recipient];
                $name  = "{$sender} -> {$recipient}";

                if ($needs === null) {
                    // Refused even with every fact present.
                    yield "{$name}: never" => [$sender, $recipient, self::FACTS, true, false];

                    continue;
                }

                $facts = array_values(array_diff($needs, ['studentDmEnabled']));

                yield "{$name}: unlocked" => [$sender, $recipient, $facts, true, true];

                foreach ($needs as $missing) {
                    if ($missing === 'studentDmEnabled') {
                        yield "{$name}: switch off" => [$sender, $recipient, $facts, false, false];

                        continue;
                    }
                    yield "{$name}: without {$missing}" => [$sender, $recipient, array_values(array_diff($facts, [$missing])), true, false];
                }
            }
        }
    }

    /**
     * @param list<string> $facts
     */
    #[DataProvider('provideStartMatrix')]
    public function testStartMatrix(string $sender, string $recipient, array $facts, bool $switchOn, bool $expected): void
    {
        $this->assertSame($expected, (new MessagingPolicy())->canStart($sender, $recipient, self::startContext($facts, $switchOn)));
    }

    public function testTheTableCoversEveryOrderedPairOfRoles(): void
    {
        $this->assertSame(self::ROLES, array_keys(self::TABLE));

        foreach (self::TABLE as $row) {
            $this->assertSame(self::ROLES, array_keys($row));
        }
    }

    public function testNoStartAcrossInstitutionsOrFromAnInactiveAccount(): void
    {
        $policy = new MessagingPolicy();

        $this->assertTrue($policy->canStart('student', 'instructor', self::startContext(['sharedCourse'])));

        foreach (['sameInstitution', 'senderActive', 'recipientActive'] as $flag) {
            $this->assertFalse($policy->canStart('student', 'instructor', self::startContext(['sharedCourse'], true, [$flag => false])), $flag);
        }
    }

    public function testStudentDmSwitchOnlyAffectsStudentToStudent(): void
    {
        $policy = new MessagingPolicy();
        $off    = self::startContext(['sharedCourse'], false);

        $this->assertFalse($policy->canStart('student', 'student', $off));
        $this->assertTrue($policy->canStart('student', 'instructor', $off));
    }

    public function testABlockStopsEveryoneButInstructorsAndInstitutionalAdmins(): void
    {
        $policy = new MessagingPolicy();

        foreach (self::ROLES as $sender) {
            foreach (self::ROLES as $recipient) {
                $open = self::startContext(self::FACTS);

                if (! $policy->canStart($sender, $recipient, $open)) {
                    continue; // not a permitted pair, nothing for a block to change
                }
                $blocked  = self::startContext(self::FACTS, true, ['blockedByRecipient' => true]);
                $expected = in_array($recipient, ['instructor', 'institutional_admin'], true);

                $this->assertSame($expected, $policy->canStart($sender, $recipient, $blocked), "{$sender} -> {$recipient}");
            }
        }
    }

    /**
     * canPost is role-blind: whether a chairperson may reply to a parent who
     * wrote first is decided by the slice-2 resolver, not by this policy.
     */
    public function testAChairpersonCannotStartWithAParent(): void
    {
        $this->assertFalse((new MessagingPolicy())->canStart('chairperson', 'parent', self::startContext(self::FACTS)));
    }

    public function testNobodyCanStartAThreadWithThemselves(): void
    {
        $policy = new MessagingPolicy();

        foreach (self::ROLES as $sender) {
            foreach (self::ROLES as $recipient) {
                $this->assertFalse(
                    $policy->canStart($sender, $recipient, self::startContext(self::FACTS, true, ['isSelf' => true])),
                    "{$sender} -> {$recipient}",
                );
            }
        }
    }

    public function testABlockedDirectPostIsRefusedUnlessTheBlockerIsExempt(): void
    {
        $policy = new MessagingPolicy();

        $this->assertFalse($policy->canPost(new PostContext('direct', true, true, blocked: true)));
        $this->assertTrue($policy->canPost(new PostContext('direct', true, true, blocked: true, blockerIsExempt: true)));
        $this->assertTrue($policy->canPost(new PostContext('direct', true, true, blockerIsExempt: true)));
        $this->assertTrue($policy->canPost(new PostContext('direct', true, true)));
    }

    /** @return iterable<string, array{PostContext, bool}> */
    public static function providePostRules(): iterable
    {
        yield 'active participant, direct'     => [new PostContext('direct', true, true), true];
        yield 'not a participant'              => [new PostContext('direct', false, true), false];
        yield 'deactivated account'            => [new PostContext('direct', true, false), false];
        yield 'read-only institution'          => [new PostContext('course', true, true, institutionReadOnly: true), false];
        yield 'closed offering'                => [new PostContext('course', true, true, offeringClosed: true), false];
        yield 'locked course chat, student'    => [new PostContext('course', true, true, locked: true), false];
        yield 'locked course chat, instructor' => [new PostContext('course', true, true, locked: true, isCourseInstructor: true), true];
        yield 'unlocked course chat, student'  => [new PostContext('course', true, true), true];
        yield 'blocked direct thread'          => [new PostContext('direct', true, true, blocked: true), false];
        yield 'student switch off, direct'     => [new PostContext('direct', true, true, studentDmBlocked: true), false];
        yield 'group chat'                     => [new PostContext('group', true, true), true];
        yield 'parent link ended'              => [new PostContext('direct', true, true, subjectLinkLost: true), false];
    }

    #[DataProvider('providePostRules')]
    public function testPostRules(PostContext $context, bool $expected): void
    {
        $this->assertSame($expected, (new MessagingPolicy())->canPost($context));
    }

    public function testViewNeedsAnActiveParticipantInTheSameInstitution(): void
    {
        $policy = new MessagingPolicy();

        $this->assertTrue($policy->canView(true, true));
        $this->assertFalse($policy->canView(false, true));
        $this->assertFalse($policy->canView(true, false));
        $this->assertFalse($policy->canView(false, false));
    }

    public function testModerationRules(): void
    {
        $policy = new MessagingPolicy();

        $this->assertTrue($policy->canModerate('instructor', 'course', true));
        $this->assertFalse($policy->canModerate('instructor', 'course', false));
        $this->assertFalse($policy->canModerate('student', 'course', true));
        $this->assertTrue($policy->canModerate('chairperson', 'group', true));
        $this->assertTrue($policy->canModerate('dean', 'group', true));
        $this->assertFalse($policy->canModerate('dean', 'group', false));
        $this->assertTrue($policy->canModerate('institutional_admin', 'course', false));
        $this->assertTrue($policy->canModerate('institutional_admin', 'group', false));

        foreach (self::ROLES as $role) {
            $this->assertFalse($policy->canModerate($role, 'direct', true), $role);
        }
    }

    public function testModerationGapsAreRefused(): void
    {
        $policy = new MessagingPolicy();

        $this->assertFalse($policy->canModerate('chairperson', 'course', true));
        $this->assertFalse($policy->canModerate('dean', 'course', true));
        $this->assertFalse($policy->canModerate('instructor', 'group', true));
        $this->assertFalse($policy->canModerate('admin', 'course', true));
        $this->assertFalse($policy->canModerate('admin', 'group', true));
    }

    public function testNobodyModeratesAcrossInstitutions(): void
    {
        $policy = new MessagingPolicy();

        foreach (self::ROLES as $role) {
            foreach (['course', 'group', 'direct'] as $type) {
                $this->assertFalse($policy->canModerate($role, $type, true, false), "{$role} {$type}");
            }
        }
        $this->assertTrue($policy->canModerate('institutional_admin', 'course', false, true));
    }

    public function testParentFeedShowsOnlyInstructorPostsOfALinkedEnrolledChild(): void
    {
        $policy = new MessagingPolicy();

        $this->assertTrue($policy->canSeeParentFeed(true, true, 'instructor'));
        $this->assertFalse($policy->canSeeParentFeed(false, true, 'instructor'));
        $this->assertFalse($policy->canSeeParentFeed(true, false, 'instructor'));
        $this->assertFalse($policy->canSeeParentFeed(true, true, 'student'));
    }
}
