<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The archive page (past and deleted institutions) and the two actions on
 * deleted ones: restore and purge, both behind the password, purge also behind
 * the typed name.
 *
 * @internal
 */
final class AdminArchivePageTest extends RouteTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('audit_logs')->emptyTable();
    }

    private function institution(string $code, string $status, ?string $deletedAt = null, ?string $name = null): int
    {
        db_connect()->table('institutions')->insert([
            'name'       => $name ?? "Institution {$code}",
            'code'       => $code,
            'status'     => $status,
            'deleted_at' => $deletedAt,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    private function deletedDaysAgo(string $code, int $days, ?string $name = null): int
    {
        return $this->institution($code, 'past', date('Y-m-d H:i:s', strtotime("-{$days} days")), $name);
    }

    private function exists(int $id): bool
    {
        return db_connect()->table('institutions')->where('id', $id)->countAllResults() > 0;
    }

    private function isDeleted(int $id): bool
    {
        return db_connect()->table('institutions')->where('id', $id)->get()->getRowArray()['deleted_at'] !== null;
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    /** @param array<string, string> $data */
    private function submit(string $uri, array $data = []): TestResponse
    {
        return $this->step('POST', $uri, $data);
    }

    private function body(string $uri = 'admin/archive'): string
    {
        return $this->step('GET', $uri)->response()->getBody();
    }

    private function location(TestResponse $response): string
    {
        return $response->response()->getHeaderLine('Location');
    }

    private function auditCount(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /*
    |--------------------------------------------------------------------------
    | Who may use it
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanSeeOrUseTheArchive(string $role): void
    {
        $id = $this->deletedDaysAgo('GONE', 5, 'Gone College');

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        $this->assertSame(302, $this->step('GET', 'admin/archive')->response()->getStatusCode());

        $this->submit("admin/institutions/{$id}/purge", ['confirm_name' => 'Gone College']);
        $this->submit("admin/institutions/{$id}/restore");

        $this->assertTrue($this->exists($id));
        $this->assertTrue($this->isDeleted($id), 'untouched');
    }

    public function testAdminHomeLinksToTheArchive(): void
    {
        $this->assertStringContainsString('admin/archive', $this->body('admin/dashboard'));
    }

    /*
    |--------------------------------------------------------------------------
    | The lists
    |--------------------------------------------------------------------------
    */

    public function testAnEmptyArchiveSaysSo(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('No past institutions', $body);
        $this->assertStringContainsString('No deleted institutions', $body);
    }

    public function testPastAndDeletedInstitutionsAreListedSeparately(): void
    {
        $this->institution('PAST1', 'past', null, 'Lapsed College');
        $this->institution('LIVE1', 'active', null, 'Running College');
        $this->deletedDaysAgo('DEL1', 10, 'Removed College');

        $body = $this->body();

        $pastSection    = substr($body, strpos($body, 'Past institutions'), strpos($body, 'Deleted institutions') - strpos($body, 'Past institutions'));
        $deletedSection = substr($body, strpos($body, 'Deleted institutions'));

        $this->assertStringContainsString('Lapsed College', $pastSection);
        $this->assertStringNotContainsString('Removed College', $pastSection);
        $this->assertStringNotContainsString('Running College', $body);
        $this->assertStringContainsString('Removed College', $deletedSection);
    }

    public function testEachDeletedInstitutionShowsWhenItIsPurged(): void
    {
        $this->deletedDaysAgo('DEL1', 80, 'Soon Gone College');

        $body = $this->body();

        $this->assertStringContainsString(date('Y-m-d', strtotime('+10 days')), $body, 'the purge date');
        $this->assertStringContainsString('10 days left', $body);
    }

    public function testAnOverdueInstitutionIsMarkedDue(): void
    {
        $this->deletedDaysAgo('DEL1', 120, 'Overdue College');

        $this->assertStringContainsString('due now', $this->body());
    }

    public function testThePurgeWarningCountsWhatWillBeRemovedAndWhatStays(): void
    {
        $id = $this->deletedDaysAgo('DEL1', 5, 'Full College');
        $user = CourseFactory::user('student');
        db_connect()->table('institution_users')->insert(['institution_id' => $id, 'user_id' => $user->id]);

        $body = $this->body();

        $this->assertStringContainsString('cannot be undone', $body);
        $this->assertStringContainsString('1 member account(s) are kept', $body);
        $this->assertStringContainsString('1 of them with no institution left', $body);
    }

    public function testNamesAreEscaped(): void
    {
        $this->deletedDaysAgo('XSS', 5, '<script>alert(1)</script> College');

        $body = $this->body();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Restore from the archive
    |--------------------------------------------------------------------------
    */

    public function testRestoringFromTheArchiveNeedsThePasswordAndComesBackToTheArchive(): void
    {
        $id = $this->deletedDaysAgo('DEL1', 5, 'Back College');

        $this->assertStringEndsWith('admin/reauth', $this->location($this->submit("admin/institutions/{$id}/restore", ['return' => 'archive'])));
        $this->assertTrue($this->isDeleted($id));

        $this->confirmedPassword();
        $response = $this->submit("admin/institutions/{$id}/restore", ['return' => 'archive']);

        $this->assertStringEndsWith('admin/archive', $this->location($response));
        $this->assertFalse($this->isDeleted($id));
        $this->assertStringContainsString('Institution restored', $this->body());
    }

    /*
    |--------------------------------------------------------------------------
    | Purge
    |--------------------------------------------------------------------------
    */

    public function testPurgingNeedsThePasswordFirst(): void
    {
        $id = $this->deletedDaysAgo('DEL1', 5, 'Doomed College');

        $response = $this->submit("admin/institutions/{$id}/purge", ['confirm_name' => 'Doomed College', 'return' => 'archive']);

        $this->assertStringEndsWith('admin/reauth', $this->location($response));
        $this->assertTrue($this->exists($id));
        $this->assertSame(0, $this->auditCount('institution.purged'));
    }

    public function testOnceConfirmedAndNamedAPurgeRemovesItForGood(): void
    {
        $id = $this->deletedDaysAgo('DEL1', 5, 'Doomed College');
        $this->confirmedPassword();

        $response = $this->submit("admin/institutions/{$id}/purge", ['confirm_name' => 'Doomed College', 'return' => 'archive']);

        $this->assertStringEndsWith('admin/archive', $this->location($response));
        $this->assertFalse($this->exists($id));
        $this->assertStringContainsString('Institution purged', $this->body());
        $this->assertStringNotContainsString('Doomed College', $this->body());
        $this->assertSame(1, $this->auditCount('institution.purged'));
    }

    public function testThePurgeAuditEntryRemainsAfterTheInstitutionIsGone(): void
    {
        $id = $this->deletedDaysAgo('DEL1', 5, 'Doomed College');
        $this->confirmedPassword();

        $this->submit("admin/institutions/{$id}/purge", ['confirm_name' => 'Doomed College']);

        $entry = db_connect()->table('audit_logs')->where('action', 'institution.purged')->get()->getRowArray();

        $this->assertSame((string) $this->admin->id, (string) $entry['actor_user_id']);
        $this->assertSame('Doomed College', json_decode($entry['old_values'], true)['name']);
    }

    public function testTheWrongNameDoesNotPurge(): void
    {
        $id = $this->deletedDaysAgo('DEL1', 5, 'Doomed College');
        $this->confirmedPassword();

        $this->submit("admin/institutions/{$id}/purge", ['confirm_name' => 'doomed college', 'return' => 'archive']);

        $this->assertTrue($this->exists($id));
        $this->assertStringContainsString('exact name', $this->body());
        $this->assertSame(0, $this->auditCount('institution.purged'));
    }

    public function testNoNameAtAllDoesNotPurge(): void
    {
        $id = $this->deletedDaysAgo('DEL1', 5, 'Doomed College');
        $this->confirmedPassword();

        $this->submit("admin/institutions/{$id}/purge");

        $this->assertTrue($this->exists($id));
    }

    public function testAnInstitutionThatIsNotDeletedCannotBePurged(): void
    {
        $id = $this->institution('LIVE1', 'past', null, 'Still Here College');
        $this->confirmedPassword();

        $this->submit("admin/institutions/{$id}/purge", ['confirm_name' => 'Still Here College', 'return' => 'archive']);

        $this->assertTrue($this->exists($id));
        $this->assertStringContainsString('Only a deleted institution', $this->body());
    }

    public function testPurgingARealInstitutionRemovesItsCourses(): void
    {
        $offering = CourseFactory::offering();
        $id       = (int) db_connect()->table('departments')->select('institution_id')->orderBy('id', 'desc')->get()->getRowArray()['institution_id'];
        db_connect()->table('institutions')->where('id', $id)->update(['status' => 'past', 'deleted_at' => date('Y-m-d H:i:s')]);
        $name = db_connect()->table('institutions')->where('id', $id)->get()->getRowArray()['name'];
        $this->confirmedPassword();

        $this->submit("admin/institutions/{$id}/purge", ['confirm_name' => $name]);

        $this->assertFalse($this->exists($id));
        $this->assertSame(0, db_connect()->table('course_offerings')->where('id', $offering)->countAllResults());
    }
}
