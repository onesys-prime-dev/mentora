<?php

declare(strict_types=1);

use App\Services\Institutions\OfferingService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The close-a-term pages: access, the confirm page that says what will
 * change, the password in front of closing, the result on the Terms page,
 * reopening, foreign terms answering 404, and a read-only institution.
 *
 * @internal
 */
final class InstitutionTermCloseTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private int $term;

    private int $offering;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('TCFA');
        $this->other       = SisFixture::institution('TCFB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@tcf.example', $this->institution);

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro to Computing', 'units' => 3]);
        $course = (int) $db->insertID();

        $this->term     = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $this->offering = (new OfferingService())->create($this->institution, ['course_id' => $course, 'term_id' => $this->term, 'section_code' => 'A', 'capacity' => '40'], $this->admin);

        CourseFactory::enroll($this->offering, PeopleFactory::person('student', 'Ann', 'Student', null, $this->institution));

        $this->signIn($this->admin);
    }

    private function signIn(User $user, bool $confirmed = true): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);

        if ($confirmed) {
            $_SESSION['reauth'] = ['user' => $user->id, 'at' => time()];
        } else {
            unset($_SESSION['reauth']);
        }

        $this->withSession();
    }

    private function termStatus(): string
    {
        return (string) db_connect()->table('academic_terms')->where('id', $this->term)->get()->getRowArray()['status'];
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
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@tcf.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', "institution/terms/{$this->term}/close")->response()->getStatusCode());

        $this->step('POST', "institution/terms/{$this->term}/close");

        $this->assertSame('active', $this->termStatus());
    }

    public function testTheConfirmPageSaysExactlyWhatWillChange(): void
    {
        $body = $this->step('GET', "institution/terms/{$this->term}/close")->response()->getBody();

        $this->assertStringContainsString('data-close-totals', $body);
        $this->assertStringContainsString('Offerings completed', $body);
        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString('cannot change anything', $body);
        $this->assertStringContainsString('Close ', $body);
        $this->assertSame('active', $this->termStatus(), 'looking changes nothing');
    }

    public function testClosingNeedsThePasswordThenClosesAndTheTermsPageOffersReopen(): void
    {
        $this->signIn($this->admin, false);

        $refused = $this->step('POST', "institution/terms/{$this->term}/close");

        $this->assertStringEndsWith('/reauth', $refused->response()->getHeaderLine('Location'));
        $this->assertSame('active', $this->termStatus());

        $this->signIn($this->admin, true);
        $this->step('POST', "institution/terms/{$this->term}/close");

        $this->assertSame('completed', $this->termStatus());

        $page = $this->step('GET', 'institution/terms')->response()->getBody();
        $this->assertStringContainsString('The term was closed: 1 offering and 1 enrolment completed', $page);
        $this->assertStringContainsString('Reopen', $page);
    }

    public function testAClosedTermCanBeReopenedBehindThePassword(): void
    {
        $this->step('POST', "institution/terms/{$this->term}/close");

        $this->signIn($this->admin, false);
        $this->step('POST', "institution/terms/{$this->term}/reopen");
        $this->assertSame('completed', $this->termStatus());

        $this->signIn($this->admin, true);
        $this->step('POST', "institution/terms/{$this->term}/reopen");

        $this->assertSame('active', $this->termStatus());
        $this->assertStringContainsString('The term is open again', $this->step('GET', 'institution/terms')->response()->getBody());
    }

    public function testTheConfirmPageOfAClosedTermSaysSoAndOffersNoButton(): void
    {
        $this->step('POST', "institution/terms/{$this->term}/close");

        $body = $this->step('GET', "institution/terms/{$this->term}/close")->response()->getBody();

        $this->assertStringContainsString('already closed', $body);
        $this->assertStringNotContainsString('data-close-totals', $body);
    }

    public function testAnotherInstitutionsTermIsNeverShownOrClosed(): void
    {
        $theirs = (int) db_connect()->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        foreach ([['GET', "institution/terms/{$theirs}/close"], ['POST', "institution/terms/{$theirs}/close"], ['POST', "institution/terms/{$theirs}/reopen"]] as [$method, $uri]) {
            try {
                $this->call($method, $uri);
                $this->fail("{$uri} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('active', (string) db_connect()->table('academic_terms')->where('id', $theirs)->get()->getRowArray()['status']);
    }

    public function testAReadOnlyInstitutionCanLookButNotClose(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->step('POST', "institution/terms/{$this->term}/close");

        $this->assertSame('active', $this->termStatus());
    }
}
