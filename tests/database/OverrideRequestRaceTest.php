<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\OverrideRequestChain as Chain;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * A chairperson's action and the requester's can't both win (audit decision 16.1): each moves the request on
 * only from the status it read, and the one that comes second is told and sends nothing.
 *
 * @internal
 */
final class OverrideRequestRaceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offering;

    private User $teacher;

    private User $chair;

    private int $requestId;

    private int $department;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        session()->destroy();

        $home             = DeanFactory::department('RC');
        $this->department = $home['department'];
        $sub            = DeanFactory::subDepartment($home['department'], 'RC');
        $this->offering = DeanFactory::offering(DeanFactory::program($sub, 'RC'), DeanFactory::term($home['institution']), 'IT701');
        DeanFactory::dean($home['institution'], $home['department']);
        $this->chair   = DeanFactory::chair($sub, 'Maria');
        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $home['institution']);
        $student       = PeopleFactory::person('student', 'Ann', 'Lee', null, $home['institution']);

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $student);
        db_connect()->table('gradebook_records')->insert(['course_offering_id' => $this->offering, 'student_id' => $student->id, 'grading_scheme_id' => GradeFactory::scheme($this->offering), 'calculated_percentage' => 80.0, 'is_override' => 0]);

        $this->requestId = (int) (new GradeOverrideRequestService())->request($this->teacher, $this->offering, (int) $student->id, 85.0, 'Excused.')['request']['id'];
    }

    /** A service whose next read of the request is followed at once by someone else's change to it. */
    private function racing(array $change): GradeOverrideRequestService
    {
        return new class ($change) extends GradeOverrideRequestService {
            public function __construct(private ?array $change)
            {
                parent::__construct();
            }

            protected function requestRow(int $id): ?array
            {
                $row = parent::requestRow($id);

                if ($this->change !== null) {
                    db_connect()->table('grade_override_requests')->where('id', $id)->update($this->change);
                    $this->change = null;
                }

                return $row;
            }
        };
    }

    private function statusOf(): string
    {
        return (string) db_connect()->table('grade_override_requests')->where('id', $this->requestId)->get()->getRow('status');
    }

    private function toldTeacher(string $title): int
    {
        return db_connect()->table('notifications')->where(['user_id' => $this->teacher->id, 'title' => $title])->countAllResults();
    }

    public function testAWithdrawalAfterTheChairsReadMakesTheEndorsementLose(): void
    {
        $result = $this->racing(['status' => Chain::WITHDRAWN, 'open_marker' => null])->endorse($this->chair, $this->requestId, 'ok');

        $this->assertFalse($result['success']);
        $this->assertSame('That request was just acted on.', $result['message']);
        $this->assertSame(Chain::WITHDRAWN, $this->statusOf());
        $this->assertSame(0, $this->toldTeacher('Override request endorsed'));
    }

    public function testAnEndorsementAfterTheRequestersReadMakesTheWithdrawalLose(): void
    {
        $result = $this->racing(['status' => Chain::AWAITING_DEAN, 'endorsed_by' => $this->chair->id])->withdraw($this->teacher, $this->offering, $this->requestId);

        $this->assertFalse($result['success']);
        $this->assertSame('That request was just acted on.', $result['message']);
        $this->assertSame(Chain::AWAITING_DEAN, $this->statusOf());
    }

    public function testSendBackLosesTheSameWay(): void
    {
        $result = $this->racing(['status' => Chain::WITHDRAWN, 'open_marker' => null])->sendBack($this->chair, $this->requestId, 'Attach the note.');

        $this->assertFalse($result['success']);
        $this->assertSame('That request was just acted on.', $result['message']);
        $this->assertSame(Chain::WITHDRAWN, $this->statusOf());
        $this->assertSame(0, $this->toldTeacher('Override request sent back'));
    }

    public function testADecisionAfterTheReRoutesReadStands(): void
    {
        (new GradeOverrideRequestService())->endorse($this->chair, $this->requestId, 'ok');
        $this->assertSame(Chain::AWAITING_DEAN, $this->statusOf());
        db_connect()->table('dean_assignments')->update(['status' => 'ended', 'active_marker' => null]);

        // The re-route reads the request as waiting for the dean; the dean decides it before the re-route writes.
        $service = new class () extends GradeOverrideRequestService {
            protected function pastTheChair(int $departmentId): array
            {
                $rows = parent::pastTheChair($departmentId);
                db_connect()->table('grade_override_requests')->whereIn('id', array_column($rows, 'id'))->update(['status' => Chain::APPROVED, 'open_marker' => null]);

                return $rows;
            }
        };

        $this->assertSame(0, $service->retargetDepartment($this->department));
        $this->assertSame(Chain::APPROVED, $this->statusOf(), 'a decided request is never reopened');
    }

    public function testWithoutARaceEachStillWorks(): void
    {
        $this->assertTrue((new GradeOverrideRequestService())->endorse($this->chair, $this->requestId, 'ok')['success']);
        $this->assertSame(Chain::AWAITING_DEAN, $this->statusOf());
        $this->assertSame(1, $this->toldTeacher('Override request endorsed'));
    }
}
