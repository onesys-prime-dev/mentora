<?php

declare(strict_types=1);

use App\Services\Profiles\ProfilePolicy;
use App\Services\Profiles\ViewContext;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal */
final class ProfilePolicyTest extends CIUnitTestCase
{
    private const STAFF_FULL          = ['photo', 'names', 'pronouns', 'roles', 'bio', 'staff_info', 'email', 'courses'];
    private const STUDENT_FOR_STAFF   = ['photo', 'names', 'pronouns', 'roles', 'bio', 'email', 'student_details', 'courses'];
    private const STUDENT_FOR_STUDENT = ['photo', 'names', 'pronouns', 'roles', 'bio', 'student_details'];
    private const STUDENT_FOR_PARENT  = ['photo', 'names', 'pronouns', 'roles', 'bio', 'student_details', 'courses'];
    private const PARENT_NAME_ONLY    = ['photo', 'names', 'roles'];
    private const BASICS              = ['photo', 'names', 'roles', 'deactivated_badge'];

    /**
     * @param array<string, mixed> $o
     */
    private static function ctx(array $o): ViewContext
    {
        $d = [
            'isSelf' => false, 'sameInstitution' => true, 'viewerRank' => 'instructor',
            'subjectRoles' => ['instructor'], 'subjectActive' => true,
            'viewerIsParentOfSubject' => false, 'viewerIsChildOfSubject' => false,
            'viewerIsInstitutionAdmin' => false, 'subjectIsSystemAdmin' => false,
        ];

        return new ViewContext(...array_merge($d, $o));
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function provideCases(): iterable
    {
        // Staff viewer
        yield 'staff -> staff' => [[], self::STAFF_FULL];
        yield 'dean -> instructor' => [['viewerRank' => 'dean'], self::STAFF_FULL];
        yield 'staff -> student' => [['subjectRoles' => ['student']], self::STUDENT_FOR_STAFF];
        yield 'staff -> parent' => [['subjectRoles' => ['parent']], self::PARENT_NAME_ONLY];
        yield 'staff -> deactivated' => [['subjectActive' => false], self::BASICS];
        // Student viewer
        yield 'student -> staff' => [['viewerRank' => 'student'], self::STAFF_FULL];
        yield 'student -> student' => [['viewerRank' => 'student', 'subjectRoles' => ['student']], self::STUDENT_FOR_STUDENT];
        yield 'student -> own parent' => [['viewerRank' => 'student', 'subjectRoles' => ['parent'], 'viewerIsChildOfSubject' => true], self::PARENT_NAME_ONLY];
        yield 'student -> other parent' => [['viewerRank' => 'student', 'subjectRoles' => ['parent']], []];
        yield 'student -> deactivated' => [['viewerRank' => 'student', 'subjectRoles' => ['student'], 'subjectActive' => false], self::BASICS];
        // Parent viewer
        yield 'parent -> staff' => [['viewerRank' => 'parent'], self::STAFF_FULL];
        yield 'parent -> own child, no email' => [['viewerRank' => 'parent', 'subjectRoles' => ['student'], 'viewerIsParentOfSubject' => true], self::STUDENT_FOR_PARENT];
        yield 'parent -> other child' => [['viewerRank' => 'parent', 'subjectRoles' => ['student']], []];
        yield 'parent -> parent' => [['viewerRank' => 'parent', 'subjectRoles' => ['parent']], []];
        yield 'parent -> deactivated' => [['viewerRank' => 'parent', 'subjectActive' => false], self::BASICS];
        // Deactivated subjects keep the relationship gate
        yield 'parent -> unrelated deactivated student' => [['viewerRank' => 'parent', 'subjectRoles' => ['student'], 'subjectActive' => false], []];
        yield 'parent -> own deactivated child' => [['viewerRank' => 'parent', 'subjectRoles' => ['student'], 'subjectActive' => false, 'viewerIsParentOfSubject' => true], self::BASICS];
        yield 'student -> unrelated deactivated parent' => [['viewerRank' => 'student', 'subjectRoles' => ['parent'], 'subjectActive' => false], []];
        yield 'student -> own deactivated parent' => [['viewerRank' => 'student', 'subjectRoles' => ['parent'], 'subjectActive' => false, 'viewerIsChildOfSubject' => true], self::BASICS];
        yield 'staff -> deactivated student' => [['subjectRoles' => ['student'], 'subjectActive' => false], self::BASICS];
        yield 'admin -> deactivated staff, no admin_actions' => [['viewerIsInstitutionAdmin' => true, 'subjectActive' => false], self::BASICS];
        // Outside the rows
        yield 'subject is system admin' => [['subjectIsSystemAdmin' => true], []];
        yield 'other institution' => [['sameInstitution' => false], []];
        yield 'system admin viewer, no rank' => [['viewerRank' => null], []];
        // D31
        yield 'instructor viewer who is also a parent uses staff row' => [['viewerRank' => 'instructor', 'subjectRoles' => ['student']], self::STUDENT_FOR_STAFF];
        yield 'student viewer -> student+instructor: staff_info, no email or courses' => [
            ['viewerRank' => 'student', 'subjectRoles' => ['instructor', 'student']],
            ['photo', 'names', 'pronouns', 'roles', 'bio', 'staff_info', 'student_details'],
        ];
        yield 'staff viewer -> student+instructor' => [
            ['subjectRoles' => ['instructor', 'student']],
            ['photo', 'names', 'pronouns', 'roles', 'bio', 'staff_info', 'email', 'student_details', 'courses'],
        ];
        yield 'student viewer -> instructor+parent: parent adds nothing' => [['viewerRank' => 'student', 'subjectRoles' => ['instructor', 'parent']], self::STAFF_FULL];
        // M7
        yield 'student viewer who is the parent of the subject gets the own-child grant' => [
            ['viewerRank' => 'student', 'subjectRoles' => ['student'], 'viewerIsParentOfSubject' => true],
            ['photo', 'names', 'pronouns', 'roles', 'bio', 'student_details', 'courses'],
        ];
        // Admin actions
        yield 'institutional admin gets admin_actions' => [['viewerIsInstitutionAdmin' => true], [...self::STAFF_FULL, 'admin_actions']];
        yield 'admin actions on a student' => [['viewerIsInstitutionAdmin' => true, 'subjectRoles' => ['student']], [...self::STUDENT_FOR_STAFF, 'admin_actions']];
        yield 'admin sees deactivated as basics only' => [['viewerIsInstitutionAdmin' => true, 'subjectActive' => false], self::BASICS];
        yield 'admin gets nothing when subject is hidden' => [['viewerIsInstitutionAdmin' => true, 'sameInstitution' => false], []];
        // Self
        yield 'self instructor' => [['isSelf' => true], self::STAFF_FULL];
        yield 'self student' => [['isSelf' => true, 'subjectRoles' => ['student']], ['photo', 'names', 'pronouns', 'roles', 'bio', 'email', 'student_details', 'courses', 'parents']];
        yield 'self parent' => [['isSelf' => true, 'subjectRoles' => ['parent']], ['photo', 'names', 'pronouns', 'roles', 'bio', 'email', 'children']];
        yield 'self system admin' => [['isSelf' => true, 'subjectIsSystemAdmin' => true, 'subjectRoles' => []], ['names', 'roles', 'email']];
        yield 'self ignores institution' => [['isSelf' => true, 'sameInstitution' => false], self::STAFF_FULL];
        yield 'self is not given admin_actions' => [['isSelf' => true, 'viewerIsInstitutionAdmin' => true], self::STAFF_FULL];
    }

    /**
     * @param array<string, mixed> $override
     * @param list<string>         $expected
     */
    #[DataProvider('provideCases')]
    public function testFields(array $override, array $expected): void
    {
        $this->assertSame($expected, (new ProfilePolicy())->fields(self::ctx($override)));
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>, list<string>}> */
    public static function provideRoleCases(): iterable
    {
        $stuPar = ['subjectRoles' => ['student', 'parent']];
        $insPar = ['subjectRoles' => ['instructor', 'parent']];
        $stuIns = ['subjectRoles' => ['instructor', 'student']];

        yield 'student -> student+parent' => [['viewerRank' => 'student', ...$stuPar], self::STUDENT_FOR_STUDENT, ['student']];
        yield 'parent -> instructor+parent' => [['viewerRank' => 'parent', ...$insPar], self::STAFF_FULL, ['instructor']];
        yield 'unlinked parent -> student+instructor' => [
            ['viewerRank' => 'parent', ...$stuIns],
            ['photo', 'names', 'pronouns', 'roles', 'bio', 'staff_info'],
            ['instructor'],
        ];
        yield 'linked parent -> student+instructor' => [
            ['viewerRank' => 'parent', 'viewerIsParentOfSubject' => true, ...$stuIns],
            ['photo', 'names', 'pronouns', 'roles', 'bio', 'staff_info', 'student_details', 'courses'],
            ['instructor', 'student'],
        ];
        yield 'student -> own parent who is an instructor' => [
            ['viewerRank' => 'student', 'viewerIsChildOfSubject' => true, ...$insPar],
            self::STAFF_FULL,
            ['instructor', 'parent'],
        ];
        yield 'staff -> student+parent' => [$stuPar, self::STUDENT_FOR_STAFF, ['student', 'parent']];
        yield 'deactivated: student -> student+parent' => [['viewerRank' => 'student', 'subjectActive' => false, ...$stuPar], self::BASICS, ['student']];
        yield 'deactivated: parent -> instructor+parent' => [['viewerRank' => 'parent', 'subjectActive' => false, ...$insPar], self::BASICS, ['instructor']];
        yield 'self keeps every role' => [['isSelf' => true, 'subjectRoles' => ['instructor', 'student', 'parent']], [...self::STAFF_FULL, 'student_details', 'parents', 'children'], ['instructor', 'student', 'parent']];
        yield 'self system admin' => [['isSelf' => true, 'subjectIsSystemAdmin' => true, 'subjectRoles' => []], ['names', 'roles', 'email'], ['admin']];
        yield 'hidden subject has no roles' => [['sameInstitution' => false], [], []];
    }

    /**
     * @param array<string, mixed> $override
     * @param list<string>         $fields
     * @param list<string>         $roles
     */
    #[DataProvider('provideRoleCases')]
    public function testRolesPerViewer(array $override, array $fields, array $roles): void
    {
        $policy = new ProfilePolicy();
        $c      = self::ctx($override);
        $this->assertSame($roles, $policy->roles($c));
        $this->assertSame(array_values(array_intersect(['photo', 'names', 'pronouns', 'roles', 'bio', 'staff_info', 'email', 'student_details', 'courses', 'children', 'parents', 'admin_actions', 'deactivated_badge'], $fields)), $policy->fields($c));
    }
}
