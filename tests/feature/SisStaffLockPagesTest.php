<?php

declare(strict_types=1);

use App\Services\Institutions\ChairAssignmentService;
use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\OfferingService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * What an institution's administrator sees and can do with the chairpersons and instructors a SIS supplies:
 * they are marked as the SIS's and have no Remove button, a request to remove one anyway is refused with the
 * reason, the ones made by hand are untouched, and everything is editable again once the connection is off.
 * The upload page names the staff file.
 *
 * @internal
 */
final class SisStaffLockPagesTest extends RouteTestCase
{
    private int $institution;

    private int $sub;

    private int $offering;

    private User $admin;

    private User $sisChair;

    private User $handChair;

    private User $sisTeacher;

    private User $handTeacher;

    private int $sisChairRow;

    private int $handChairRow;

    private int $sisTeachRow;

    private int $handTeachRow;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('SLOCK');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@slock.example', $this->institution);
        $root              = PeopleFactory::person('admin', 'Root', 'Admin', 'root@slock.example');

        SisFixture::connect($this->institution, $root);

        $db        = db_connect();
        $dept      = (int) $db->table('departments')->where('institution_id', $this->institution)->get()->getRow('id');
        $this->sub = (int) $db->table('sub_departments')->where('department_id', $dept)->get()->getRow('id');

        $this->sisChair  = PeopleFactory::person('chairperson', 'Sis', 'Chair', null, $this->institution);
        $this->handChair = PeopleFactory::person('chairperson', 'Hand', 'Chair', null, $this->institution);

        $chairs             = new ChairAssignmentService();
        $this->sisChairRow  = $chairs->assign($this->institution, $this->sub, (int) $this->sisChair->id, $this->admin);
        $this->handChairRow = $chairs->assign($this->institution, $this->sub, (int) $this->handChair->id, $this->admin);

        $this->offering = SisFixture::course($this->institution)['offering'];

        $this->sisTeacher  = PeopleFactory::person('instructor', 'Sis', 'Teacher', null, $this->institution);
        $this->handTeacher = PeopleFactory::person('instructor', 'Hand', 'Teacher', null, $this->institution);

        $offerings          = new OfferingService();
        $this->sisTeachRow  = $offerings->assignInstructor($this->institution, $this->offering, (int) $this->sisTeacher->id, $this->admin);
        $this->handTeachRow = $offerings->assignInstructor($this->institution, $this->offering, (int) $this->handTeacher->id, $this->admin);

        // These two are the ones the SIS supplied.
        $now = date('Y-m-d H:i:s');
        $db->table('sis_staff_assignments')->insert(['institution_id' => $this->institution, 'role' => 'chairperson', 'person_user_id' => (int) $this->sisChair->id, 'target_id' => $this->sub, 'local_id' => $this->sisChairRow, 'created_at' => $now, 'updated_at' => $now]);
        $db->table('sis_staff_assignments')->insert(['institution_id' => $this->institution, 'role' => 'instructor', 'person_user_id' => (int) $this->sisTeacher->id, 'target_id' => $this->offering, 'local_id' => $this->sisTeachRow, 'created_at' => $now, 'updated_at' => $now]);

        $db->table('audit_logs')->emptyTable();

        $this->loggedInAs($this->admin);
        $this->withSession();
    }

    private function page(string $uri): string
    {
        $response = $this->step('GET', $uri)->response();

        $this->assertSame(200, $response->getStatusCode(), $uri);

        return html_entity_decode($response->getBody());
    }

    private function remove(string $uri): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])->step('POST', $uri);
    }

    private function chairActive(int $row): bool
    {
        return db_connect()->table('chairperson_assignments')->where(['id' => $row, 'status' => 'active'])->countAllResults() === 1;
    }

    private function teaches(int $row): bool
    {
        return db_connect()->table('instructor_assignments')->where('id', $row)->countAllResults() === 1;
    }

    private function switchOff(): void
    {
        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->update(['enabled' => 0]);
    }

    public function testTheStructurePageMarksTheSisChairpersonAndOffersToRemoveTheOneMadeByHand(): void
    {
        $body = $this->page('institution/structure');

        $this->assertStringContainsString('data-chair="' . $this->sisChairRow . '"', $body);
        $this->assertMatchesRegularExpression('#data-chair="' . $this->sisChairRow . '".*?data-sis-managed#s', $body);
        $this->assertStringContainsString('Managed by your student information system. Change it there; the next sync brings it here.', $body);
        $this->assertStringNotContainsString('chairpersons/' . $this->sisChairRow . '/remove', $body, 'no way to remove what the SIS supplies');
        $this->assertStringContainsString('chairpersons/' . $this->handChairRow . '/remove', $body, 'the one made by hand can still be removed');
    }

    public function testARequestToRemoveTheSisChairpersonAnywayIsRefusedWithTheReason(): void
    {
        $response = $this->remove('institution/structure/chairpersons/' . $this->sisChairRow . '/remove')->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('managed by your student information system', json_decode($response->getBody(), true)['message']);
        $this->assertTrue($this->chairActive($this->sisChairRow));
    }

    public function testTheChairpersonMadeByHandIsRemovedAsBefore(): void
    {
        $this->assertSame(200, $this->remove('institution/structure/chairpersons/' . $this->handChairRow . '/remove')->response()->getStatusCode());
        $this->assertFalse($this->chairActive($this->handChairRow));
        $this->assertTrue($this->chairActive($this->sisChairRow));
    }

    public function testTheOfferingsPageMarksTheSisInstructorAndOffersToRemoveTheOneMadeByHand(): void
    {
        $body = $this->page('institution/offerings');

        $this->assertMatchesRegularExpression('#data-instructor="' . $this->sisTeachRow . '".*?data-sis-managed#s', $body);
        $this->assertStringNotContainsString('instructors/' . $this->sisTeachRow . '/remove', $body);
        $this->assertStringContainsString('instructors/' . $this->handTeachRow . '/remove', $body);
    }

    public function testARequestToRemoveTheSisInstructorAnywayIsRefusedWithTheReason(): void
    {
        $response = $this->remove('institution/offerings/instructors/' . $this->sisTeachRow . '/remove')->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('managed by your student information system', json_decode($response->getBody(), true)['message']);
        $this->assertTrue($this->teaches($this->sisTeachRow));
    }

    public function testTheInstructorMadeByHandIsRemovedAsBefore(): void
    {
        $this->assertSame(200, $this->remove('institution/offerings/instructors/' . $this->handTeachRow . '/remove')->response()->getStatusCode());
        $this->assertFalse($this->teaches($this->handTeachRow));
        $this->assertTrue($this->teaches($this->sisTeachRow));
    }

    public function testNothingIsMarkedAndEverythingCanBeRemovedOnceTheConnectionIsOff(): void
    {
        $this->switchOff();

        $structure = $this->page('institution/structure');
        $offerings = $this->page('institution/offerings');

        $this->assertStringNotContainsString('data-sis-managed', $structure);
        $this->assertStringNotContainsString('data-sis-managed', $offerings);
        $this->assertStringContainsString('chairpersons/' . $this->sisChairRow . '/remove', $structure);
        $this->assertStringContainsString('instructors/' . $this->sisTeachRow . '/remove', $offerings);

        $this->assertSame(200, $this->remove('institution/structure/chairpersons/' . $this->sisChairRow . '/remove')->response()->getStatusCode());
        $this->assertFalse($this->chairActive($this->sisChairRow));
    }

    public function testAnotherInstitutionsAdministratorSeesNoSisMarkForAssignmentsThatAreNotTheirs(): void
    {
        $other = SisFixture::institution('SLOTH');
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs(PeopleFactory::person('institutional_admin', 'Olga', 'Other', 'olga@sloth.example', $other));
        $this->withSession();

        $this->assertStringNotContainsString('data-sis-managed', $this->page('institution/structure'));
    }

    public function testTheDeansLockIsTheServicesAndTheDeanPageNeedsNoNewControl(): void
    {
        $dept = (int) db_connect()->table('departments')->where('institution_id', $this->institution)->get()->getRow('id');
        $dean = PeopleFactory::person('dean', 'Sis', 'Dean', null, $this->institution);
        $row  = (new DeanAssignmentService())->assign($this->institution, $dept, (int) $dean->id, $this->admin);
        $now  = date('Y-m-d H:i:s');
        db_connect()->table('sis_staff_assignments')->insert(['institution_id' => $this->institution, 'role' => 'dean', 'person_user_id' => (int) $dean->id, 'target_id' => $dept, 'local_id' => $row, 'created_at' => $now, 'updated_at' => $now]);

        $this->expectException(\App\Services\Institutions\StructureException::class);
        $this->expectExceptionMessage('managed by your student information system');

        (new DeanAssignmentService())->end($this->institution, $row, $this->admin);
    }

    public function testTheUploadPageNamesTheStaffFileWithItsColumns(): void
    {
        $this->switchOff(); // an upload is only open while the SIS is not the source

        $body = $this->page('institution/people/upload');

        $this->assertStringContainsString('Staff assignments (staff_assignments.csv)', $body);
        $this->assertStringContainsString('department_code for a dean', $body);
        $this->assertStringContainsString('upload/template/staff_assignments', $body);

        $template = $this->step('GET', 'institution/people/upload/template/staff_assignments')->response();

        $this->assertSame(200, $template->getStatusCode());
        $this->assertStringContainsString('role,person_external_id,department_code,sub_department_code,course_external_id,section', $template->getBody());
    }
}
