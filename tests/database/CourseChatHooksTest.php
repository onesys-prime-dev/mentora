<?php

declare(strict_types=1);

use App\Services\Institutions\EnrolmentService;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\TermCloseService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatHooks;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessagingContextResolver;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The writers of enrolments, assignments, offerings and terms keep the course chat in step.
 *
 * @internal
 */
final class CourseChatHooksTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $term;

    private int $course;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('CHKA');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN departments sd ON sd.id = p.department_id JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro', 'units' => 3]);
        $this->course = (int) $db->insertID();
        $this->term   = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
    }

    protected function tearDown(): void
    {
        CourseChatHooks::useSync(null);

        parent::tearDown();
    }

    private function offering(string $section = 'A'): int
    {
        return (new OfferingService())->create($this->institution, ['course_id' => $this->course, 'term_id' => $this->term, 'section_code' => $section, 'capacity' => '40'], $this->actor);
    }

    private function student(string $first): User
    {
        return PeopleFactory::person('student', $first, 'Student', strtolower($first) . '@chat.test', $this->institution);
    }

    private function chatId(int $offering): ?int
    {
        $row = db_connect()->table('conversations')->select('id')->where('offering_id', $offering)->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function active(int $offering, User $user): bool
    {
        $chat = $this->chatId($offering);

        return $chat !== null && (new MessagingContextResolver())->isActiveParticipant((int) $user->id, $chat);
    }

    private function enrol(int $offering, User $student): void
    {
        (new EnrolmentService())->add($this->institution, $offering, (string) $student->email, true, $this->actor);
    }

    private function setStatus(int $offering, User $student, string $status): void
    {
        $id = (int) db_connect()->table('enrollments')->where(['offering_id' => $offering, 'student_user_id' => $student->id])->get()->getRowArray()['id'];
        (new EnrolmentService())->setStatus($this->institution, $id, $status, true, $this->actor);
    }

    public function testCreatingAnOfferingCreatesItsChat(): void
    {
        $offering = $this->offering();

        $this->assertNotNull($this->chatId($offering));
    }

    public function testAssigningAnInstructorAddsThemAndUnassigningRemovesThem(): void
    {
        $offering = $this->offering();
        $teacher  = PeopleFactory::person('instructor', 'Tea', 'Cher', null, $this->institution);
        $service  = new OfferingService();

        $assignment = $service->assignInstructor($this->institution, $offering, (int) $teacher->id, $this->actor);
        $this->assertTrue($this->active($offering, $teacher));

        $service->unassignInstructor($this->institution, $assignment, $this->actor);
        $this->assertFalse($this->active($offering, $teacher));
    }

    public function testEnrolAddsAndDropRemoves(): void
    {
        $offering = $this->offering();
        $ann      = $this->student('Ann');

        $this->enrol($offering, $ann);
        $this->assertTrue($this->active($offering, $ann));

        $this->setStatus($offering, $ann, 'dropped');
        $this->assertFalse($this->active($offering, $ann));

        $this->setStatus($offering, $ann, 'enrolled');
        $this->assertTrue($this->active($offering, $ann));

        $this->setStatus($offering, $ann, 'withdrawn');
        $this->assertFalse($this->active($offering, $ann));
    }

    public function testArchivingAndReactivatingKeepsMembersAndFlipsPostability(): void
    {
        $offering = $this->offering();
        $teacher  = PeopleFactory::person('instructor', 'Tea', 'Cher', null, $this->institution);
        (new OfferingService())->assignInstructor($this->institution, $offering, (int) $teacher->id, $this->actor);
        $chat = db_connect()->table('conversations')->where('offering_id', $offering)->get()->getRowArray();

        (new OfferingService())->setArchived($this->institution, $offering, true, $this->actor);
        $this->assertTrue($this->active($offering, $teacher));
        $this->assertTrue((new MessagingContextResolver())->forPost($teacher, $chat)->offeringClosed);

        (new OfferingService())->setArchived($this->institution, $offering, false, $this->actor);
        $this->assertTrue($this->active($offering, $teacher));
        $this->assertFalse((new MessagingContextResolver())->forPost($teacher, $chat)->offeringClosed);
    }

    public function testClosingAndReopeningATermChangesNoMembership(): void
    {
        $offering = $this->offering();
        $teacher  = PeopleFactory::person('instructor', 'Tea', 'Cher', null, $this->institution);
        $ann      = $this->student('Ann');
        (new OfferingService())->assignInstructor($this->institution, $offering, (int) $teacher->id, $this->actor);
        $this->enrol($offering, $ann);
        $chat = db_connect()->table('conversations')->where('offering_id', $offering)->get()->getRowArray();
        $resolver = new MessagingContextResolver();

        (new TermCloseService())->close($this->institution, $this->term, $this->actor);

        $this->assertTrue($this->active($offering, $ann));
        $this->assertTrue($this->active($offering, $teacher));
        $this->assertSame(0, db_connect()->table('conversation_participants')->where('conversation_id', $chat['id'])->where('left_at IS NOT NULL', null, false)->countAllResults());
        $this->assertTrue($resolver->forPost($ann, $chat)->offeringClosed);

        (new TermCloseService())->reopen($this->institution, $this->term, $this->actor);

        $this->assertTrue($this->active($offering, $ann));
        $this->assertFalse($resolver->forPost($ann, $chat)->offeringClosed);
    }

    public function testAClosedChatAddsNothingToUnread(): void
    {
        $offering = $this->offering();
        $teacher  = PeopleFactory::person('instructor', 'Tea', 'Cher', null, $this->institution);
        $ann      = $this->student('Ann');
        (new OfferingService())->assignInstructor($this->institution, $offering, (int) $teacher->id, $this->actor);
        $this->enrol($offering, $ann);

        $service = new ConversationService();
        $before  = $service->unreadCount($ann);

        (new TermCloseService())->close($this->institution, $this->term, $this->actor);

        $this->assertSame($before, $service->unreadCount($ann));
        $this->assertCount(1, $service->listFor($ann, 'course'));
    }

    public function testAHookFailureDoesNotBreakTheEnrolment(): void
    {
        $offering = $this->offering();
        $ann      = $this->student('Ann');
        $failing  = new class () extends CourseChatSync {
            public function syncOffering(int $offeringId): array
            {
                throw new RuntimeException('boom');
            }
        };
        CourseChatHooks::useSync($failing);

        $this->enrol($offering, $ann);

        $this->assertSame(1, db_connect()->table('enrollments')->where(['offering_id' => $offering, 'student_user_id' => $ann->id])->countAllResults());
    }
}
