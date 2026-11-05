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

    /**
     * @return array<string, array{string, string, bool, bool, bool, bool, bool}>
     */
    public static function provideControlsMatrix(): array
    {
        // role, type, teachesCourse, inScope, createdGroup, sameInstitution, expected. Only the institution's
        // administrator manages a chat's controls, whatever the other facts say.
        return [
            'instructor of the course'          => ['instructor', 'course', true, true, true, true, false],
            'chairperson in scope on course'    => ['chairperson', 'course', false, true, false, true, false],
            'dean in scope on course'           => ['dean', 'course', false, true, false, true, false],
            'chairperson created the group'     => ['chairperson', 'group', false, true, true, true, false],
            'dean created the group'            => ['dean', 'group', false, false, true, true, false],
            'student course'                    => ['student', 'course', true, true, true, true, false],
            'parent group'                      => ['parent', 'group', true, true, true, true, false],
            'institutional admin course'        => ['institutional_admin', 'course', false, false, false, true, true],
            'institutional admin group'         => ['institutional_admin', 'group', false, false, false, true, true],
            'institutional admin direct'        => ['institutional_admin', 'direct', true, true, true, true, false],
            'other institution admin'           => ['institutional_admin', 'course', true, true, true, false, false],
        ];
    }

    #[DataProvider('provideControlsMatrix')]
    public function testWhoMayManageControls(string $role, string $type, bool $teaches, bool $inScope, bool $created, bool $same, bool $expected): void
    {
        $this->assertSame($expected, (new MessagingPolicy())->canManageControls($role, $type, $teaches, $inScope, $created, $same));
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function providePinMatrix(): array
    {
        return [
            'course moderator'     => ['course', true, true],
            'course non-moderator' => ['course', false, false],
            'direct non-moderator' => ['direct', false, true],
            'direct moderator'     => ['direct', true, true],
            'group non-moderator'  => ['group', false, true],
            'group moderator'      => ['group', true, true],
        ];
    }

    #[DataProvider('providePinMatrix')]
    public function testWhoMayPinAMessage(string $type, bool $isModerator, bool $expected): void
    {
        $this->assertSame($expected, (new MessagingPolicy())->canPinMessage($type, $isModerator));
    }

    /**
     * @return array<string, array{PostContext, bool}>
     */
    public static function provideReactionRules(): array
    {
        return [
            'locked course chat, student'  => [new PostContext('course', true, true, locked: true), true],
            'unlocked course chat'         => [new PostContext('course', true, true), true],
            'locked course chat, posting'  => [new PostContext('course', true, true, locked: true), true],
            'not a participant'            => [new PostContext('course', false, true), false],
            'inactive account'             => [new PostContext('group', true, false), false],
            'read-only institution'        => [new PostContext('group', true, true, institutionReadOnly: true), false],
            'closed offering'              => [new PostContext('course', true, true, offeringClosed: true), false],
            'lost parent link'             => [new PostContext('direct', true, true, subjectLinkLost: true), false],
            'blocked direct thread'        => [new PostContext('direct', true, true, blocked: true), false],
            'blocked by exempt blocker'    => [new PostContext('direct', true, true, blocked: true, blockerIsExempt: true), true],
            'student dm switched off'      => [new PostContext('direct', true, true, studentDmBlocked: true), false],
        ];
    }

    #[DataProvider('provideReactionRules')]
    public function testReactionsAreAllowedInALockedCourseChatButNotWhenBlockedOrReadOnly(PostContext $context, bool $expected): void
    {
        $this->assertSame($expected, (new MessagingPolicy())->canReact($context));
    }
}
