<?php

declare(strict_types=1);

use App\Services\Institutions\ClassSectionService;
use App\Services\Institutions\OfferingService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The address behind the autocomplete in the "add students" boxes: who may ask, that it answers JSON, that it only
 * ever shows the administrator's own institution, and that a foreign offering or section is a 404.
 *
 * @internal
 */
final class InstitutionStudentSuggestTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private int $offering;

    private int $section;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('SGFA');
        $this->other       = SisFixture::institution('SGFB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@suggest.example', $this->institution);

        $db      = db_connect();
        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $term    = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro', 'units' => 3]);

        $this->offering = (new OfferingService())->create($this->institution, ['course_id' => (string) $db->insertID(), 'term_id' => (string) $term, 'section_code' => 'A', 'capacity' => '40'], $this->admin);
        $this->section  = (new ClassSectionService())->create($this->institution, ['term_id' => (string) $term, 'program_id' => (string) $program, 'year_level' => '1', 'code' => 'BSIT 1-A', 'capacity' => '40'], $this->admin);

        $this->signIn($this->admin);
        $this->withHeaders(['Accept' => 'application/json']);
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
        $user = PeopleFactory::person('student', $first, 'Student', strtolower($first) . '@suggest.example', $institution ?? $this->institution);
        db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['student_number' => $number]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function ask(string $query): array
    {
        return json_decode($this->step('GET', 'institution/enrolments/suggest' . $query)->getJSON(), true);
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanAsk(string $role): void
    {
        $this->student('Ann', '2026-001');
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@suggest.example", $role === 'admin' ? null : $this->institution));

        $result = $this->step('GET', 'institution/enrolments/suggest?q=ann');

        $this->assertSame(302, $result->response()->getStatusCode());
        $this->assertStringNotContainsString('ann@suggest.example', (string) $result->response()->getBody());
    }

    public function testItAnswersJsonWithTheStudentsAndTheFieldsTheListShows(): void
    {
        $this->student('Ann', '2026-001');

        $result = $this->step('GET', 'institution/enrolments/suggest?q=ann');

        $this->assertTrue($result->isOK());
        $this->assertStringContainsString('application/json', $result->response()->getHeaderLine('Content-Type'));
        $this->assertSame(['students' => [['name' => 'Ann Student', 'student_number' => '2026-001', 'email' => 'ann@suggest.example']]], json_decode($result->getJSON(), true));
    }

    public function testAnotherInstitutionsStudentIsNeverOffered(): void
    {
        $this->student('Ann', '2026-001');
        $this->student('Anya', '2026-777', $this->other);

        $emails = array_column($this->ask('?q=an')['students'], 'email');

        $this->assertSame(['ann@suggest.example'], $emails);
        $this->assertStringNotContainsString('anya@suggest.example', json_encode($this->ask('?q=2026-777')));
    }

    public function testAQueryTooShortOrMissingAnswersAnEmptyList(): void
    {
        $this->student('Ann', '2026-001');

        $this->assertSame(['students' => []], $this->ask('?q=a'));
        $this->assertSame(['students' => []], $this->ask(''));
    }

    public function testThoseWhoAlreadyHaveTheOfferingsPlaceAreLeftOut(): void
    {
        $this->student('Sam', '2026-010');
        $this->student('Sid', '2026-011');
        (new \App\Services\Institutions\EnrolmentService())->add($this->institution, $this->offering, '2026-010', false, $this->admin);

        $this->assertSame(['sid@suggest.example'], array_column($this->ask("?q=si&offering={$this->offering}")['students'], 'email'));
        $this->assertSame(['sam@suggest.example'], array_column($this->ask('?q=sa')['students'], 'email'), 'with no offering named, nobody is left out');
    }

    public function testThoseAlreadyInTheSectionAreLeftOut(): void
    {
        $this->student('Sam', '2026-010');
        $this->student('Sid', '2026-011');
        (new ClassSectionService())->addStudents($this->institution, $this->section, '2026-010', false, $this->admin);

        $this->assertSame(['sid@suggest.example'], array_column($this->ask("?q=si&section={$this->section}")['students'], 'email'));
        $this->assertSame([], $this->ask("?q=sa&section={$this->section}")['students']);
    }

    public function testAForeignOfferingOrSectionIs404(): void
    {
        $theirs = $this->foreign();

        // Their own are fine, so the 404s below are about ownership and not about a missing route.
        $this->assertTrue($this->step('GET', "institution/enrolments/suggest?q=ann&offering={$this->offering}")->isOK());
        $this->assertTrue($this->step('GET', "institution/enrolments/suggest?q=ann&section={$this->section}")->isOK());

        foreach (["?q=ann&offering={$theirs['offering']}", "?q=ann&section={$theirs['section']}"] as $query) {
            try {
                $status = $this->step('GET', 'institution/enrolments/suggest' . $query)->response()->getStatusCode();
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $status = 404;
            }

            $this->assertSame(404, $status, $query);
        }
    }

    /** @return array{offering: int, section: int} */
    private function foreign(): array
    {
        $db      = db_connect();
        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->other])->getRow()->id;
        $term    = (int) $db->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];
        $admin   = PeopleFactory::person('institutional_admin', 'Olga', 'Other', 'olga@suggest.example', $this->other);

        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'XX101', 'course_title' => 'Theirs', 'units' => 3]);

        return [
            'offering' => (new OfferingService())->create($this->other, ['course_id' => (string) $db->insertID(), 'term_id' => (string) $term, 'section_code' => 'Z', 'capacity' => '40'], $admin),
            'section'  => (new ClassSectionService())->create($this->other, ['term_id' => (string) $term, 'program_id' => (string) $program, 'year_level' => '1', 'code' => 'THEIRS', 'capacity' => '40'], $admin),
        ];
    }
}
