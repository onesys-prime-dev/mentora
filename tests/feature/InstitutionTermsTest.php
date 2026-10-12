<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The Terms page and what it posts: access, the list and its overlap badge,
 * create / edit / start through the dialog's JSON and plain posts, warnings
 * going with the message, closed terms, foreign ids answering 404, and a
 * read-only institution.
 *
 * @internal
 */
final class InstitutionTermsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('TERMSIA', false);
        $this->other       = SisFixture::institution('TERMSOT');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@terms.example', $this->institution);

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

    private function page(): string
    {
        return $this->step('GET', 'institution/terms')->response()->getBody();
    }

    private function term(array $override = []): array
    {
        return $override + ['name' => 'First Semester', 'academic_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2026-12-20'];
    }

    private function add(array $override = []): int
    {
        $this->step('POST', 'institution/terms', $this->term($override));

        return (int) db_connect()->table('academic_terms')->where(['institution_id' => $this->institution, 'name' => $this->term($override)['name']])->get()->getRowArray()['id'];
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
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@terms.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/terms')->response()->getStatusCode());
        $this->step('POST', 'institution/terms', $this->term(['name' => 'Hacked term']));

        $this->assertSame(0, db_connect()->table('academic_terms')->where('name', 'Hacked term')->countAllResults());
    }

    public function testTheEmptyPageOffersTheFirstTerm(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('No terms yet', $body);
        $this->assertStringContainsString('Add a term', $body);
        $this->assertStringContainsString('id="term-form"', $body);
    }

    public function testATermIsAddedThroughTheDialogAndListedUpcoming(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', 'institution/terms', $this->term());

        $this->assertTrue($result->isOK());
        $this->assertSame('The term was added.', json_decode($result->getJSON(), true)['message']);

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->page();

        $this->assertStringContainsString('First Semester', $body);
        $this->assertStringContainsString('2026-2027', $body);
        $this->assertStringContainsString('1 Aug 2026 to 20 Dec 2026', $body);
        $this->assertStringContainsString('Upcoming', $body);
        $this->assertStringContainsString('Start term', $body);
    }

    public function testARefusedTermAnswers422WithTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/terms', $this->term(['end_date' => '2026-07-01']));

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('end_date', json_decode($result->getJSON(), true)['errors']);
    }

    public function testAnOverlapIsAllowedAndTheWarningTravelsWithTheMessage(): void
    {
        $this->add();

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', 'institution/terms', $this->term(['name' => 'Summer', 'start_date' => '2026-12-01', 'end_date' => '2027-02-01']));
        $json   = json_decode($result->getJSON(), true);

        $this->assertTrue($result->isOK());
        $this->assertStringContainsString('overlaps First Semester', $json['message']);
        $this->assertCount(1, $json['warnings']);

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->page();

        $this->assertStringContainsString('data-overlap="First Semester"', $body);
        $this->assertStringContainsString('data-alert="warning"', $body, 'the warning shows as a warning');
    }

    public function testATermCanBeEditedStartedAndMovedBack(): void
    {
        $id = $this->add();

        $this->step('POST', "institution/terms/{$id}", $this->term(['name' => 'Fall Semester']));
        $this->assertSame('Fall Semester', db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray()['name']);

        $this->step('POST', "institution/terms/{$id}/start");
        $this->assertSame('active', db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertStringContainsString('Back to upcoming', $this->page());

        $this->step('POST', "institution/terms/{$id}/upcoming");
        $this->assertSame('upcoming', db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray()['status']);

        foreach (['created', 'updated', 'started', 'set_upcoming'] as $what) {
            $this->assertSame(1, db_connect()->table('audit_logs')->where('action', "structure.term_{$what}")->countAllResults(), $what);
        }
    }

    public function testACompletedTermShowsNoActionsAndRefusesChanges(): void
    {
        $id = $this->add();
        db_connect()->table('academic_terms')->where('id', $id)->update(['status' => 'completed']);

        $body = $this->page();
        $this->assertStringContainsString('Completed', $body);
        $this->assertStringNotContainsString('Start term', $body);
        $this->assertStringNotContainsString('data-dialog-title="Edit First Semester"', $body);

        $this->step('POST', "institution/terms/{$id}", $this->term(['name' => 'Renamed']));
        $this->assertSame('First Semester', db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray()['name']);
        $this->assertStringContainsString('closed', $this->page());
    }

    public function testAnotherInstitutionsTermsAreNeverShownOrChanged(): void
    {
        $foreign = (int) db_connect()->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        $this->assertStringNotContainsString('First Term', $this->page());

        foreach ([["institution/terms/{$foreign}", $this->term(['name' => 'Hacked'])], ["institution/terms/{$foreign}/start", []], ["institution/terms/{$foreign}/upcoming", []]] as [$uri, $data]) {
            try {
                $this->call('POST', $uri, $data);
                $this->fail("{$uri} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('First Term', db_connect()->table('academic_terms')->where('id', $foreign)->get()->getRowArray()['name']);
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        $id = $this->add();
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $body = $this->page();

        $this->assertStringContainsString('First Semester', $body);
        $this->assertStringContainsString('data-lock="read_only"', $body);
        $this->assertStringNotContainsString('Add a term', $body);

        $this->step('POST', "institution/terms/{$id}/start");
        $this->assertSame('upcoming', db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray()['status']);
    }

    public function testTheAppsTileAndTheAcademicsTabsReachTheTerms(): void
    {
        $dashboard = html_entity_decode($this->step('GET', 'institution/dashboard')->response()->getBody());

        $this->assertSame(1, preg_match('/<a\s[^>]*href="[^"]*institution\/terms"[^>]*data-app-tile="terms"/s', $dashboard));
        $this->assertStringContainsString('institution/terms', html_entity_decode($this->step('GET', 'institution/structure')->response()->getBody()));
        $this->assertStringContainsString('institution/terms', html_entity_decode($this->step('GET', 'institution/courses')->response()->getBody()));
    }
}
