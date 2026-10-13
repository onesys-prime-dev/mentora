<?php

declare(strict_types=1);

use App\Services\Institutions\OfferingService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The copy-offerings pages: access, choosing terms, the preview of what
 * will be copied, copying, a clash, foreign terms answering 404, a read-only
 * institution, and the Terms page's entry points and the reopen rule.
 *
 * @internal
 */
final class InstitutionOfferingCopyTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private int $source;

    private int $target;

    private int $offering;

    private int $course;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('CPFA');
        $this->other       = SisFixture::institution('CPFB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@cpf.example', $this->institution);

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro to Computing', 'units' => 3]);
        $this->course = (int) $db->insertID();

        $this->source = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $db->table('academic_terms')->insert(['institution_id' => $this->institution, 'name' => 'Next Term', 'academic_year' => '2027-2028', 'start_date' => '2027-01-10', 'end_date' => '2027-05-30', 'status' => 'upcoming', 'created_at' => date('Y-m-d H:i:s')]);
        $this->target = (int) $db->insertID();

        $this->offering = (new OfferingService())->create($this->institution, ['course_id' => $this->course, 'term_id' => $this->source, 'section_code' => 'A', 'capacity' => '30'], $this->admin);

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

    private function copies(): int
    {
        return db_connect()->table('course_offerings')->where('copied_from_offering_id', $this->offering)->countAllResults();
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
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@cpf.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/terms/copy')->response()->getStatusCode());

        $this->step('POST', 'institution/terms/copy', ['source' => (string) $this->source, 'target' => (string) $this->target, 'offerings' => [(string) $this->offering]]);

        $this->assertSame(0, $this->copies());
    }

    public function testTheFirstStepOffersTheTerms(): void
    {
        $body = $this->step('GET', 'institution/terms/copy')->response()->getBody();

        $this->assertStringContainsString('data-stepper', $body);
        $this->assertStringContainsString('Copy from', $body);
        $this->assertStringContainsString('Next Term', $body);
        $this->assertStringNotContainsString('data-copy-form', $body);
    }

    public function testChoosingTermsShowsTheOfferingsAndWhatWillBeCopied(): void
    {
        $body = $this->step('GET', "institution/terms/copy?source={$this->source}&target={$this->target}")->response()->getBody();

        $this->assertStringContainsString('data-copy-summary', $body);
        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString('Not copied:', $body);
        $this->assertStringContainsString('students, enrolments, submissions', $body);
        $this->assertMatchesRegularExpression('/move \d+ days later/', $body);
        $this->assertStringContainsString('Copy the ticked offerings', $body);
        $this->assertSame(0, $this->copies(), 'looking changes nothing');
    }

    public function testTickedOfferingsAreCopiedAndTheOfferingsPageShowsThem(): void
    {
        $this->step('POST', 'institution/terms/copy', ['source' => (string) $this->source, 'target' => (string) $this->target, 'offerings' => [(string) $this->offering]]);

        $this->assertSame(1, $this->copies());

        $page = $this->step('GET', "institution/offerings?term={$this->target}")->response()->getBody();
        $this->assertStringContainsString('Copied 1 offering', $page);
        $this->assertStringContainsString('IT101', $page);
    }

    public function testAClashIsShownAndRefusedWithNothingCopied(): void
    {
        (new OfferingService())->create($this->institution, ['course_id' => $this->course, 'term_id' => $this->target, 'section_code' => 'A', 'capacity' => '10'], $this->admin);

        $preview = $this->step('GET', "institution/terms/copy?source={$this->source}&target={$this->target}")->response()->getBody();
        $this->assertStringContainsString('Section already there', $preview);

        $this->step('POST', 'institution/terms/copy', ['source' => (string) $this->source, 'target' => (string) $this->target, 'offerings' => [(string) $this->offering]]);

        $this->assertSame(0, $this->copies());
        $this->assertStringContainsString('already has a section', $this->step('GET', "institution/terms/copy?source={$this->source}&target={$this->target}")->response()->getBody());
    }

    public function testNothingTickedIsRefused(): void
    {
        $this->step('POST', 'institution/terms/copy', ['source' => (string) $this->source, 'target' => (string) $this->target]);

        $this->assertSame(0, $this->copies());
        $this->assertStringContainsString('Tick at least one offering', $this->step('GET', "institution/terms/copy?source={$this->source}&target={$this->target}")->response()->getBody());
    }

    public function testAnotherInstitutionsTermsAreNeverShownOrUsed(): void
    {
        $theirs = (int) db_connect()->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        foreach ([
            ['GET', "institution/terms/copy?source={$theirs}&target={$this->target}", []],
            ['POST', 'institution/terms/copy', ['source' => (string) $theirs, 'target' => (string) $this->target, 'offerings' => ['1']]],
        ] as [$method, $uri, $data]) {
            try {
                $this->call($method, $uri, $data);
                $this->fail("{$uri} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAReadOnlyInstitutionCanLookButNotCopy(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertSame(200, $this->step('GET', "institution/terms/copy?source={$this->source}&target={$this->target}")->response()->getStatusCode());

        $this->step('POST', 'institution/terms/copy', ['source' => (string) $this->source, 'target' => (string) $this->target, 'offerings' => [(string) $this->offering]]);

        $this->assertSame(0, $this->copies());
    }

    public function testTheTermsPageLinksToItAndAClosedTermThatWasCopiedStaysClosed(): void
    {
        $this->assertStringContainsString('terms/copy', html_entity_decode($this->step('GET', 'institution/terms')->response()->getBody()));

        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();

        $this->step('POST', "institution/terms/{$this->source}/close");
        $this->assertStringContainsString('Reopen', $this->step('GET', 'institution/terms')->response()->getBody());

        $this->step('POST', 'institution/terms/copy', ['source' => (string) $this->source, 'target' => (string) $this->target, 'offerings' => [(string) $this->offering]]);

        $page = $this->step('GET', 'institution/terms')->response()->getBody();
        $this->assertStringContainsString('data-copied-note', $page);
        $this->assertStringNotContainsString("terms/{$this->source}/reopen", html_entity_decode($page));

        $this->step('POST', "institution/terms/{$this->source}/reopen");
        $this->assertSame('completed', db_connect()->table('academic_terms')->where('id', $this->source)->get()->getRowArray()['status']);
    }
}
