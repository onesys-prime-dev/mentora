<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The system administrator's actions that change who can use Mentora ask for the password again
 * (QA AMB-AUTH-05): suspending or deleting an institution, deactivating, reactivating or signing
 * someone out (one at a time or in bulk), and testing or previewing an institution's integration.
 * Without a fresh confirmation each one goes to the confirm page and changes nothing. (Changing a
 * flag for one institution does not: AdminFlagsPageTest pins that.)
 *
 * @internal
 */
final class SensitiveAdminActionsNeedThePasswordTest extends RouteTestCase
{
    private User $admin;
    private int $institution;
    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);
        $this->loggedInAs($this->admin);
        $this->withSession();
        $this->institution = PeopleFactory::institution('ACME', 'active', 'Acme University');
        $this->person      = PeopleFactory::person('student', 'Sam', 'Student', null, $this->institution);
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function actions(): array
    {
        return [
            'suspend an institution'        => ['admin/institutions/{institution}/suspend', []],
            'delete an institution'         => ['admin/institutions/{institution}/delete', ['confirm_name' => 'Acme University']],
            'deactivate a person'           => ['admin/users/{person}/deactivate', []],
            'reactivate a person'           => ['admin/users/{person}/reactivate', []],
            'sign a person out everywhere'  => ['admin/users/{person}/sign-out', []],
            'a bulk action'                 => ['admin/users/bulk', ['action' => 'deactivate', 'ids' => ['{person}']]],
            'test an integration'           => ['admin/institutions/{institution}/integrations/test', []],
            'preview an integration'        => ['admin/institutions/{institution}/integrations/preview', []],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('actions')]
    public function testItAsksForThePasswordFirst(string $uri, array $data): void
    {
        $fill = fn ($value) => is_string($value) ? strtr($value, ['{institution}' => (string) $this->institution, '{person}' => (string) $this->person->id]) : $value;
        $data = array_map(static fn ($v) => is_array($v) ? array_map($fill, $v) : $fill($v), $data);

        $response = $this->step('POST', $fill($uri), $data);

        $this->assertStringContainsString('admin/reauth', $response->response()->getHeaderLine('Location'));
    }

    public function testNothingIsChangedBeforeThePasswordIsConfirmed(): void
    {
        $this->step('POST', "admin/institutions/{$this->institution}/suspend");
        $this->step('POST', "admin/users/{$this->person->id}/deactivate");

        $this->assertSame('active', db_connect()->table('institutions')->where('id', $this->institution)->get()->getRowArray()['status']);
        $this->assertSame(1, (int) db_connect()->table('users')->where('id', $this->person->id)->get()->getRowArray()['active']);
    }
}
