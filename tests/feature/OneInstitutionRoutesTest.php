<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Nothing offers a second institution to an account any more (decision D1).
 *
 * @internal
 */
final class OneInstitutionRoutesTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = PeopleFactory::institution('ONEI', 'active', 'One University');
        $this->other       = PeopleFactory::institution('TWOI', 'active', 'Two College');
        $this->person      = PeopleFactory::person('student', 'Sam', 'Single', institutionId: $this->institution);
    }

    private function asSystemAdministrator(): void
    {
        $admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($admin);
        $this->withSession();
        $_SESSION['reauth'] = ['user' => $admin->id, 'at' => time()];
        $this->withSession();
    }

    public function testTheSystemAdministratorCannotAddOrRemoveAMembership(): void
    {
        $this->asSystemAdministrator();

        foreach (["admin/users/{$this->person->id}/memberships", "admin/users/{$this->person->id}/memberships/{$this->institution}/remove"] as $uri) {
            try {
                $this->step('POST', $uri, ['institution_id' => (string) $this->other]);
                $this->fail("{$uri} should not exist");
            } catch (PageNotFoundException) {
                // gone
            }
        }

        $this->assertSame(1, db_connect()->table('institution_users')->where('user_id', $this->person->id)->countAllResults());
    }

    public function testTheUserPageShowsTheInstitutionWithoutChangingIt(): void
    {
        $this->asSystemAdministrator();

        $html = (string) $this->step('GET', "admin/users/{$this->person->id}")->getBody();

        $this->assertStringContainsString(site_url("admin/institutions/{$this->institution}"), $html);
        $this->assertStringNotContainsString('/memberships', $html);
    }

    public function testAnInstitutionAdministratorHasNoRemoveFromMyInstitution(): void
    {
        $this->loggedInAs(PeopleFactory::person('institutional_admin', 'Ada', 'Admin', institutionId: $this->institution));

        $html = (string) $this->get('institution/people')->getBody();

        $this->assertStringContainsString('Sam', $html);
        $this->assertStringNotContainsString('Remove from my institution', $html);
        $this->assertStringNotContainsString('data-elsewhere', $html);
    }

    public function testTheRemoveFromMyInstitutionRouteIsGone(): void
    {
        $this->loggedInAs(PeopleFactory::person('institutional_admin', 'Ada', 'Admin', institutionId: $this->institution));

        $this->expectException(PageNotFoundException::class);

        $this->post("institution/people/{$this->person->id}/remove");
    }
}
