<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The institution administrator's Sign-off page (audit decisions 2.1, 2.4, D4).
 *
 * @internal
 */
final class InstitutionSignoffPageTest extends RouteTestCase
{
    private int $institution;

    private int $offering;

    private int $deanOffering;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $home              = DeanFactory::department('SG');
        $this->institution = $home['institution'];
        $term              = DeanFactory::term($this->institution);

        $other = DeanFactory::department('SH', $this->institution);
        DeanFactory::dean($this->institution, $other['department'], 'Otto');

        $this->offering     = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($home['department'], 'SG'), 'SG'), $term, 'IT501');
        $this->deanOffering = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($other['department'], 'SH'), 'SH'), $term, 'LW501');

        foreach ([$this->offering, $this->deanOffering] as $id) {
            db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $id, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
        }

        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function asAdmin(bool $confirmed = false): void
    {
        $this->loggedInAs($this->admin);
        $this->withSession();

        if ($confirmed) {
            $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
            $this->withSession();
        }
    }

    public function testTheAdministratorSeesOnlyTheDepartmentsWithNoDean(): void
    {
        $this->asAdmin();

        $html = (string) $this->step('GET', 'institution/signoff')->getBody();

        $this->assertStringContainsString('IT501', $html);
        $this->assertStringNotContainsString('LW501', $html);
        $this->assertStringContainsString(site_url('institution/signoff/' . $this->offering), $html);
        // No link to the dean's pages (the view's file path, pages/dean/..., can appear in debug comments).
        $this->assertStringNotContainsString(site_url('dean/'), $html);
        $this->assertStringNotContainsString("your department's sections", $html, 'worded for an administrator');
        $this->assertStringNotContainsString('only you can', $html, 'another administrator may reopen');
        $this->assertStringContainsString('only an administrator can', $html);
    }

    public function testSigningOffNeedsThePassword(): void
    {
        $this->asAdmin();
        $this->step('POST', 'institution/signoff/' . $this->offering);
        $this->assertSame(0, db_connect()->table('grade_signoffs')->countAllResults());

        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
        $this->step('POST', 'institution/signoff/' . $this->offering);
        $this->assertSame(1, db_connect()->table('grade_signoffs')->where('offering_id', $this->offering)->countAllResults());
    }

    /** The instructor reads who signed off; it is not "the dean" when an administrator did (QA AMB-ASG-04). */
    public function testTheInstructorReadsThatTheAdministratorSignedOff(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $teacher);
        $this->assertTrue((new \App\Services\Courses\Grades\GradeSignoffService())->signOff($this->admin, $this->offering)['success']);

        $this->loggedInAs($teacher);
        $html = html_entity_decode((string) (json_decode((string) $this->withHeaders(['Accept' => 'application/json'])->call('get', "instructor/api/courses/{$this->offering}/grades")->getJSON(), true)['html'] ?? ''));

        $this->assertStringContainsString('data-signed-off-tag', $html);
        $this->assertStringContainsString('Signed off by the administrator, Ada Admin', $html);
        $this->assertStringContainsString('only an administrator can reopen the grades', $html);
        $this->assertStringNotContainsString('the dean', $html);

        $refusal = (new \App\Services\Courses\Grades\GradeFinalizationService())->reopen($teacher, $this->offering, 'A late regrade');
        $this->assertFalse($refusal['success']);
        $this->assertStringNotContainsString('dean', $refusal['message']);
    }

    public function testTheAdministratorsOwnSectionSaysAnotherSignsItOff(): void
    {
        $this->admin->addGroup('instructor');
        CourseFactory::assignInstructor($this->offering, $this->admin);
        $this->asAdmin();

        $html = (string) $this->step('GET', 'institution/signoff')->getBody();

        $this->assertStringContainsString('You teach this section, so another administrator signs it off.', $html);
        $this->assertStringNotContainsString('data-dialog-open="signoff-' . $this->offering . '"', $html);
    }
}
