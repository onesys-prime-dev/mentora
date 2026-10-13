<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The institutional administrator's Structure page and what it posts:
 * who may use it, what it shows, each create / edit / switch-off / switch-on,
 * the JSON a dialog form gets back, refusals shown in place, another
 * institution's ids answering 404, and a read-only institution blocking it.
 *
 * @internal
 */
final class InstitutionStructureTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private int $department;

    private int $subDepartment;

    private int $program;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('STRUCT');
        $this->other       = SisFixture::institution('STRUCTO');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@struct.example', $this->institution);

        $db = db_connect();

        $this->department    = (int) $db->table('departments')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $this->subDepartment = (int) $db->table('sub_departments')->where('department_id', $this->department)->get()->getRowArray()['id'];
        $this->program       = (int) $db->table('programs')->where('sub_department_id', $this->subDepartment)->get()->getRowArray()['id'];

        $db->table('audit_logs')->emptyTable();

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

    private function page(): string
    {
        return $this->step('GET', 'institution/structure')->response()->getBody();
    }

    private function url(string $suffix = ''): string
    {
        return 'institution/structure' . $suffix;
    }

    private function audited(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /** @return array<string, int> */
    private function foreign(): array
    {
        $db = db_connect();

        $department = (int) $db->table('departments')->where('institution_id', $this->other)->get()->getRowArray()['id'];
        $sub        = (int) $db->table('sub_departments')->where('department_id', $department)->get()->getRowArray()['id'];

        return ['department' => $department, 'sub' => $sub, 'program' => (int) $db->table('programs')->where('sub_department_id', $sub)->get()->getRowArray()['id']];
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
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@struct.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', $this->url())->response()->getStatusCode());

        $before = db_connect()->table('departments')->countAllResults();
        $this->step('POST', $this->url('/departments'), ['code' => 'HACK', 'name' => 'Hacked department']);

        $this->assertSame($before, db_connect()->table('departments')->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | The page
    |--------------------------------------------------------------------------
    */

    public function testThePageShowsTheTreeWithItsCountsAndTheActions(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('data-department="' . $this->department . '"', $body);
        $this->assertStringContainsString('data-sub-department="' . $this->subDepartment . '"', $body);
        $this->assertStringContainsString('data-program="' . $this->program . '"', $body);
        $this->assertMatchesRegularExpression('/1 department,\s+1 sub-department,\s+1 program/', $body);
        $this->assertStringContainsString('Add a department', $body);
        $this->assertStringContainsString('id="structure-department"', $body);
        $this->assertStringContainsString('id="structure-confirm"', $body);
    }

    public function testAnotherInstitutionsStructureIsNeverShown(): void
    {
        $body = $this->page();

        $this->assertStringNotContainsString('data-department="' . $this->foreign()['department'] . '"', $body);
        $this->assertStringNotContainsString('STRUCTO College', $body);
    }

    public function testWithNoDepartmentsThePageSaysSoAndOffersTheFirstOne(): void
    {
        $empty = SisFixture::institution('STREMPTY');
        db_connect()->table('departments')->where('institution_id', $empty)->delete();
        $admin = PeopleFactory::person('institutional_admin', 'Eve', 'Empty', 'eve@struct.example', $empty);
        $this->signIn($admin);

        $body = $this->page();

        $this->assertStringContainsString('data-structure-empty', $body);
        $this->assertStringContainsString('No departments yet', $body);
        $this->assertStringContainsString('Add a department', $body);
    }

    public function testAnAdministratorWithNoInstitutionIsSentAway(): void
    {
        $this->signIn(PeopleFactory::person('institutional_admin', 'No', 'Home', 'nohome@struct.example'));

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->call('GET', $this->url());
    }

    /*
    |--------------------------------------------------------------------------
    | Creating and editing
    |--------------------------------------------------------------------------
    */

    public function testAJsonDialogPostCreatesAndAnswersWithAMessageAndWhereToGo(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', $this->url('/departments'), ['code' => 'ccs', 'name' => 'College of Computing', 'location' => '', 'established_year' => '']);

        $this->assertTrue($result->isOK());
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('The department was added.', $json['message']);
        $this->assertStringContainsString('institution/structure', $json['redirect']);

        $row = db_connect()->table('departments')->where(['institution_id' => $this->institution, 'code' => 'CCS'])->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(1, $this->audited('structure.department_created'));
    }

    public function testARefusedDialogPostAnswers422WithTheFieldToHighlight(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', $this->url('/departments'), ['code' => 'bad code', 'name' => 'College of Computing']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('code', $json['errors']);
        $this->assertSame('Nothing was saved.', $json['message']);
    }

    public function testAPlainPostRedirectsBackWithAMessage(): void
    {
        $response = $this->step('POST', $this->url('/departments'), ['code' => 'PLAIN', 'name' => 'A plain department'])->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('institution/structure', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('The department was added.', $this->page());
    }

    public function testAPlainPostThatIsRefusedShowsTheReasonOnThePage(): void
    {
        $this->step('POST', $this->url('/departments'), ['code' => 'DUP', 'name' => 'First department']);
        $this->step('POST', $this->url('/departments'), ['code' => 'DUP', 'name' => 'Second department']);

        $this->assertStringContainsString('Another department already uses the code DUP', $this->page());
        $this->assertSame(1, db_connect()->table('departments')->where(['institution_id' => $this->institution, 'code' => 'DUP'])->countAllResults());
    }

    public function testEachLevelCanBeCreatedAndEdited(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $this->step('POST', $this->url("/departments/{$this->department}/sub-departments"), ['code' => 'NEWSUB', 'name' => 'A new sub-department']);
        $subId = (int) db_connect()->table('sub_departments')->where('code', 'NEWSUB')->get()->getRowArray()['id'];

        $this->step('POST', $this->url("/sub-departments/{$subId}/programs"), ['code' => 'NEWPRG', 'name' => 'A new program']);
        $programId = (int) db_connect()->table('programs')->where('code', 'NEWPRG')->get()->getRowArray()['id'];

        $this->step('POST', $this->url("/departments/{$this->department}"), ['code' => 'RENAMED', 'name' => 'Renamed department', 'location' => 'East wing', 'established_year' => '2001']);
        $this->step('POST', $this->url("/sub-departments/{$subId}"), ['code' => 'NEWSUB', 'name' => 'Renamed sub-department']);
        $this->step('POST', $this->url("/programs/{$programId}"), ['code' => 'NEWPRG', 'name' => 'Renamed program']);

        $db = db_connect();
        $this->assertSame('RENAMED', $db->table('departments')->where('id', $this->department)->get()->getRowArray()['code']);
        $this->assertSame('East wing', $db->table('departments')->where('id', $this->department)->get()->getRowArray()['location']);
        $this->assertSame('Renamed sub-department', $db->table('sub_departments')->where('id', $subId)->get()->getRowArray()['name']);
        $this->assertSame('Renamed program', $db->table('programs')->where('id', $programId)->get()->getRowArray()['name']);

        foreach (['structure.sub_department_created', 'structure.program_created', 'structure.department_updated', 'structure.sub_department_updated', 'structure.program_updated'] as $action) {
            $this->assertSame(1, $this->audited($action), $action);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Switching off and on
    |--------------------------------------------------------------------------
    */

    public function testABusyDepartmentCannotBeDeactivatedAndTheReasonIsShown(): void
    {
        $this->step('POST', $this->url("/departments/{$this->department}/deactivate"));

        $this->assertStringContainsString('1 active sub-department', $this->page());
        $this->assertSame(1, (int) db_connect()->table('departments')->where('id', $this->department)->get()->getRowArray()['is_active']);
    }

    public function testAProgramCanBeDeactivatedArchivedAndBroughtBack(): void
    {
        $this->step('POST', $this->url("/programs/{$this->program}/deactivate"));
        $this->assertSame('inactive', db_connect()->table('programs')->where('id', $this->program)->get()->getRowArray()['status']);
        $this->assertStringContainsString('Reactivate', $this->page());

        $this->step('POST', $this->url("/programs/{$this->program}/archive"));
        $this->assertSame('archived', db_connect()->table('programs')->where('id', $this->program)->get()->getRowArray()['status']);

        $this->step('POST', $this->url("/programs/{$this->program}/reactivate"));
        $this->assertSame('active', db_connect()->table('programs')->where('id', $this->program)->get()->getRowArray()['status']);
    }

    public function testTheWholeBranchGoesOffAndComesBackOn(): void
    {
        $this->step('POST', $this->url("/programs/{$this->program}/deactivate"));
        $this->step('POST', $this->url("/sub-departments/{$this->subDepartment}/deactivate"));
        $this->step('POST', $this->url("/departments/{$this->department}/deactivate"));

        $this->assertSame(0, (int) db_connect()->table('departments')->where('id', $this->department)->get()->getRowArray()['is_active']);
        $this->assertStringContainsString('Inactive', $this->page());

        $this->step('POST', $this->url("/departments/{$this->department}/reactivate"));
        $this->step('POST', $this->url("/sub-departments/{$this->subDepartment}/reactivate"));

        $this->assertSame(1, (int) db_connect()->table('departments')->where('id', $this->department)->get()->getRowArray()['is_active']);
        $this->assertSame(1, (int) db_connect()->table('sub_departments')->where('id', $this->subDepartment)->get()->getRowArray()['is_active']);
    }

    /*
    |--------------------------------------------------------------------------
    | Another institution, and read-only
    |--------------------------------------------------------------------------
    */

    public function testEveryIdOfAnotherInstitutionIsA404(): void
    {
        $foreign = $this->foreign();

        $attempts = [
            "/departments/{$foreign['department']}" => ['code' => 'X1', 'name' => 'Hacked name'],
            "/departments/{$foreign['department']}/deactivate" => [],
            "/departments/{$foreign['department']}/sub-departments" => ['code' => 'X1', 'name' => 'Hacked name'],
            "/sub-departments/{$foreign['sub']}" => ['code' => 'X1', 'name' => 'Hacked name'],
            "/sub-departments/{$foreign['sub']}/programs" => ['code' => 'X1', 'name' => 'Hacked name'],
            "/programs/{$foreign['program']}" => ['code' => 'X1', 'name' => 'Hacked name'],
            "/programs/{$foreign['program']}/archive" => [],
        ];

        foreach ($attempts as $suffix => $data) {
            try {
                $this->call('POST', $this->url($suffix), $data);
                $this->fail("{$suffix} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $row = db_connect()->table('programs')->where('id', $foreign['program'])->get()->getRowArray();
        $this->assertSame('active', $row['status']);
        $this->assertSame(0, db_connect()->table('programs')->where('code', 'X1')->countAllResults());
    }

    public function testAReadOnlyInstitutionCanLookButNotChangeAnything(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $body = $this->page();

        $this->assertStringContainsString('data-department="' . $this->department . '"', $body, 'it can be looked at');
        $this->assertStringContainsString('data-lock="read_only"', $body);
        $this->assertStringNotContainsString('Add a department', $body);
        $this->assertStringNotContainsString('id="structure-department"', $body);

        $response = $this->step('POST', $this->url('/departments'), ['code' => 'LATE', 'name' => 'Too late department'])->response();

        $this->assertSame(0, db_connect()->table('departments')->where('code', 'LATE')->countAllResults());
        $this->assertContains($response->getStatusCode(), [302, 403]);
    }

    public function testTheAppsMenuLinksAcademicsToTheStructure(): void
    {
        $body = html_entity_decode($this->step('GET', 'institution/dashboard')->response()->getBody());

        // The tile is a live link (an unbuilt one is inert and says "Coming soon").
        $this->assertSame(1, preg_match('/<a\s[^>]*href="[^"]*institution\/structure"[^>]*data-app-tile="academics"/s', $body));
    }
}
