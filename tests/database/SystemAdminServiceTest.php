<?php

declare(strict_types=1);

use App\Database\Seeds\SystemAdminSeeder;
use App\Services\Admin\SystemAdminService;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\DatabaseTestCase;

/**
 * Creating system administrators: the only places an account in the admin
 * group can come from are SystemAdminService (spark `admin:create`) and the
 * seeder that wraps it. Covers the rules, the command and the seeder.
 *
 * @internal
 */
final class SystemAdminServiceTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    protected bool $isolateTests = true;

    private function service(): SystemAdminService
    {
        return new SystemAdminService();
    }

    public function testCreatesAnActiveUserInTheAdminGroup(): void
    {
        $user = $this->service()->create('Root@Mentora.test', 'root', 'a-long-password-1');

        $this->assertTrue($user->inGroup('admin'));
        $this->assertTrue($user->active);
        $this->assertSame('root@mentora.test', $user->email, 'the email is stored lower-cased');
    }

    public function testTheAdministratorBelongsToNoInstitution(): void
    {
        $user = $this->service()->create('root@mentora.test', 'root', 'a-long-password-1');

        $this->assertSame(0, db_connect()->table('institution_users')->where('user_id', $user->id)->countAllResults());
    }

    public function testTheNewAccountCanBeLookedUpByItsCredentials(): void
    {
        $this->service()->create('root@mentora.test', 'root', 'a-long-password-1');

        $this->assertNotNull(model(UserModel::class)->findByCredentials(['email' => 'root@mentora.test']));
    }

    public function testRejectsAnInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid email');

        $this->service()->create('not-an-email', 'root', 'a-long-password-1');
    }

    public function testRejectsAShortPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 12');

        $this->service()->create('root@mentora.test', 'root', 'short');
    }

    public function testRejectsABlankUsername(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->create('root@mentora.test', '   ', 'a-long-password-1');
    }

    public function testRejectsADuplicateEmail(): void
    {
        $this->service()->create('root@mentora.test', 'root', 'a-long-password-1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');

        $this->service()->create('ROOT@mentora.test', 'other', 'a-long-password-1');
    }

    public function testRejectsADuplicateUsername(): void
    {
        $this->service()->create('root@mentora.test', 'root', 'a-long-password-1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already taken');

        $this->service()->create('other@mentora.test', 'root', 'a-long-password-1');
    }

    public function testTheSparkCommandCreatesAnAdministrator(): void
    {
        $output = $this->runCommand('admin:create cli@mentora.test cli.admin a-long-password-1');

        $this->assertStringContainsString('Created system administrator', $output);
        $this->assertTrue(model(UserModel::class)->findByCredentials(['email' => 'cli@mentora.test'])->inGroup('admin'));
    }

    public function testTheSparkCommandSaysWhatToDoNext(): void
    {
        $output = $this->runCommand('admin:create next@mentora.test next.admin a-long-password-1');

        $this->assertStringContainsString('mail:test next@mentora.test', $output);
        $this->assertStringContainsString('6-digit code', $output);
        $this->assertStringNotContainsString('a-long-password-1', $output, 'the password is never echoed back');
    }

    public function testTheSparkCommandRefusesBadInputAndCreatesNothing(): void
    {
        $output = $this->runCommand('admin:create nope cli.admin a-long-password-1');

        $this->assertStringContainsString('valid email', $output);
        $this->assertNull(model(UserModel::class)->where('username', 'cli.admin')->first());
    }

    public function testTheSeederCreatesOneAdministratorAndIsSafeToRunTwice(): void
    {
        ob_start();
        $this->seed(SystemAdminSeeder::class);
        $this->seed(SystemAdminSeeder::class);
        ob_end_clean();

        $admin = model(UserModel::class)->findByCredentials(['email' => 'admin@mentora.test']);

        $this->assertTrue($admin->inGroup('admin'));
        $this->assertSame(1, db_connect()->table('auth_groups_users')->where('group', 'admin')->countAllResults());
    }

    private function runCommand(string $command): string
    {
        command($command);

        return $this->getStreamFilterBuffer();
    }
}
