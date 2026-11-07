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

    private int $college;

    private int $department;

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

        $this->college    = (int) $db->table('colleges')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $this->department = (int) $db->table('departments')->where('college_id', $this->college)->get()->getRowArray()['id'];
        $this->program       = (int) $db->table('programs')->where('department_id', $this->department)->get()->getRowArray()['id'];

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

        $college = (int) $db->table('colleges')->where('institution_id', $this->other)->get()->getRowArray()['id'];
        $department        = (int) $db->table('departments')->where('college_id', $college)->get()->getRowArray()['id'];

        return ['college' => $college, 'department' => $department, 'program' => (int) $db->table('programs')->where('department_id', $department)->get()->getRowArray()['id']];
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

        $before = db_connect()->table('colleges')->countAllResults();
        $this->step('POST', $this->url('/colleges'), ['code' => 'HACK', 'name' => 'Hacked college']);

        $this->assertSame($before, db_connect()->table('colleges')->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | The page
    |--------------------------------------------------------------------------
    */

    public function testThePageShowsTheTreeWithItsCountsAndTheActions(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('data-college="' . $this->college . '"', $body);
        $this->assertStringContainsString('data-department="' . $this->department . '"', $body);
        $this->assertStringContainsString('data-program="' . $this->program . '"', $body);
        $this->assertMatchesRegularExpression('/<strong[^>]*>1<\/strong>\s+college.*?<strong[^>]*>1<\/strong>\s+department.*?<strong[^>]*>1<\/strong>\s+program/s', $body);
        $this->assertStringContainsString('Add a college', $body);
        $this->assertStringContainsString('id="structure-college"', $body);
        $this->assertStringContainsString('id="structure-confirm"', $body);
    }

    public function testAnotherInstitutionsStructureIsNeverShown(): void
    {
        $body = $this->page();

        $this->assertStringNotContainsString('data-college="' . $this->foreign()['college'] . '"', $body);
        $this->assertStringNotContainsString('STRUCTO College', $body);
    }

    public function testWithNoCollegesThePageSaysSoAndOffersTheFirstOne(): void
    {
        $empty = SisFixture::institution('STREMPTY');
        db_connect()->table('colleges')->where('institution_id', $empty)->delete();
        $admin = PeopleFactory::person('institutional_admin', 'Eve', 'Empty', 'eve@struct.example', $empty);
        $this->signIn($admin);

        $body = $this->page();

        $this->assertStringContainsString('data-structure-empty', $body);
        $this->assertStringContainsString('No colleges yet', $body);
        $this->assertStringContainsString('Add a college', $body);
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

        $result = $this->step('POST', $this->url('/colleges'), ['code' => 'ccs', 'name' => 'College of Computing', 'location' => '', 'established_year' => '']);

        $this->assertTrue($result->isOK());
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('The college was added.', $json['message']);
        $this->assertStringContainsString('institution/structure', $json['redirect']);

        $row = db_connect()->table('colleges')->where(['institution_id' => $this->institution, 'code' => 'CCS'])->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(1, $this->audited('structure.college_created'));
    }

    public function testADeanIsAssignedToACollegeReplacedAndRemoved(): void
    {
        $first  = PeopleFactory::person('dean', 'Dina', 'Dean', 'dina@struct.example', $this->institution);
        $second = PeopleFactory::person('dean', 'Dan', 'Dale', 'dan@struct.example', $this->institution);

        $this->assertStringContainsString('Assign dean', $this->page());

        $this->withHeaders(['Accept' => 'application/json']);
        $this->assertTrue($this->step('POST', $this->url('/colleges/' . $this->college . '/deans'), ['user_id' => (string) $first->id])->isOK());

        $page = $this->page();
        $this->assertStringContainsString('Dina Dean', $page);
        $this->assertStringContainsString('Replace dean', $page);

        $this->assertTrue($this->step('POST', $this->url('/colleges/' . $this->college . '/deans'), ['user_id' => (string) $second->id])->isOK());
        $this->assertSame('Dan Dale', (new \App\Services\Institutions\DeanAssignmentService())->byCollege($this->institution)[$this->college]['name']);

        $id = (new \App\Services\Institutions\DeanAssignmentService())->byCollege($this->institution)[$this->college]['id'];
        $this->assertTrue($this->step('POST', $this->url('/deans/' . $id . '/remove'))->isOK());
        $this->assertSame([], (new \App\Services\Institutions\DeanAssignmentService())->byCollege($this->institution));
    }

    public function testAddingACollegeCanPickItsDeanAndRefusesSomeoneWhoIsNotOne(): void
    {
        $dean = PeopleFactory::person('dean', 'Dina', 'Dean', 'dina@struct.example', $this->institution);

        $this->withHeaders(['Accept' => 'application/json']);
        $this->assertTrue($this->step('POST', $this->url('/colleges'), ['code' => 'ccs', 'name' => 'College of Computing', 'dean_user_id' => (string) $dean->id])->isOK());

        $row = db_connect()->table('colleges')->where(['institution_id' => $this->institution, 'code' => 'CCS'])->get()->getRowArray();
        $this->assertSame((int) $dean->id, (new \App\Services\Institutions\DeanAssignmentService())->byCollege($this->institution)[(int) $row['id']]['user_id']);

        $outsider = PeopleFactory::person('student', 'Sam', 'Student', 'sam@struct.example', $this->other);
        $result   = $this->step('POST', $this->url('/colleges'), ['code' => 'cba', 'name' => 'College of Business', 'dean_user_id' => (string) $outsider->id]);
        $this->assertSame(422, $result->response()->getStatusCode());
    }

    public function testARefusedDialogPostAnswers422WithTheFieldToHighlight(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', $this->url('/colleges'), ['code' => 'bad code', 'name' => 'College of Computing']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('code', $json['errors']);
        $this->assertSame('Nothing was saved.', $json['message']);
    }

    public function testAPlainPostRedirectsBackWithAMessage(): void
    {
        $response = $this->step('POST', $this->url('/colleges'), ['code' => 'PLAIN', 'name' => 'A plain college'])->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('institution/structure', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('The college was added.', $this->page());
    }

    public function testAPlainPostThatIsRefusedShowsTheReasonOnThePage(): void
    {
        $this->step('POST', $this->url('/colleges'), ['code' => 'DUP', 'name' => 'First college']);
        $this->step('POST', $this->url('/colleges'), ['code' => 'DUP', 'name' => 'Second college']);

        $this->assertStringContainsString('Another college already uses the code DUP', $this->page());
        $this->assertSame(1, db_connect()->table('colleges')->where(['institution_id' => $this->institution, 'code' => 'DUP'])->countAllResults());
    }

    public function testEachLevelCanBeCreatedAndEdited(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $this->step('POST', $this->url("/colleges/{$this->college}/departments"), ['code' => 'NEWSUB', 'name' => 'A new department']);
        $departmentId = (int) db_connect()->table('departments')->where('code', 'NEWSUB')->get()->getRowArray()['id'];

        $this->step('POST', $this->url("/departments/{$departmentId}/programs"), ['code' => 'NEWPRG', 'name' => 'A new program']);
        $programId = (int) db_connect()->table('programs')->where('code', 'NEWPRG')->get()->getRowArray()['id'];

        $this->step('POST', $this->url("/colleges/{$this->college}"), ['code' => 'RENAMED', 'name' => 'Renamed college', 'location' => 'East wing', 'established_year' => '2001']);
        $this->step('POST', $this->url("/departments/{$departmentId}"), ['code' => 'NEWSUB', 'name' => 'Renamed department']);
        $this->step('POST', $this->url("/programs/{$programId}"), ['code' => 'NEWPRG', 'name' => 'Renamed program']);

        $db = db_connect();
        $this->assertSame('RENAMED', $db->table('colleges')->where('id', $this->college)->get()->getRowArray()['code']);
        $this->assertSame('East wing', $db->table('colleges')->where('id', $this->college)->get()->getRowArray()['location']);
        $this->assertSame('Renamed department', $db->table('departments')->where('id', $departmentId)->get()->getRowArray()['name']);
        $this->assertSame('Renamed program', $db->table('programs')->where('id', $programId)->get()->getRowArray()['name']);

        foreach (['structure.department_created', 'structure.program_created', 'structure.college_updated', 'structure.department_updated', 'structure.program_updated'] as $action) {
            $this->assertSame(1, $this->audited($action), $action);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Switching off and on
    |--------------------------------------------------------------------------
    */

    public function testABusyCollegeCannotBeDeactivatedAndTheReasonIsShown(): void
    {
        $this->step('POST', $this->url("/colleges/{$this->college}/deactivate"));

        $this->assertStringContainsString('1 active department', $this->page());
        $this->assertSame(1, (int) db_connect()->table('colleges')->where('id', $this->college)->get()->getRowArray()['is_active']);
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
        $this->step('POST', $this->url("/departments/{$this->department}/deactivate"));
        $this->step('POST', $this->url("/colleges/{$this->college}/deactivate"));

        $this->assertSame(0, (int) db_connect()->table('colleges')->where('id', $this->college)->get()->getRowArray()['is_active']);
        $this->assertStringContainsString('Inactive', $this->page());

        $this->step('POST', $this->url("/colleges/{$this->college}/reactivate"));
        $this->step('POST', $this->url("/departments/{$this->department}/reactivate"));

        $this->assertSame(1, (int) db_connect()->table('colleges')->where('id', $this->college)->get()->getRowArray()['is_active']);
        $this->assertSame(1, (int) db_connect()->table('departments')->where('id', $this->department)->get()->getRowArray()['is_active']);
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
            "/colleges/{$foreign['college']}" => ['code' => 'X1', 'name' => 'Hacked name'],
            "/colleges/{$foreign['college']}/deactivate" => [],
            "/colleges/{$foreign['college']}/departments" => ['code' => 'X1', 'name' => 'Hacked name'],
            "/departments/{$foreign['department']}" => ['code' => 'X1', 'name' => 'Hacked name'],
            "/departments/{$foreign['department']}/programs" => ['code' => 'X1', 'name' => 'Hacked name'],
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

        $this->assertStringContainsString('data-college="' . $this->college . '"', $body, 'it can be looked at');
        $this->assertStringContainsString('data-lock="read_only"', $body);
        $this->assertStringNotContainsString('Add a college', $body);
        $this->assertStringNotContainsString('id="structure-college"', $body);

        $response = $this->step('POST', $this->url('/colleges'), ['code' => 'LATE', 'name' => 'Too late college'])->response();

        $this->assertSame(0, db_connect()->table('colleges')->where('code', 'LATE')->countAllResults());
        $this->assertContains($response->getStatusCode(), [302, 403]);
    }

    public function testTheAppsMenuLinksAcademicsToTheStructure(): void
    {
        $body = html_entity_decode($this->step('GET', 'institution/dashboard')->response()->getBody());

        // The tile is a live link (an unbuilt one is inert and says "Coming soon").
        $this->assertSame(1, preg_match('/<a\s[^>]*href="[^"]*institution\/structure"[^>]*data-app-tile="academics"/s', $body));
    }
}
