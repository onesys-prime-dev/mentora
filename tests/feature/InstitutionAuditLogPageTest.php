<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionAuditLog;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The institution's own audit log: who may open it, that it holds only its
 * institution's entries whatever is asked for, that no source address and no
 * connection detail reaches the page or the export, the filters, and that
 * exporting is itself recorded.
 *
 * @internal
 */
final class InstitutionAuditLogPageTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->institution = SisFixture::institution('AUDA');
        $this->other       = SisFixture::institution('AUDB');
        $this->root        = PeopleFactory::person('admin', 'Root', 'Admin', 'root@aud.example');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@aud.example', $this->institution);

        $this->signIn($this->admin);

        // Signing in is recorded too; the cases below count what they add themselves.
        db_connect()->table('audit_logs')->where('id >', 0)->delete();
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** @param array<string, mixed> $values */
    private function entry(array $values = []): void
    {
        db_connect()->table('audit_logs')->insert($values + [
            'actor_user_id'  => (int) $this->admin->id,
            'actor_label'    => 'ada@aud.example',
            'acting_role'    => 'institutional_admin',
            'action'         => 'term.created',
            'target_type'    => 'term',
            'target_id'      => '7',
            'institution_id' => $this->institution,
            'ip_address'     => '203.0.113.9',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    private function page(string $query = ''): string
    {
        return $this->step('GET', 'institution/audit' . ($query === '' ? '' : '?' . $query))->response()->getBody();
    }

    private function export(string $query = ''): string
    {
        return $this->step('GET', 'institution/audit/export' . ($query === '' ? '' : '?' . $query))->response()->getBody();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanOpenOrExportIt(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@aud.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/audit')->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', 'institution/audit/export')->response()->getStatusCode());
    }

    public function testItListsOnlyTheInstitutionsOwnEntries(): void
    {
        $this->entry(['action' => 'term.created']);
        $this->entry(['action' => 'secret.elsewhere', 'institution_id' => $this->other, 'actor_label' => 'who@other.example']);
        $this->entry(['action' => 'secret.nowhere', 'institution_id' => null, 'actor_label' => 'nobody@none.example']);

        $body = $this->page();

        $this->assertStringContainsString('Term created', $body);
        $this->assertStringNotContainsString('Secret elsewhere', $body);
        $this->assertStringNotContainsString('who@other.example', $body);
        $this->assertStringNotContainsString('nobody@none.example', $body);
    }

    public function testAnotherInstitutionCannotBeAskedFor(): void
    {
        $this->entry(['action' => 'secret.elsewhere', 'institution_id' => $this->other]);

        $this->assertStringNotContainsString('Secret elsewhere', $this->page('institution=' . $this->other));
        $this->assertStringNotContainsString('secret.elsewhere', $this->export('institution=' . $this->other));
        $this->assertStringContainsString('No entries match these filters', $this->page('action=secret.elsewhere'));
    }

    public function testTheChoicesComeOnlyFromItsOwnEntries(): void
    {
        $this->entry(['action' => 'term.created']);
        $this->entry(['action' => 'secret.elsewhere', 'institution_id' => $this->other, 'target_type' => 'hidden_kind']);

        $log = new InstitutionAuditLog($this->institution);

        $this->assertSame(['term.created'], array_keys($log->actionOptions()));
        $this->assertSame(['term'], array_keys($log->targetTypeOptions()));
    }

    public function testASourceAddressIsNeverShownSearchedOrExported(): void
    {
        $this->entry(['ip_address' => '198.51.100.77']);

        // Even when the vendor has switched disclosure on for itself.
        (new App\Services\Admin\PlatformSettings())->set('auditShowSourceIp', '1');

        $this->assertStringNotContainsString('198.51.100.77', $this->page());
        $this->assertStringNotContainsString('Source IP', $this->page());
        $this->assertStringNotContainsString('198.51.100.77', $this->export());
        $this->assertStringNotContainsString('ip_address', $this->export());
        $this->assertStringContainsString('No entries match these filters', $this->page('q=198.51.100'), 'an address is not searchable');
    }

    public function testTheVendorsStaffAreShownAsSupportNotByAccount(): void
    {
        $this->entry(['actor_user_id' => (int) $this->root->id, 'actor_label' => 'root@aud.example', 'acting_role' => 'admin', 'action' => 'subscription.renewed']);

        $body = $this->page();

        $this->assertStringContainsString('Mentora support', $body);
        $this->assertStringContainsString('Subscription renewed', $body);
        $this->assertStringNotContainsString('root@aud.example', $body);
    }

    public function testViewingAsIsShownWithWhoDidIt(): void
    {
        $student = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@aud.example', $this->institution);

        $this->entry(['actor_user_id' => (int) $student->id, 'actor_label' => 'stu@aud.example', 'acting_role' => 'student', 'impersonator_user_id' => (int) $this->admin->id, 'action' => 'course.viewed']);
        $this->entry(['actor_user_id' => (int) $student->id, 'actor_label' => 'stu@aud.example', 'acting_role' => 'student', 'impersonator_user_id' => (int) $this->root->id, 'action' => 'course.viewed']);

        $body = $this->page();

        $this->assertStringContainsString('viewing as, by ada@aud.example', $body);
        $this->assertStringContainsString('viewing as, by Mentora support', $body);
        $this->assertStringNotContainsString('root@aud.example', $body);
    }

    public function testTheConnectionsSettingsAreNotShown(): void
    {
        $this->entry(['action' => 'sis.connection_saved', 'actor_user_id' => (int) $this->root->id, 'new_values' => json_encode(['inbound_folder' => '/srv/sis/aud-in'])]);

        $this->assertStringNotContainsString('/srv/sis/aud-in', $this->page());
        $this->assertStringContainsString('Kept by Mentora', $this->page());
        $this->assertStringNotContainsString('/srv/sis/aud-in', $this->export());
    }

    public function testChangesAreShownBeforeAndAfter(): void
    {
        $this->entry(['action' => 'institution.profile_updated', 'old_values' => '{"name":"Old name"}', 'new_values' => '{"name":"New name"}']);

        $body = $this->page();

        $this->assertStringContainsString('Old name', $body);
        $this->assertStringContainsString('New name', $body);
    }

    public function testEachFilterNarrowsTheList(): void
    {
        $this->entry(['action' => 'term.created', 'created_at' => '2026-03-10 09:00:00']);
        $this->entry(['action' => 'person.invited', 'target_type' => 'user', 'actor_label' => 'bea@aud.example', 'actor_user_id' => null, 'created_at' => '2026-05-20 09:00:00']);

        $this->assertStringContainsString('1 entry match', $this->page('action=person.invited'));
        $this->assertStringContainsString('1 entry match', $this->page('target_type=user'));
        $this->assertStringContainsString('1 entry match', $this->page('actor=bea@'));
        $this->assertStringContainsString('1 entry match', $this->page('from=2026-05-01'));
        $this->assertStringContainsString('1 entry match', $this->page('to=2026-04-01'));
        $this->assertStringContainsString('1 entry match', $this->page('q=invited'));
        $this->assertStringContainsString('No entries match these filters', $this->page('q=nothing-like-this'));
    }

    public function testItPagesAndSorts(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->entry(['target_id' => (string) $i]);
        }

        $first = $this->page();

        $this->assertStringContainsString('30 entries', $first);
        $this->assertStringContainsString('Page 1 of 2', $first);
        $this->assertStringContainsString('Page 2 of 2', $this->page('page=2'));
        $this->assertStringContainsString('aria-sort', $this->page('sort=action&dir=asc'));
    }

    public function testAnUnknownSortOrAWildcardCannotBreakIt(): void
    {
        $this->entry();

        $this->assertSame(200, $this->step('GET', 'institution/audit?sort=ip_address;drop&dir=sideways&page=-4&per_page=abc')->response()->getStatusCode());
        $this->assertStringContainsString('No entries match these filters', $this->page('q=%25'));
    }

    public function testExportingIsRecordedAndSpreadsheetFormulasAreDefused(): void
    {
        $this->entry(['actor_label' => '=cmd@aud.example', 'actor_user_id' => null]);

        $csv = $this->export('q=cmd');

        $this->assertStringContainsString("'=cmd@aud.example", $csv);

        $row = db_connect()->table('audit_logs')->where('action', 'institution.audit_exported')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame($this->institution, (int) $row['institution_id']);
        $this->assertSame((int) $this->admin->id, (int) $row['actor_user_id']);
        $this->assertStringContainsString('"rows":1', (string) $row['new_values']);
    }

    public function testAReadOnlyInstitutionCanStillReadAndExport(): void
    {
        $this->entry();
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertSame(200, $this->step('GET', 'institution/audit')->response()->getStatusCode());
        $this->assertSame(200, $this->step('GET', 'institution/audit/export')->response()->getStatusCode());
    }

    public function testThereIsATileForIt(): void
    {
        $dashboard = html_entity_decode($this->step('GET', 'institution/dashboard')->response()->getBody());

        $this->assertSame(1, preg_match('/<a\s[^>]*href="[^"]*institution\/audit"[^>]*data-app-tile="audit"/s', $dashboard));
    }

    public function testActionKeysHavePlainLabels(): void
    {
        $this->assertSame('Signed in', InstitutionAuditLog::label('auth.login'));
        $this->assertSame('Offering status changed', InstitutionAuditLog::label('offering.status_changed'));
    }
}
