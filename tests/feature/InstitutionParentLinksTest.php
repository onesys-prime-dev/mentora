<?php

declare(strict_types=1);

use App\Models\Academic\ParentStudentLinkModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The Parents tab and what it posts: access, the list, linking through the
 * dialog's JSON, unlinking, foreign ids answering 404, and a read-only
 * institution.
 *
 * @internal
 */
final class InstitutionParentLinksTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('PLKFA');
        $this->other       = SisFixture::institution('PLKFB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@plk.example', $this->institution);
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@plk.example', $this->institution);

        db_connect()->table('audit_logs')->emptyTable();

        $this->signIn($this->admin);
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function student(string $first, string $number, ?int $institution = null): User
    {
        $user = PeopleFactory::person('student', $first, 'Student', strtolower($first) . '@plk.example', $institution ?? $this->institution);
        db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['student_number' => $number]);

        return $user;
    }

    private function page(string $query = ''): string
    {
        return $this->step('GET', 'institution/people/parents' . $query)->response()->getBody();
    }

    private function linkCount(): int
    {
        return (new ParentStudentLinkModel())->countAllResults();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseIt(string $role): void
    {
        $this->student('Ann', '2026-001');
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@plk.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/people/parents')->response()->getStatusCode());

        $this->step('POST', "institution/people/parents/{$this->parent->id}/students", ['students' => '2026-001']);

        $this->assertSame(0, $this->linkCount());
    }

    public function testThePageListsParentsAndHasTheTabs(): void
    {
        PeopleFactory::person('parent', 'Rae', 'Elsewhere', null, $this->other);

        $body = $this->page();

        $this->assertStringContainsString('Pam Parent', $body);
        $this->assertStringContainsString('No student linked', $body);
        $this->assertStringContainsString('id="parent-link"', $body);
        $this->assertStringNotContainsString('Rae Elsewhere', $body);
        $this->assertStringContainsString('institution/people/parents', html_entity_decode($this->step('GET', 'institution/people')->response()->getBody()));
    }

    public function testStudentsAreLinkedThroughTheDialogAndShown(): void
    {
        $this->student('Ann', '2026-001');

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', "institution/people/parents/{$this->parent->id}/students", ['students' => "2026-001\nghost@plk.example"]);

        $this->assertTrue($result->isOK());
        $payload = json_decode($result->getJSON(), true);
        $this->assertStringContainsString('1 student was linked', $payload['message']);
        $this->assertStringContainsString('ghost@plk.example', $payload['message']);

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->page();

        $this->assertStringContainsString('Ann Student', $body);
        $this->assertStringContainsString('data-linked-student', $body);
        $this->assertStringNotContainsString('No student linked', $body);
    }

    public function testParentsAndStudentsHaveGatedAvatarsBesideTheirLegalNames(): void
    {
        $ann = $this->student('Ann', '2026-001');
        $bea = $this->student('Bea', '2026-002');
        db_connect()->table('user_profiles')->where('user_id', $ann->id)->update(['preferred_first_name' => 'Jun']);
        db_connect()->table('user_profiles')->where('user_id', $this->parent->id)->update(['preferred_first_name' => 'Pammy']);
        db_connect()->table('user_profiles')->where('user_id', $bea->id)->update(['preferred_first_name' => 'Zedekiah']);
        foreach ([$ann, $bea] as $child) {
            db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $this->parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
        }
        // Bea left the institution: the administrator may no longer open her card.
        db_connect()->table('institution_users')->where('user_id', $bea->id)->delete();

        $body = $this->page();

        $this->assertStringContainsString('data-person-avatar="' . $this->parent->id . '"', $body);
        $this->assertStringContainsString('data-person-avatar="' . $ann->id . '"', $body);
        $this->assertStringNotContainsString('data-person-avatar="' . $bea->id . '"', $body);
        // An admin screen keeps legal names as its text; only the card a visible avatar opens is the person's own.
        $this->assertStringContainsString('<span class="font-medium">Pam Parent</span>', $body);
        $this->assertMatchesRegularExpression('#data-linked-student="\d+">.*?Ann Student#s', $body);
        $this->assertStringContainsString('Bea Student', $body);
        $this->assertStringNotContainsString('<span class="font-medium">Pammy', $body);
        // The text a reader sees (no tags or attributes, so a visible avatar's own label does not count).
        $visible = html_entity_decode(strip_tags(preg_replace('#<(script|template)[ >].*?</(script|template)>#s', '', $body)));
        $this->assertStringContainsString('Pam Parent', $visible);
        $this->assertStringContainsString('Ann Student', $visible);
        $this->assertStringNotContainsString('Pammy', $visible);
        $this->assertStringNotContainsString('Jun Student', $visible);
        $this->assertStringNotContainsString('Zedekiah', $body, 'nothing of a hidden person beyond the legal name');
    }

    public function testNobodyLinkedAnswers422OnTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', "institution/people/parents/{$this->parent->id}/students", ['students' => 'ghost@plk.example']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('students', json_decode($result->getJSON(), true)['errors']);
    }

    public function testALinkIsRemoved(): void
    {
        $this->student('Ann', '2026-001');
        $this->step('POST', "institution/people/parents/{$this->parent->id}/students", ['students' => '2026-001']);
        $id = (int) (new ParentStudentLinkModel())->first()['id'];

        $this->step('POST', "institution/people/parents/links/{$id}/remove");

        $this->assertSame(0, $this->linkCount());
        $this->assertStringContainsString('No student linked', $this->page());
    }

    public function testAnotherInstitutionsParentAndLinksAreNeverTouched(): void
    {
        $theirParent = PeopleFactory::person('parent', 'Rae', 'Elsewhere', null, $this->other);
        $student     = $this->student('Zed', '2026-009', $this->other);

        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->other, 'parent_user_id' => $theirParent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);
        $linkId = (int) (new ParentStudentLinkModel())->first()['id'];

        foreach ([
            ["institution/people/parents/{$theirParent->id}/students", ['students' => '2026-009']],
            ["institution/people/parents/links/{$linkId}/remove", []],
        ] as [$uri, $data]) {
            try {
                $this->call('POST', $uri, $data);
                $this->fail("{$uri} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, $this->linkCount());
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        $this->student('Ann', '2026-001');
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $body = $this->page();

        $this->assertStringContainsString('Pam Parent', $body);
        $this->assertStringContainsString('data-lock="read_only"', $body);
        $this->assertStringNotContainsString('id="parent-link"', $body);

        $this->step('POST', "institution/people/parents/{$this->parent->id}/students", ['students' => '2026-001']);
        $this->assertSame(0, $this->linkCount());
    }
}
