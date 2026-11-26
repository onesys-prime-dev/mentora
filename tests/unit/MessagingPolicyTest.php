<?php

declare(strict_types=1);

use App\Services\Messaging\MessagingPolicy;
use App\Services\Messaging\Party;
use App\Services\Messaging\PostContext;
use App\Services\Messaging\StartContext;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Messages permission rules. Starting a thread follows requirements FR1
 * (docs/feature-development/messages-institution-wide/requirements.md) as the
 * AC1 matrix. Pure logic: every fact the policy needs is passed in.
 *
 * @internal
 */
final class MessagingPolicyTest extends CIUnitTestCase
{
    private const ROLES = ['student', 'instructor', 'chairperson', 'dean', 'parent', 'institutional_admin', 'admin'];

    private const STAFF = ['instructor', 'chairperson', 'dean', 'institutional_admin'];

    /** @param list<string> $roles */
    private static function party(array $roles, int $id = 1, bool $minor = false): Party
    {
        return new Party($id, $roles, true, 1, $minor);
    }

    private static function can(Party $sender, Party $recipient, StartContext $c = new StartContext(), ?int $subject = null): bool
    {
        return (new MessagingPolicy())->canStart($sender, $recipient, $subject, $c);
    }

    /**
     * D1/D2 with the default facts: open within the institution; a parent only in a thread about their linked child,
     * and only with staff; the system admin never.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideRolePairs(): iterable
    {
        foreach (self::ROLES as $sender) {
            foreach (self::ROLES as $recipient) {
                $expected = match (true) {
                    $sender === 'admin' || $recipient === 'admin' => false,
                    $sender === 'parent'                          => in_array($recipient, self::STAFF, true),
                    $recipient === 'parent'                       => in_array($sender, self::STAFF, true),
                    default                                       => true,
                };

                yield "{$sender} -> {$recipient}" => [$sender, $recipient, $expected];
            }
        }
    }

    /**
     * A parent on either side names their linked child; anyone else starts a thread about nobody.
     *
     * @param array<string, mixed> $facts
     */
    private static function pairStart(string $sender, string $recipient, array $facts = []): bool
    {
        $subject = $sender === 'parent' || $recipient === 'parent' ? 99 : null;
        $c       = new StartContext(...$facts + ['linkedChildAsSenderParent' => $sender === 'parent', 'linkedChildAsRecipientParent' => $recipient === 'parent']);

        return self::can(self::party([$sender]), self::party([$recipient], 2), $c, $subject);
    }

    #[DataProvider('provideRolePairs')]
    public function testRolePairWithDefaultContext(string $sender, string $recipient, bool $expected): void
    {
        $this->assertSame($expected, self::pairStart($sender, $recipient));
    }

    public function testMultiRoleStudentTeacherIsStaffToClassmates(): void
    {
        $off = new StartContext(studentScope: 'off');

        // D4: a student who also teaches is staff to every classmate, so the D3 scope does not narrow them.
        $this->assertTrue(self::can(self::party(['student', 'instructor']), self::party(['student'], 2), $off));
        $this->assertTrue(self::can(self::party(['student']), self::party(['student', 'instructor'], 2), $off));
        $this->assertFalse(self::can(self::party(['student']), self::party(['student'], 2), $off));
    }

    public function testMultiRoleMinorStaffProtectedWhenReceiving(): void
    {
        $minorStaff = self::party(['student', 'instructor'], 2, true);

        // S2-f: a minor who also teaches is protected as a minor when receiving.
        $this->assertFalse(self::can(self::party(['instructor']), $minorStaff, new StartContext(recipientMinor: true)));
        $this->assertTrue(self::can(self::party(['instructor']), $minorStaff, new StartContext(recipientMinor: true, senderTeachesRecipient: true)));
        // ...and sends as staff (D4): writing to an unrelated student is fine, even with the scope off.
        $this->assertTrue(self::can(self::party(['student', 'instructor'], 1, true), self::party(['student'], 2), new StartContext(studentScope: 'off')));
    }

    public function testMinorReachableByOwnInstructorChairDeanClassmateAdminOnly(): void
    {
        $minor = self::party(['student'], 2, true);
        $facts = static fn (array $args): StartContext => new StartContext(...['recipientMinor' => true] + $args);

        $this->assertTrue(self::can(self::party(['instructor']), $minor, $facts(['senderTeachesRecipient' => true])), 'own instructor');
        $this->assertTrue(self::can(self::party(['chairperson']), $minor, $facts(['senderLeadsRecipient' => true])), 'own chair');
        $this->assertTrue(self::can(self::party(['dean']), $minor, $facts(['senderLeadsRecipient' => true])), 'own dean');
        $this->assertTrue(self::can(self::party(['student']), $minor, $facts(['sharedCourse' => true])), 'classmate');
        $this->assertTrue(self::can(self::party(['institutional_admin']), $minor, $facts([])), 'institutional admin');

        $this->assertFalse(self::can(self::party(['instructor']), $minor, $facts([])), 'unrelated instructor');
        $this->assertFalse(self::can(self::party(['chairperson']), $minor, $facts([])), 'other chair');
        $this->assertFalse(self::can(self::party(['dean']), $minor, $facts([])), 'other dean');
        $this->assertFalse(self::can(self::party(['student']), $minor, $facts([])), 'student outside their courses');
        // Sharing a course counts only for a classmate: staff who do not teach them gain nothing from it.
        $this->assertFalse(self::can(self::party(['instructor']), $minor, $facts(['sharedCourse' => true])), 'staff sharing a course');
        // The classmate rule still sits under the D3 scope.
        $this->assertFalse(self::can(self::party(['student']), $minor, $facts(['sharedCourse' => true, 'studentScope' => 'off'])), 'scope off');
    }

    /**
     * Every role against a minor recipient, with no link and with that role's own D9 link (teaches for an
     * instructor, leads for a chair or dean, a shared course for a student; a parent and the admins have none).
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function provideMinorMatrix(): iterable
    {
        foreach (self::ROLES as $role) {
            yield "{$role} -> minor, no link" => [$role, false, $role === 'institutional_admin'];
            yield "{$role} -> minor, own link" => [$role, true, ! in_array($role, ['parent', 'admin'], true)];
        }
    }

    #[DataProvider('provideMinorMatrix')]
    public function testEveryRoleAgainstAMinor(string $role, bool $linked, bool $expected): void
    {
        $link = ! $linked ? [] : match ($role) {
            'instructor'           => ['senderTeachesRecipient' => true],
            'chairperson', 'dean'  => ['senderLeadsRecipient' => true],
            'student'              => ['sharedCourse' => true],
            default                => [],
        };

        foreach ([['student'], ['student', 'instructor']] as $minorRoles) {
            $this->assertSame($expected, self::can(self::party([$role]), self::party($minorRoles, 2, true), new StartContext(...['recipientMinor' => true] + $link)), implode('+', $minorRoles));
        }
    }

    public function testAParentNeverReachesAMinorStaffMemberThroughAChildThreadWithoutTheirOwnLink(): void
    {
        $minorStaff = self::party(['student', 'instructor'], 2, true);
        $child      = static fn (array $f = []): StartContext => new StartContext(...['recipientMinor' => true, 'linkedChildAsSenderParent' => true] + $f);

        $this->assertTrue(self::can(self::party(['parent']), self::party(['instructor'], 2), new StartContext(linkedChildAsSenderParent: true), 99), 'an adult teacher: allowed');
        $this->assertFalse(self::can(self::party(['parent']), $minorStaff, $child(), 99));
        $this->assertTrue(self::can(self::party(['parent', 'instructor']), $minorStaff, $child(['senderTeachesRecipient' => true]), 99), 'a parent who teaches them');
    }

    public function testParentNeedsLinkedChildAndStaffRecipient(): void
    {
        $parent = self::party(['parent']);
        $linked = new StartContext(linkedChildAsSenderParent: true);

        $this->assertTrue(self::can($parent, self::party(['dean'], 2), $linked, 99));
        $this->assertFalse(self::can($parent, self::party(['dean'], 2), new StartContext(), 99), 'child not linked to this parent');
        $this->assertFalse(self::can($parent, self::party(['dean'], 2), $linked), 'no child named');
        $this->assertFalse(self::can($parent, self::party(['student'], 2), $linked, 99), 'student recipient');
        $this->assertFalse(self::can($parent, self::party(['parent'], 2), $linked, 99), 'parent recipient');
        // Staff to a parent: the parent side must be linked and a child must be named (ruling R7 ends).
        $this->assertTrue(self::can(self::party(['institutional_admin']), self::party(['parent'], 2), new StartContext(linkedChildAsRecipientParent: true), 99));
        $this->assertFalse(self::can(self::party(['institutional_admin']), self::party(['parent'], 2), new StartContext(linkedChildAsRecipientParent: true)));
        $this->assertFalse(self::can(self::party(['instructor']), self::party(['parent'], 2), new StartContext(linkedChildAsSenderParent: true), 99), 'wrong side linked');
    }

    public function testEachOrientationReadsItsOwnLinkedChildFact(): void
    {
        $sender = self::party(['chairperson', 'parent']);
        $recip  = self::party(['instructor', 'parent'], 2);
        $plain  = self::party(['parent'], 2);

        // Either side may be the parent, as long as the side speaking as the parent is the one linked.
        $this->assertTrue(self::can($sender, $recip, new StartContext(linkedChildAsRecipientParent: true), 99));
        $this->assertTrue(self::can($sender, $recip, new StartContext(linkedChildAsSenderParent: true), 99));
        // A staff+parent writing to a parent-only person about the sender's own child: the recipient is no staff.
        $this->assertFalse(self::can($sender, $plain, new StartContext(linkedChildAsSenderParent: true), 99));
        $this->assertTrue(self::can($sender, $plain, new StartContext(linkedChildAsRecipientParent: true), 99));
    }

    public function testStaffParentOutsideAChildThreadActsAsStaff(): void
    {
        $both = self::party(['chairperson', 'parent']);

        $this->assertTrue(self::can($both, self::party(['instructor'], 2)));
        $this->assertTrue(self::can(self::party(['student'], 2), $both));
        $this->assertFalse(self::can(self::party(['parent']), self::party(['instructor'], 2)), 'a parent-only person starts only parent threads');
    }

    public function testAnyoneCanReachInstitutionalAdmin(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'dean', 'institutional_admin'] as $role) {
            $this->assertTrue(self::can(self::party([$role]), self::party(['institutional_admin'], 2), new StartContext(studentScope: 'off')), $role);
        }
        $this->assertTrue(self::can(self::party(['parent']), self::party(['institutional_admin'], 2), new StartContext(linkedChildAsSenderParent: true), 99));
    }

    public function testScopeOffCourseInstitution(): void
    {
        $a = self::party(['student']);
        $b = self::party(['student'], 2);

        $this->assertFalse(self::can($a, $b, new StartContext(studentScope: 'off', sharedCourse: true)));
        $this->assertFalse(self::can($a, $b, new StartContext(studentScope: 'course')));
        $this->assertTrue(self::can($a, $b, new StartContext(studentScope: 'course', sharedCourse: true)));
        $this->assertTrue(self::can($a, $b, new StartContext(studentScope: 'institution')));
        // Only student <-> student: the scope never touches a pair with staff.
        $this->assertTrue(self::can($a, self::party(['dean'], 2), new StartContext(studentScope: 'off')));
        $this->assertTrue(self::can(self::party(['dean']), $b, new StartContext(studentScope: 'off')));
    }

    public function testRestrictedAndPausedRefused(): void
    {
        // A restriction and the automatic pause both arrive as the sender's restriction kind.
        foreach (['none' => true, 'no_new' => false, 'no_send' => false] as $restriction => $expected) {
            $this->assertSame($expected, self::can(self::party(['instructor']), self::party(['student'], 2), new StartContext(senderRestriction: $restriction)), $restriction);
        }
        // Opening a thread that exists ignores it.
        $this->assertTrue(self::can(self::party(['instructor']), self::party(['student'], 2), (new StartContext(senderRestriction: 'no_new'))->forOpening()));
    }

    public function testReadOnlyInstitutionRefusesStart(): void
    {
        $this->assertFalse(self::can(self::party(['institutional_admin']), self::party(['student'], 2), new StartContext(senderInstitutionWritable: false)));
        $this->assertTrue(self::can(self::party(['institutional_admin']), self::party(['student'], 2), new StartContext(senderInstitutionWritable: true)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideRefusals(): iterable
    {
        yield 'self'                  => [['isSelf' => true]];
        yield 'other institution'     => [['sameInstitution' => false]];
        yield 'sender deactivated'    => [['senderActive' => false]];
        yield 'recipient deactivated' => [['recipientActive' => false]];
        yield 'impersonation'         => [['impersonating' => true]];
        yield 'read-only institution' => [['senderInstitutionWritable' => false]];
        yield 'restricted'            => [['senderRestriction' => 'no_new']];
        yield 'blocked by recipient'  => [['blockedByRecipient' => true]];
    }

    /** @param array<string, mixed> $facts */
    #[DataProvider('provideRefusals')]
    public function testHardRefusalsAndBlocksStopEveryPair(array $facts): void
    {
        foreach (self::provideRolePairs() as [$sender, $recipient, $allowed]) {
            if ($allowed) {
                $this->assertFalse(self::pairStart($sender, $recipient, $facts), "{$sender} -> {$recipient}");
            }
        }
    }

    public function testRelationshipOnlyKeepsOnlyThePairFacts(): void
    {
        $c = (new StartContext(senderActive: false, recipientActive: false, senderInstitutionWritable: false, impersonating: true, blockedByRecipient: true, senderRestriction: 'no_send', recipientMinor: true))->relationshipOnly();

        // D26: account state, restriction and block are left out; the minor fact stays.
        $this->assertTrue(self::can(self::party(['institutional_admin']), self::party(['student'], 2, true), $c));
        $this->assertFalse(self::can(self::party(['instructor']), self::party(['student'], 2, true), $c));
    }

    public function testSystemAdminAlwaysRefused(): void
    {
        $this->assertFalse(self::can(self::party(['admin']), self::party(['student'], 2)));
        $this->assertFalse(self::can(self::party(['student']), self::party(['admin'], 2)));
        // Ruling R10: beside an institution role the admin role is ignored.
        $this->assertTrue(self::can(self::party(['instructor', 'admin']), self::party(['instructor'], 2)));
    }

    public function testSystemAdminNeverModerates(): void
    {
        $this->assertFalse((new MessagingPolicy())->canModerate(self::party(['admin']), 'course', true));
    }

    public function testAdminRoleIsIgnoredBesideInstitutionRoles(): void
    {
        // Ruling R10: inside Messages the account acts under its institution roles.
        $this->assertTrue((new MessagingPolicy())->canModerate(self::party(['admin', 'institutional_admin']), 'course', true));
    }

    public function testABlockedDirectPostIsRefused(): void
    {
        $policy = new MessagingPolicy();

        $this->assertFalse($policy->canPost(new PostContext('direct', true, true, blocked: true)));
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
        yield 'student scope lost, direct'     => [new PostContext('direct', true, true, scopeLost: true), false];
        yield 'minor no longer reached'        => [new PostContext('direct', true, true, minorLost: true), false];
        yield 'other side deactivated'         => [new PostContext('direct', true, true, deactivatedOther: true), false];
        yield 'minor flag never stops a group' => [new PostContext('group', true, true, minorLost: true, scopeLost: true), true];
        yield 'group chat'                     => [new PostContext('group', true, true), true];
        yield 'parent link ended'              => [new PostContext('direct', true, true, subjectLinkLost: true), false];
        yield 'no_new restriction, direct'     => [new PostContext('direct', true, true, restriction: 'no_new'), true];
        yield 'no_send restriction, direct'    => [new PostContext('direct', true, true, restriction: 'no_send'), false];
        yield 'no_send, group'                 => [new PostContext('group', true, true, restriction: 'no_send'), false];
        yield 'no_send, course chat student'   => [new PostContext('course', true, true, restriction: 'no_send'), false];
        yield 'no_send, own course chat'       => [new PostContext('course', true, true, isCourseInstructor: true, restriction: 'no_send'), true];
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

        $this->assertTrue($policy->canModerate(self::party(['instructor']), 'course', true));
        $this->assertFalse($policy->canModerate(self::party(['instructor']), 'course', false));
        $this->assertFalse($policy->canModerate(self::party(['student']), 'course', true));
        $this->assertTrue($policy->canModerate(self::party(['chairperson']), 'group', true));
        $this->assertTrue($policy->canModerate(self::party(['dean']), 'group', true));
        $this->assertFalse($policy->canModerate(self::party(['dean']), 'group', false));
        $this->assertTrue($policy->canModerate(self::party(['institutional_admin']), 'course', false));
        $this->assertTrue($policy->canModerate(self::party(['institutional_admin']), 'group', false));

        foreach (self::ROLES as $role) {
            $this->assertFalse($policy->canModerate(self::party([$role]), 'direct', true), $role);
        }
    }

    public function testModerationGapsAreRefused(): void
    {
        $policy = new MessagingPolicy();

        $this->assertFalse($policy->canModerate(self::party(['chairperson']), 'course', true));
        $this->assertFalse($policy->canModerate(self::party(['dean']), 'course', true));
        $this->assertFalse($policy->canModerate(self::party(['instructor']), 'group', false));
        $this->assertTrue($policy->canModerate(self::party(['student']), 'group', true), 'any owner');
        $this->assertFalse($policy->canModerate(self::party(['admin']), 'course', true));
        $this->assertFalse($policy->canModerate(self::party(['admin']), 'group', true));
    }

    public function testNobodyModeratesAcrossInstitutions(): void
    {
        $policy = new MessagingPolicy();

        foreach (self::ROLES as $role) {
            foreach (['course', 'group', 'direct'] as $type) {
                $this->assertFalse($policy->canModerate(self::party([$role]), $type, true, false), "{$role} {$type}");
            }
        }
        $this->assertTrue($policy->canModerate(self::party(['institutional_admin']), 'course', false, true));
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
            'student scope lost'           => [new PostContext('direct', true, true, scopeLost: true), false],
            'minor no longer reached'      => [new PostContext('direct', true, true, minorLost: true), false],
            'other side deactivated'       => [new PostContext('direct', true, true, deactivatedOther: true), false],
            'no_send restriction'          => [new PostContext('direct', true, true, restriction: 'no_send'), false],
            'no_send, own course chat'     => [new PostContext('course', true, true, isCourseInstructor: true, restriction: 'no_send'), true],
            'no_new restriction'           => [new PostContext('direct', true, true, restriction: 'no_new'), true],
        ];
    }

    #[DataProvider('provideReactionRules')]
    public function testReactionsAreAllowedInALockedCourseChatButNotWhenBlockedOrReadOnly(PostContext $context, bool $expected): void
    {
        $this->assertSame($expected, (new MessagingPolicy())->canReact($context));
    }
}
