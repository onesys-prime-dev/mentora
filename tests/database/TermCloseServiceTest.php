<?php

declare(strict_types=1);

use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use App\Services\Institutions\TermCloseService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * Closing a term and reopening it: exactly what changes, what is left alone,
 * the memory of what the close changed, refusals, audit, and scoping.
 *
 * @internal
 */
final class TermCloseServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $term;

    private int $course;

    private User $actor;

    private TermCloseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('TCLA');
        $this->other       = SisFixture::institution('TCLB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->service     = new TermCloseService();

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro', 'units' => 3]);
        $this->course = (int) $db->insertID();
        $this->term   = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $db->table('audit_logs')->emptyTable();
    }

    private function offering(string $section): int
    {
        return (new OfferingService())->create($this->institution, ['course_id' => $this->course, 'term_id' => $this->term, 'section_code' => $section, 'capacity' => '40'], $this->actor);
    }

    private function student(string $first): User
    {
        return PeopleFactory::person('student', $first, 'Student', null, $this->institution);
    }

    private function statusOf(string $table, int $id): string
    {
        return (string) db_connect()->table($table)->where('id', $id)->get()->getRowArray()['status'];
    }

    private function placeId(int $offering, User $student): int
    {
        return (int) db_connect()->table('enrollments')->where(['offering_id' => $offering, 'student_user_id' => $student->id])->get()->getRowArray()['id'];
    }

    public function testThePreviewCountsWhatWillChange(): void
    {
        $a = $this->offering('A');
        $b = $this->offering('B');
        $c = $this->offering('C');
        db_connect()->table('course_offerings')->where('id', $c)->update(['status' => 'archived']);

        CourseFactory::enroll($a, $this->student('Ann'));
        CourseFactory::enroll($a, $this->student('Ben'));
        CourseFactory::enroll($b, $this->student('Cy'));
        CourseFactory::enroll($b, $this->student('Dee'), 'dropped');

        $plan = $this->service->preview($this->institution, $this->term);

        $this->assertTrue($plan['canClose']);
        $this->assertSame(2, $plan['offeringCount']);
        $this->assertSame(3, $plan['enrolmentCount']);
        $this->assertSame(1, $plan['untouched']);
    }

    public function testClosingCompletesOfferingsAndEnrolmentsAndLeavesTheRest(): void
    {
        $a   = $this->offering('A');
        $arc = $this->offering('B');
        db_connect()->table('course_offerings')->where('id', $arc)->update(['status' => 'archived']);

        $ann  = $this->student('Ann');
        $dee  = $this->student('Dee');
        $zed  = $this->student('Zed');
        CourseFactory::enroll($a, $ann);
        CourseFactory::enroll($a, $dee, 'dropped');
        CourseFactory::enroll($a, $zed, 'withdrawn');

        $done = $this->service->close($this->institution, $this->term, $this->actor);

        $this->assertSame(['offerings' => 1, 'enrolments' => 1], $done);
        $this->assertSame('completed', $this->statusOf('academic_terms', $this->term));
        $this->assertSame('completed', $this->statusOf('course_offerings', $a));
        $this->assertSame('archived', $this->statusOf('course_offerings', $arc));
        $this->assertSame('completed', $this->statusOf('enrollments', $this->placeId($a, $ann)));
        $this->assertSame('dropped', $this->statusOf('enrollments', $this->placeId($a, $dee)));
        $this->assertSame('withdrawn', $this->statusOf('enrollments', $this->placeId($a, $zed)));

        $term = db_connect()->table('academic_terms')->where('id', $this->term)->get()->getRowArray();
        $this->assertNotNull($term['closed_at']);
        $this->assertSame((int) $this->actor->id, (int) $term['closed_by_user_id']);

        $audit = db_connect()->table('audit_logs')->where('action', 'structure.term_closed')->get()->getRowArray();
        $this->assertNotNull($audit);
    }

    public function testReopeningPutsBackExactlyWhatTheCloseChanged(): void
    {
        $a   = $this->offering('A');
        $ann = $this->student('Ann');
        $ben = $this->student('Ben');
        CourseFactory::enroll($a, $ann);
        CourseFactory::enroll($a, $ben, 'completed'); // completed long before the close

        $this->service->close($this->institution, $this->term, $this->actor);
        $back = $this->service->reopen($this->institution, $this->term, $this->actor);

        $this->assertSame(['offerings' => 1, 'enrolments' => 1], $back);
        $this->assertSame('active', $this->statusOf('academic_terms', $this->term));
        $this->assertSame('active', $this->statusOf('course_offerings', $a));
        $this->assertSame('enrolled', $this->statusOf('enrollments', $this->placeId($a, $ann)));
        $this->assertSame('completed', $this->statusOf('enrollments', $this->placeId($a, $ben)), 'it was completed before, so it stays so');

        $term = db_connect()->table('academic_terms')->where('id', $this->term)->get()->getRowArray();
        $this->assertNull($term['closed_at']);
        $this->assertNull($term['close_snapshot']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'structure.term_reopened')->countAllResults());
    }

    public function testReopeningLeavesWhatWasChangedAfterTheClose(): void
    {
        $a   = $this->offering('A');
        $ann = $this->student('Ann');
        CourseFactory::enroll($a, $ann);

        $this->service->close($this->institution, $this->term, $this->actor);
        db_connect()->table('enrollments')->where('id', $this->placeId($a, $ann))->update(['status' => 'withdrawn']);

        $back = $this->service->reopen($this->institution, $this->term, $this->actor);

        $this->assertSame(0, $back['enrolments']);
        $this->assertSame('withdrawn', $this->statusOf('enrollments', $this->placeId($a, $ann)));
    }

    public function testAClosedTermCannotBeClosedAgainAndAnOpenOneCannotBeReopened(): void
    {
        $this->service->close($this->institution, $this->term, $this->actor);

        $e = null;

        try {
            $this->service->close($this->institution, $this->term, $this->actor);
        } catch (StructureException $e) {
        }

        $this->assertInstanceOf(StructureException::class, $e);
        $this->assertStringContainsString('already closed', $e->getMessage());

        $this->service->reopen($this->institution, $this->term, $this->actor);

        $this->expectException(StructureException::class);
        $this->service->reopen($this->institution, $this->term, $this->actor);
    }

    /** One active term at a time: reopening waits until no other term is active (QA AMB-INST-02). */
    public function testATermCannotBeReopenedWhileAnotherIsActive(): void
    {
        $this->service->close($this->institution, $this->term, $this->actor);

        $db = db_connect();
        $db->table('academic_terms')->insert(['institution_id' => $this->institution, 'name' => 'Second Semester', 'academic_year' => '2026-2027', 'start_date' => '2027-01-10', 'end_date' => '2027-05-20', 'status' => 'active']);
        $second = (int) $db->insertID();

        $term = $db->table('academic_terms')->where('id', $this->term)->get()->getRowArray();
        $this->assertStringContainsString('Second Semester', (string) $this->service->reopenBlock($term));

        try {
            $this->service->reopen($this->institution, $this->term, $this->actor);
            $this->fail('Reopening must be refused while another term is active.');
        } catch (StructureException $e) {
            $this->assertStringContainsString('Second Semester', $e->getMessage());
        }

        $this->assertSame('completed', $this->statusOf('academic_terms', $this->term));

        $db->table('academic_terms')->where('id', $second)->update(['status' => 'upcoming']);
        $this->service->reopen($this->institution, $this->term, $this->actor);
        $this->assertSame('active', $this->statusOf('academic_terms', $this->term));
    }

    public function testAnotherInstitutionsActiveTermDoesNotBlockReopening(): void
    {
        $this->service->close($this->institution, $this->term, $this->actor);
        db_connect()->table('academic_terms')->where('institution_id', $this->other)->update(['status' => 'active']);

        $this->service->reopen($this->institution, $this->term, $this->actor);

        $this->assertSame('active', $this->statusOf('academic_terms', $this->term));
    }

    public function testATermCompletedWithoutBeingClosedHereCannotBeReopened(): void
    {
        db_connect()->table('academic_terms')->where('id', $this->term)->update(['status' => 'completed']);

        $term = db_connect()->table('academic_terms')->where('id', $this->term)->get()->getRowArray();
        $this->assertStringContainsString('no record', (string) $this->service->reopenBlock($term));

        $this->expectException(StructureException::class);
        $this->service->reopen($this->institution, $this->term, $this->actor);
    }

    public function testAnUpcomingTermCanBeClosedToo(): void
    {
        db_connect()->table('academic_terms')->where('id', $this->term)->update(['status' => 'upcoming']);

        $this->assertSame(['offerings' => 0, 'enrolments' => 0], $this->service->close($this->institution, $this->term, $this->actor));
        $this->assertSame('completed', $this->statusOf('academic_terms', $this->term));
    }

    public function testAnotherInstitutionsTermIsNotFound(): void
    {
        $theirs = (int) db_connect()->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        foreach ([
            fn () => $this->service->preview($this->institution, $theirs),
            fn () => $this->service->close($this->institution, $theirs, $this->actor),
            fn () => $this->service->reopen($this->institution, $theirs, $this->actor),
        ] as $do) {
            try {
                $do();
                $this->fail('A foreign id must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('active', $this->statusOf('academic_terms', $theirs));
    }
}
