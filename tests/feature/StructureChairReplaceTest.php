<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Replacing a department's chairperson on the Structure page (audit decision 16.0b).
 *
 * @internal
 */
final class StructureChairReplaceTest extends RouteTestCase
{
    private int $department;

    private User $old;

    private User $new;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $home        = DeanFactory::college('SR');
        $this->department   = DeanFactory::department($home['college'], 'SR');
        $this->old   = DeanFactory::chair($this->department, 'Olga');
        $this->new   = PeopleFactory::person('chairperson', 'Nina', 'New', null, $home['institution']);
        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $home['institution']);

        $this->loggedInAs($this->admin);
        $this->withSession();
    }

    private function activeChair(): int
    {
        return (int) db_connect()->table('chairperson_assignments')->where(['department_id' => $this->department, 'status' => 'active'])->get()->getRowArray()['chairperson_user_id'];
    }

    public function testAFilledDepartmentOffersReplacingItsChair(): void
    {
        $html = (string) $this->step('GET', 'institution/structure')->getBody();

        $this->assertStringContainsString('Replace chairperson', $html);
        $this->assertStringContainsString('data-dialog-values=\'{"replace":"1"}\'', $html);
    }

    public function testAssigningWithoutConfirmingIsRefusedAndConfirmingReplaces(): void
    {
        $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

        $refused = $this->step('POST', "institution/structure/departments/{$this->department}/chairpersons", ['user_id' => (string) $this->new->id]);
        $this->assertSame(422, $refused->response()->getStatusCode());
        $this->assertSame((int) $this->old->id, $this->activeChair());

        $this->step('POST', "institution/structure/departments/{$this->department}/chairpersons", ['user_id' => (string) $this->new->id, 'replace' => '1']);
        $this->assertSame((int) $this->new->id, $this->activeChair());
    }
}
