<?php

declare(strict_types=1);

use Config\Messages;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\RateLimitedException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * FR3: opening a person creates nothing; the first message creates the conversation.
 *
 * @internal
 */
final class ConversationDraftTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private User $student;

    private User $instructor;

    private int $institution;

    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        $d           = DeanFactory::college('D1');
        $institution = $d['institution'];
        $this->institution = $institution;
        $program     = DeanFactory::program(DeanFactory::department($d['college'], 'DA'), 'DA');
        $term        = DeanFactory::term($institution);

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $institution);
        $offering         = DeanFactory::offering($program, $term, 'DR1');
        $this->offering   = $offering;
        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $this->instructor);
    }

    private function messages(): MessageService
    {
        $config                = new Messages();
        $config->encryptionKey = 'hex2bin:' . str_repeat('61', 32);
        $config->previousKeys  = '';

        return new MessageService(new MessageCipher($config));
    }

    private function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function testDraftCreatesNothing(): void
    {
        $draft = (new ConversationService())->draftFor($this->student, (int) $this->instructor->id);

        $this->assertSame(['recipient_id' => (int) $this->instructor->id, 'subject_student_id' => null, 'first_contact' => false, 'conversation_id' => null], $draft);
        $this->assertNull((new ConversationService())->existingDirect($this->student, (int) $this->instructor->id));
        $this->assertSame(0, db_connect()->table('conversations')->countAllResults());
        $this->assertSame(0, db_connect()->table('conversation_participants')->countAllResults());
    }

    public function testDraftReturnsExistingThread(): void
    {
        $id = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];

        $draft = (new ConversationService())->draftFor($this->instructor, (int) $this->student->id);

        $this->assertSame($id, $draft['conversation_id']);
        $this->assertSame($id, (int) (new ConversationService())->existingDirect($this->instructor, (int) $this->student->id)['id']);
    }

    public function testRefusedDraftIsNull(): void
    {
        $this->assertNull((new ConversationService())->draftFor($this->student, 999999));
        $this->assertNull((new ConversationService())->draftFor($this->student, (int) $this->student->id));
    }

    public function testSendFirstCreatesThreadAndMessage(): void
    {
        $result = $this->messages()->sendFirst($this->student, (int) $this->instructor->id, null, 'Hello sir', $this->uuid());

        $this->assertTrue($result['ok']);
        $db = db_connect();
        $this->assertSame(1, $db->table('conversations')->countAllResults());
        $this->assertSame(2, $db->table('conversation_participants')->where('conversation_id', $result['conversation_id'])->countAllResults());
        $this->assertSame(1, $db->table('messages')->where('conversation_id', $result['conversation_id'])->countAllResults());
        $this->assertNotNull($db->table('conversations')->where('id', $result['conversation_id'])->get()->getRow('last_message_id'));
    }

    public function testSendFirstWithABadBodyCreatesNothing(): void
    {
        $result = $this->messages()->sendFirst($this->student, (int) $this->instructor->id, null, '   ', $this->uuid());

        $this->assertFalse($result['ok']);
        $this->assertSame(0, db_connect()->table('conversations')->countAllResults());
        $this->assertSame(0, db_connect()->table('conversation_participants')->countAllResults());
    }

    public function testSendFirstRefusedIsNotFound(): void
    {
        $result = $this->messages()->sendFirst($this->student, (int) $this->student->id, null, 'Hi', $this->uuid());

        $this->assertSame(['ok' => false, 'reason' => 'not_found'], $result);
        $this->assertSame(0, db_connect()->table('conversations')->countAllResults());
    }

    // The real race (two connections) is FirstSendRaceTest.
    public function testTheSecondSideOfAPairFindsTheThreadTheFirstMade(): void
    {
        $a = $this->messages()->sendFirst($this->student, (int) $this->instructor->id, null, 'One', $this->uuid());
        $b = $this->messages()->sendFirst($this->instructor, (int) $this->student->id, null, 'Two', $this->uuid());

        $this->assertTrue($a['ok'] && $b['ok']);
        $this->assertSame($a['conversation_id'], $b['conversation_id']);
        $db = db_connect();
        $this->assertSame(1, $db->table('conversations')->countAllResults());
        $this->assertSame(2, $db->table('messages')->countAllResults());
        // The thread is counted once, against whoever started it.
        $this->assertSame(1, $db->table('conversations')->where('created_by', $this->student->id)->countAllResults());
        $this->assertSame(0, $db->table('conversations')->where('created_by', $this->instructor->id)->countAllResults());
    }

    public function testEmptyLegacyThreadHiddenFromListAndCounts(): void
    {
        $service = new ConversationService();
        $id      = (int) $service->startDirect($this->student, (int) $this->instructor->id)['id'];

        // Marked unread by hand, so the unread count would be 1 if the empty thread were not left out.
        db_connect()->table('conversation_participants')->where(['conversation_id' => $id, 'user_id' => $this->instructor->id])->update(['marked_unread' => 1]);

        $this->assertSame([], $service->listFor($this->instructor));
        $this->assertSame(0, $service->unreadCount($this->instructor));

        db_connect()->table('conversation_participants')->where(['conversation_id' => $id, 'user_id' => $this->instructor->id])->update(['archived_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(0, $service->archivedCount($this->instructor));

        $this->messages()->send($this->student, $id, 'Now it has one', $this->uuid());
        $this->assertCount(1, $service->listFor($this->student));
    }

    /** Rows that count against the sender's new-thread limits (the limiter counts conversations by creator). */
    private function startedThreads(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            db_connect()->table('conversations')->insert([
                'institution_id' => $this->institution,
                'type' => 'direct', 'created_by' => $user->id, 'is_locked' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function testAnInstructorMayStartAnEleventhThreadButNotATwentyFirst(): void
    {
        $this->startedThreads($this->instructor, 10);
        $this->assertTrue($this->messages()->sendFirst($this->instructor, (int) $this->student->id, null, 'Eleventh', $this->uuid())['ok']);

        $this->startedThreads($this->instructor, 9);
        $other = PeopleFactory::person('student', 'Oth', 'Er', null, $this->institution);
        CourseFactory::enroll($this->offering, $other);
        $this->expectException(RateLimitedException::class);
        $this->messages()->sendFirst($this->instructor, (int) $other->id, null, 'Twenty first', $this->uuid());
    }

    public function testAStudentWhoAlsoTeachesGetsTheStaffLimits(): void
    {
        $this->student->addGroup('instructor');
        $this->startedThreads($this->student, 10);

        $this->assertTrue($this->messages()->sendFirst($this->student, (int) $this->instructor->id, null, 'Eleventh', $this->uuid())['ok']);
    }
}
