<?php

declare(strict_types=1);

use App\Services\Messaging\CourseChatSync;
use App\Services\Monitoring\ScheduledJobs;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * messages:sync-course-chats backfills and repairs, and a dry run writes nothing.
 *
 * @internal
 */
final class SyncCourseChatsCommandTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    protected bool $isolateTests = true;

    private int $institution;

    private int $program;

    private int $term;

    protected function setUp(): void
    {
        parent::setUp();

        $d                 = DeanFactory::department('SC1');
        $this->institution = $d['institution'];
        $this->program     = DeanFactory::program(DeanFactory::subDepartment($d['department'], 'SC'), 'SC');
        $this->term        = DeanFactory::term($this->institution);
    }

    private function command(string $line): string
    {
        $this->setUpStreamFilterTrait();
        $this->resetStreamFilterBuffer();

        try {
            command($line);

            return $this->getStreamFilterBuffer();
        } finally {
            $this->tearDownStreamFilterTrait();
        }
    }

    /** @return array{int, User, User} offering, student, instructor — with no chat yet */
    private function offeringWithPeople(string $code, string $status = 'active', string $enrolment = 'enrolled'): array
    {
        $offering   = DeanFactory::offering($this->program, $this->term, $code, 'A', $status);
        $student    = PeopleFactory::person('student', 'Stu', $code, null, $this->institution);
        $instructor = PeopleFactory::person('instructor', 'Ins', $code, null, $this->institution);
        CourseFactory::enroll($offering, $student, $enrolment);
        CourseFactory::assignInstructor($offering, $instructor);

        return [$offering, $student, $instructor];
    }

    /** @return list<int> */
    private function members(int $offering): array
    {
        $ids = array_map('intval', array_column(db_connect()->table('conversation_participants p')->select('p.user_id')
            ->join('conversations c', 'c.id = p.conversation_id')->where('c.offering_id', $offering)->where('p.left_at', null)->get()->getResultArray(), 'user_id'));
        sort($ids);

        return $ids;
    }

    private function counts(): array
    {
        return [db_connect()->table('conversations')->countAllResults(), db_connect()->table('conversation_participants')->countAllResults()];
    }

    public function testBackfillsOfferingsThatHaveNoChat(): void
    {
        [$a, $studentA, $teacherA] = $this->offeringWithPeople('BA1');
        [$b, $studentB, $teacherB] = $this->offeringWithPeople('BA2');

        $this->command('messages:sync-course-chats');

        $this->assertSame($this->sorted([(int) $studentA->id, (int) $teacherA->id]), $this->members($a));
        $this->assertSame($this->sorted([(int) $studentB->id, (int) $teacherB->id]), $this->members($b));
    }

    public function testRepairsDrift(): void
    {
        [$offering, $student, $teacher] = $this->offeringWithPeople('RD1');
        $stray                          = PeopleFactory::person('student', 'Str', 'Ay', null, $this->institution);
        $chat                           = (new CourseChatSync())->syncOffering($offering)['conversation_id'];
        db_connect()->table('conversation_participants')->where(['conversation_id' => $chat, 'user_id' => (int) $student->id])->delete();
        db_connect()->table('conversation_participants')->insert(['conversation_id' => $chat, 'user_id' => (int) $stray->id, 'joined_at' => date('Y-m-d H:i:s'), 'first_visible_message_id' => 0]);

        $this->command('messages:sync-course-chats');

        $this->assertSame($this->sorted([(int) $student->id, (int) $teacher->id]), $this->members($offering));
    }

    public function testIsIdempotent(): void
    {
        $this->offeringWithPeople('ID1');
        $this->command('messages:sync-course-chats');
        $before = $this->counts();

        $output = $this->command('messages:sync-course-chats');

        $this->assertSame($before, $this->counts());
        $this->assertStringContainsString('0 chat(s) created, 0 member(s) added, 0 removed', $output);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->offeringWithPeople('DR1');

        $output = $this->command('messages:sync-course-chats --dry-run');

        $this->assertSame([0, 0], $this->counts());
        $this->assertStringContainsString('Would have: 1 chat(s) created, 2 member(s) added', $output);
    }

    public function testSkipsArchivedOfferings(): void
    {
        [$offering] = $this->offeringWithPeople('AR1', 'archived');

        $this->command('messages:sync-course-chats');

        $this->assertSame(0, db_connect()->table('conversations')->where('offering_id', $offering)->countAllResults());
    }

    public function testBackfillOfAnAlreadyClosedTermKeepsCompletedStudents(): void
    {
        [$offering, $student, $teacher] = $this->offeringWithPeople('CL1', 'completed', 'completed');

        $this->command('messages:sync-course-chats');

        $this->assertSame($this->sorted([(int) $student->id, (int) $teacher->id]), $this->members($offering));
        $this->assertSame(0, db_connect()->table('conversation_participants')->where('left_at IS NOT NULL', null, false)->countAllResults());
    }

    public function testRealRunRecordsTheHeartbeatAndDryRunDoesNot(): void
    {
        setting()->forget('Monitoring.messagesSyncAt');

        $this->command('messages:sync-course-chats --dry-run');
        $this->assertNull(ScheduledJobs::lastRun('messages:sync-course-chats'));

        $this->command('messages:sync-course-chats');
        $this->assertNotNull(ScheduledJobs::lastRun('messages:sync-course-chats'));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
