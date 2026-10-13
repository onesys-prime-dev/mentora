<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * An administration form posted from a script (the password dialog) is told
 * where to go as JSON rather than being sent through the redirect, so the
 * message set for the next page is still there when it arrives. A form posted
 * without a script gets the ordinary redirect, and a guarded action asks for
 * the password in the way the dialog understands.
 *
 * @internal
 */
final class AdminAjaxRedirectTest extends RouteTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $user = CourseFactory::user('admin');
        $user->createEmailIdentity(['email' => 'root@mentora.test', 'password' => 'a-long-password-1']);
        $this->admin = model(UserModel::class)->findById($user->id);

        $this->loggedInAs($this->admin);
        $this->withSession();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    public function testAScriptIsToldWhereToGoAndNothingIsFollowed(): void
    {
        $this->confirmedPassword();
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $response = $this->step('POST', 'admin/audit-log/settings', ['show_ip' => '1'])->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('admin/audit-log', (string) (json_decode($response->getBody(), true)['redirect'] ?? ''));
        $this->assertFalse($response->hasHeader('Location'));
    }

    public function testAPlainFormGetsTheOrdinaryRedirect(): void
    {
        $this->confirmedPassword();

        $response = $this->step('POST', 'admin/audit-log/settings', ['show_ip' => '1'])->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('admin/audit-log', $response->getHeaderLine('Location'));
    }

    public function testAGuardedActionAsksForThePasswordInTheWayTheDialogUnderstands(): void
    {
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $response = $this->step('POST', 'admin/audit-log/settings', ['show_ip' => '1'])->response();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('reauth_required', json_decode($response->getBody(), true)['error'] ?? null);
    }

    public function testEveryPageOfTheAreaCarriesThePasswordDialog(): void
    {
        $body = $this->step('GET', 'admin/dashboard')->response()->getBody();

        $this->assertStringContainsString('id="reauth-dialog"', $body);
        $this->assertStringContainsString('data-reauth-form', $body);
        $this->assertStringContainsString('action="' . site_url('admin/reauth') . '"', $body);
    }
}
