<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradingSchemeService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The Academic tab of the institution settings: access, what it shows (and
 * whether each value is the institution's or the platform's), saving and
 * refusing, a new offering starting from what was saved, resetting, and a
 * read-only institution.
 *
 * @internal
 */
final class InstitutionAcademicDefaultsTest extends RouteTestCase
{
    private int $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        GradeFactory::systemDefault(75.0, ['Quizzes' => 30, 'Exams' => 70]);

        $this->institution = SisFixture::institution('ACFA');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@acf.example', $this->institution);

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

    private function stored(): ?array
    {
        return db_connect()->table('institution_academic_defaults')->where('institution_id', $this->institution)->get()->getRowArray();
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
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@acf.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/settings/academic')->response()->getStatusCode());

        $this->step('POST', 'institution/settings/academic', ['passing_percentage' => '10']);

        $this->assertNull($this->stored());
    }

    public function testThePageShowsTheValuesInForceAndWhereTheyCameFrom(): void
    {
        $body = $this->step('GET', 'institution/settings/academic')->response()->getBody();

        $this->assertStringContainsString('data-academic-form', $body);
        $this->assertStringContainsString('value="75"', $body);
        $this->assertStringContainsString('value="Quizzes"', $body);
        $this->assertStringContainsString('Using the platform value.', $body);
        $this->assertStringContainsString('Using the platform categories.', $body);
        $this->assertStringNotContainsString('Go back to the platform values', $body);
    }

    public function testSavingChangesTheValuesAndTheNextVisitSaysTheyAreTheInstitutions(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', 'institution/settings/academic', [
            'passing_percentage' => '60', 'inactive_days' => '21', 'behind_below_percent' => '40', 'overdue_grading_days' => '5',
            'category_name' => ['Projects', 'Finals'], 'category_weight' => ['70', '30'],
        ]);

        $this->assertTrue($result->isOK());
        $this->assertSame('The academic defaults were saved.', json_decode($result->getJSON(), true)['message']);

        $stored = $this->stored();
        $this->assertSame(21, (int) $stored['inactive_days']);
        $this->assertSame(5, (int) $stored['overdue_grading_days']);

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->step('GET', 'institution/settings/academic')->response()->getBody();

        $this->assertStringContainsString('value="Projects"', $body);
        $this->assertStringContainsString('Set by your institution.', $body);
        $this->assertStringContainsString('Go back to the platform values', $body);
    }

    public function testRefusedInputAnswers422WithTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/settings/academic', ['passing_percentage' => '150']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('passing_percentage', json_decode($result->getJSON(), true)['errors']);
        $this->assertNull($this->stored());
    }

    public function testWeightsThatDoNotAddUpAreRefusedBesideTheCategories(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/settings/academic', ['category_name' => ['A', 'B'], 'category_weight' => ['60', '30']]);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertStringContainsString('add up to 90', json_decode($result->getJSON(), true)['errors']['categories']);
    }

    public function testANewOfferingCreatedAfterwardsStartsFromTheSavedDefaults(): void
    {
        $this->step('POST', 'institution/settings/academic', ['passing_percentage' => '60', 'category_name' => ['Projects', 'Finals'], 'category_weight' => ['70', '30']]);

        $db      = db_connect();
        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro', 'units' => 3]);
        $course = (int) $db->insertID();
        $term   = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $this->step('POST', 'institution/offerings', ['course_id' => (string) $course, 'term_id' => (string) $term, 'section_code' => 'A', 'capacity' => '30']);
        $offering = (int) $db->table('course_offerings')->where('course_id', $course)->get()->getRowArray()['id'];

        $scheme = (new GradingSchemeService())->forOffering($offering);

        $this->assertSame(60.0, (float) $scheme['passing_percentage']);
        $this->assertSame(
            [['Projects', 70.0], ['Finals', 30.0]],
            array_map(static fn (array $r): array => [$r['name'], (float) $r['weight_percentage']], $db->table('grade_categories')->where('grading_scheme_id', $scheme['id'])->orderBy('sort_order')->get()->getResultArray()),
        );
    }

    public function testResettingGoesBackToThePlatformValues(): void
    {
        $this->step('POST', 'institution/settings/academic', ['passing_percentage' => '60', 'inactive_days' => '21']);
        $this->assertNotNull($this->stored());

        $this->step('POST', 'institution/settings/academic/reset');

        $this->assertNull($this->stored());
        $this->assertStringContainsString('Using the platform value.', $this->step('GET', 'institution/settings/academic')->response()->getBody());
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $page = $this->step('GET', 'institution/settings/academic')->response()->getBody();
        $this->assertStringContainsString('data-lock="read_only"', $page);
        $this->assertStringNotContainsString('Save academic defaults', $page);

        $this->step('POST', 'institution/settings/academic', ['passing_percentage' => '10']);

        $this->assertNull($this->stored());
    }

    public function testTheSettingsTabsLinkToIt(): void
    {
        $this->assertStringContainsString('settings/academic', html_entity_decode($this->step('GET', 'institution/settings')->response()->getBody()));
    }
}
