<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * Inviting a parent from the People page with the students to link: the
 * field in the dialog, a typo that stops the invitation, and the pending
 * list that says how many students will be linked.
 *
 * @internal
 */
final class InstitutionPeopleInviteParentTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    private FakeEmail $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        $this->mailer = FakeEmail::install();

        $this->institution = SisFixture::institution('PIPA');
        $this->other       = SisFixture::institution('PIPB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@pip.example', $this->institution);

        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function student(string $first, ?string $number = null, ?int $institution = null): User
    {
        $student = PeopleFactory::person('student', $first, 'Lee', strtolower($first) . '@pip.example', $institution ?? $this->institution);

        if ($number !== null) {
            db_connect()->table('user_profiles')->where('user_id', $student->id)->update(['student_number' => $number]);
        }

        return $student;
    }

    /** @param array<string, string> $extra */
    private function invite(string $role, array $extra = [], string $email = 'pam@pip.example'): \CodeIgniter\Test\TestResponse
    {
        $this->withHeaders(['Accept' => 'application/json']);

        return $this->step('POST', 'institution/people', ['first_name' => 'Pam', 'last_name' => 'Parent', 'email' => $email, 'role' => $role] + $extra);
    }

    private function invitations(): array
    {
        return db_connect()->table('institution_invitations')->where('institution_id', $this->institution)->get()->getResultArray();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    public function testTheDialogHasTheOptionalStudentsFieldWithItsNote(): void
    {
        $this->withHeaders(['Accept' => 'text/html']);

        $body = $this->step('GET', 'institution/people')->response()->getBody();

        $this->assertStringContainsString('name="students"', $body);
        $this->assertStringContainsString('Students to link', $body);
        $this->assertStringContainsString('Only for a parent', $body);
    }

    public function testAParentIsInvitedWithTwoStudentsAndThePendingListSaysSo(): void
    {
        $ann = $this->student('Ann', '2026-001');
        $ben = $this->student('Ben');

        $result = $this->invite('parent', ['students' => "2026-001\nben@pip.example"]);

        $this->assertTrue($result->isOK());
        $this->assertCount(1, $this->mailer->sent);

        $rows = $this->invitations();

        $this->assertCount(1, $rows);
        $this->assertEqualsCanonicalizing([(int) $ann->id, (int) $ben->id], json_decode((string) $rows[0]['student_ids'], true));

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->step('GET', 'institution/people?view=invitations')->response()->getBody();

        $this->assertStringContainsString('2 students to link', $body);
    }

    public function testOneStudentIsSingular(): void
    {
        $this->student('Ann', '2026-001');
        $this->invite('parent', ['students' => '2026-001']);

        $this->withHeaders(['Accept' => 'text/html']);

        $this->assertStringContainsString('1 student to link', $this->step('GET', 'institution/people?view=invitations')->response()->getBody());
    }

    public function testATypoStopsTheInvitationOnTheStudentsFieldAndNothingIsSent(): void
    {
        $this->student('Ann', '2026-001');

        $result = $this->invite('parent', ['students' => "2026-001\n2026-009"]);

        $this->assertSame(422, $result->response()->getStatusCode());

        $errors = json_decode($result->getJSON(), true)['errors'];

        $this->assertArrayHasKey('students', $errors);
        $this->assertStringContainsString('2026-009', $errors['students']);
        $this->assertSame([], $this->invitations());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAStudentOfAnotherInstitutionIsAnUnmatchedName(): void
    {
        $this->student('Zed', '2026-777', $this->other);

        $result = $this->invite('parent', ['students' => '2026-777']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertSame([], $this->invitations());
    }

    public function testStudentsOnAnotherRoleAreRefused(): void
    {
        $this->student('Ann', '2026-001');

        $result = $this->invite('instructor', ['students' => '2026-001'], 'ivy@pip.example');

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('students', json_decode($result->getJSON(), true)['errors']);
        $this->assertSame([], $this->invitations());
    }

    public function testAParentInvitationWithoutStudentsIsAsBefore(): void
    {
        $result = $this->invite('parent');

        $this->assertTrue($result->isOK());
        $this->assertNull($this->invitations()[0]['student_ids']);

        $this->withHeaders(['Accept' => 'text/html']);
        $this->assertStringNotContainsString('data-students-to-link', $this->step('GET', 'institution/people?view=invitations')->response()->getBody());
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanInvite(string $role): void
    {
        $this->student('Ann', '2026-001');
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@pip.example", $this->institution));

        $this->invite('parent', ['students' => '2026-001']);

        $this->assertSame([], $this->invitations());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAReadOnlyInstitutionRefusesIt(): void
    {
        $this->student('Ann', '2026-001');
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);

        $result = $this->invite('parent', ['students' => '2026-001']);

        $this->assertSame(403, $result->response()->getStatusCode());
        $this->assertSame([], $this->invitations());
    }
}
