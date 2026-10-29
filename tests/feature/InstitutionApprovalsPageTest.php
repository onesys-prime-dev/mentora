<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Admin\PermissionMatrix;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The institution administrator's Approvals page (audit decision 1.1).
 *
 * @internal
 */
final class InstitutionApprovalsPageTest extends RouteTestCase
{
    private int $institution;

    private User $admin;

    private int $request;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $home              = DeanFactory::department('AP');
        $this->institution = $home['institution'];
        $other             = DeanFactory::department('AQ', $this->institution);
        DeanFactory::dean($this->institution, $other['department'], 'Otto');

        $program  = DeanFactory::program(DeanFactory::subDepartment($home['department'], 'AP'), 'AP');
        $offering = DeanFactory::offering($program, DeanFactory::term($this->institution), 'IT301', 'A', 'active', 'Networks');
        $teacher  = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $student  = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        CourseFactory::assignInstructor($offering, $teacher);
        CourseFactory::enroll($offering, $student);
        db_connect()->table('gradebook_records')->insert(['course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => GradeFactory::scheme($offering), 'calculated_percentage' => 88.0, 'is_override' => 0]);

        $this->request = (int) (new GradeOverrideRequestService())->request($teacher, $offering, (int) $student->id, 92.0, 'Late quiz excused.')['request']['id'];
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function asAdmin(): void
    {
        $this->loggedInAs($this->admin);
        $this->withSession();
    }

    public function testTheAdministratorSeesWhatWaitsForThem(): void
    {
        $this->asAdmin();

        $html = (string) $this->step('GET', 'institution/approvals')->getBody();

        $this->assertStringContainsString('Ann Lee', $html);
        $this->assertStringContainsString(site_url("institution/approvals/{$this->request}/approve"), $html);
        // No link to the dean's page (the view's file path, pages/dean/..., can appear in debug comments).
        $this->assertStringNotContainsString(site_url('dean/approvals'), $html);
        $this->assertStringNotContainsString('in your department', $html, 'worded for an administrator');
    }

    public function testTheAdministratorApprovesThroughThePage(): void
    {
        $this->asAdmin();

        $this->step('POST', "institution/approvals/{$this->request}/approve", ['comment' => '']);

        $this->assertSame('approved', db_connect()->table('grade_override_requests')->where('id', $this->request)->get()->getRowArray()['status']);
    }

    public function testRejectingNeedsAReason(): void
    {
        $this->asAdmin();

        $this->step('POST', "institution/approvals/{$this->request}/reject", ['reason' => '']);
        $this->assertSame('awaiting_institution', db_connect()->table('grade_override_requests')->where('id', $this->request)->get()->getRowArray()['status']);

        $this->step('POST', "institution/approvals/{$this->request}/reject", ['reason' => 'Not supported.']);
        $this->assertSame('rejected', db_connect()->table('grade_override_requests')->where('id', $this->request)->get()->getRowArray()['status']);
    }

    public function testAnotherInstitutionsAdministratorSeesNothingOfIt(): void
    {
        $stranger = PeopleFactory::person('institutional_admin', 'Sal', 'Stranger', null, PeopleFactory::institution('APX'));
        $this->loggedInAs($stranger);
        $this->withSession();

        $this->assertStringNotContainsString('Ann Lee', (string) $this->step('GET', 'institution/approvals')->getBody());
        $this->step('POST', "institution/approvals/{$this->request}/approve", ['comment' => '']);
        $this->assertSame('awaiting_institution', db_connect()->table('grade_override_requests')->where('id', $this->request)->get()->getRowArray()['status']);
    }
}
