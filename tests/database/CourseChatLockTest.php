<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * An instructor locks a course chat to announcements-only, and opens it again.
 *
 * @internal
 */
final class CourseChatLockTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    private int $institution;

    private int $offering;

    private int $chat;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $d                 = DeanFactory::college('L1');
        $this->institution = $d['institution'];
        $program           = DeanFactory::program(DeanFactory::department($d['college'], 'LA'), 'LA');
        $term              = DeanFactory::term($this->institution);
        $this->offering    = DeanFactory::offering($program, $term, 'LK1');
        $this->student     = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->instructor  = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $this->institution);

        CourseFactory::enroll($this->offering, $this->student);
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];

        db_connect()->table('audit_logs')->emptyTable();
    }

    private function messages(): MessageService
    {
        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';

        return new MessageService(new MessageCipher($config));
    }

    private function locked(): bool
    {
        return (bool) db_connect()->table('conversations')->where('id', $this->chat)->get()->getRowArray()['is_locked'];
    }

    public function testTheInstructorLocksAndUnlocksAndEachChangeIsAudited(): void
    {
        $service = new ConversationService();

        $this->assertSame(['id' => $this->chat, 'is_locked' => true], $service->setLocked($this->instructor, $this->chat, true));
        $this->assertTrue($this->locked());
        $this->assertSame(['id' => $this->chat, 'is_locked' => false], $service->setLocked($this->instructor, $this->chat, false));
        $this->assertFalse($this->locked());

        $actions = array_column(db_connect()->table('audit_logs')->select('action')->orderBy('id')->get()->getResultArray(), 'action');
        $this->assertSame(['messages.course_chat_locked', 'messages.course_chat_unlocked'], $actions);

        $row = db_connect()->table('audit_logs')->orderBy('id')->get()->getRowArray();
        $this->assertSame($this->chat, (int) $row['target_id']);
        $this->assertSame('conversation', $row['target_type']);
    }

    public function testLockingAnAlreadyLockedChatSucceedsWithoutAnotherAuditRow(): void
    {
        $service = new ConversationService();
        $service->setLocked($this->instructor, $this->chat, true);
        $service->setLocked($this->instructor, $this->chat, true);

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'messages.course_chat_locked')->countAllResults());
    }

    public function testNobodyElseCanLock(): void
    {
        $service    = new ConversationService();
        $otherTeach = PeopleFactory::person('instructor', 'Oth', 'Er', null, $this->institution);
        $chair      = PeopleFactory::person('chairperson', 'Cha', 'Ir', null, $this->institution);

        foreach ([$this->student, $otherTeach, $chair] as $user) {
            $this->assertNull($service->setLocked($user, $this->chat, true));
        }

        $this->assertFalse($this->locked());
        $this->assertSame(0, db_connect()->table('audit_logs')->countAllResults());
    }

    public function testADirectConversationCannotBeLocked(): void
    {
        $direct = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];

        $this->assertNull((new ConversationService())->setLocked($this->instructor, $direct, true));
    }

    public function testALockedChatStopsStudentsPostingButStillReads(): void
    {
        $messages = $this->messages();
        (new ConversationService())->setLocked($this->instructor, $this->chat, true);

        $this->assertSame('not_allowed', $messages->send($this->student, $this->chat, 'hello', 'uuid-student-1')['reason']);
        $this->assertTrue($messages->send($this->instructor, $this->chat, 'announcement', 'uuid-teacher-1')['ok']);
        $this->assertCount(1, $messages->page($this->student, $this->chat)['messages']);
        $this->assertCount(1, (new ConversationService())->listFor($this->student, 'course'));

        (new ConversationService())->setLocked($this->instructor, $this->chat, false);
        $this->assertTrue($messages->send($this->student, $this->chat, 'hello again', 'uuid-student-2')['ok']);
    }

    public function testRowsSayWhoCanModerate(): void
    {
        $service = new ConversationService();

        $teacherRow = (new ConversationPresenter())->present($this->instructor, $service->listFor($this->instructor))[0];
        $studentRow = (new ConversationPresenter())->present($this->student, $service->listFor($this->student))[0];

        $this->assertTrue($teacherRow['can_moderate']);
        $this->assertFalse($studentRow['can_moderate']);
        $this->assertFalse($studentRow['closed']);

        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'completed']);
        $closedRow = (new ConversationPresenter())->present($this->student, $service->listFor($this->student))[0];

        $this->assertTrue($closedRow['closed']);
        $this->assertFalse($closedRow['can_post']);
    }
}
