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
 * Removing a message from a course chat: who may, what everyone then sees, and
 * that no text survives anywhere but the evidence column.
 *
 * @internal
 */
final class MessageRemovalTest extends DatabaseTestCase
{
    private const KEY    = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';
    private const SECRET = 'zebra-marker-7731';

    protected bool $isolateTests = true;

    private int $institution;

    private int $chat;

    private User $student;

    private User $other;

    private User $instructor;

    private int $program;

    private int $term;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $d                 = DeanFactory::college('M1');
        $this->institution = $d['institution'];
        $this->program     = DeanFactory::program(DeanFactory::department($d['college'], 'MA'), 'MA');
        $this->term        = DeanFactory::term($this->institution);
        $offering          = DeanFactory::offering($this->program, $this->term, 'MR1');
        $this->student     = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->other       = PeopleFactory::person('student', 'Oth', 'Er', null, $this->institution);
        $this->instructor  = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $this->institution);

        CourseFactory::enroll($offering, $this->student);
        CourseFactory::enroll($offering, $this->other);
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->chat = (new CourseChatSync())->syncOffering($offering)['conversation_id'];

        db_connect()->table('audit_logs')->emptyTable();
    }

    private function messages(): MessageService
    {
        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';

        return new MessageService(new MessageCipher($config));
    }

    private function say(User $user, string $text): int
    {
        return (int) $this->messages()->send($user, $this->chat, $text, 'u-' . bin2hex(random_bytes(6)))['message']['id'];
    }

    public function testTheInstructorRemovesAStudentsMessageAndEveryoneSeesATombstone(): void
    {
        $id = $this->say($this->student, self::SECRET);
        $this->say($this->other, 'a later one');
        $this->messages()->hideForMe($this->other, $this->chat, $id);

        $this->assertTrue($this->messages()->remove($this->instructor, $this->chat, $id));

        $row = db_connect()->table('messages')->where('id', $id)->get()->getRowArray();
        $this->assertNotNull($row['removed_at']);
        $this->assertSame((int) $this->instructor->id, (int) $row['removed_by']);
        $this->assertNotSame('', $row['body_enc'], 'the evidence stays');

        foreach ([$this->student, $this->instructor] as $viewer) {
            $shown = array_values(array_filter($this->messages()->page($viewer, $this->chat)['messages'], static fn (array $m): bool => $m['id'] === $id))[0];

            $this->assertTrue($shown['removed']);
            $this->assertNull($shown['body']);
        }

        // Still hidden for the person who hid it.
        $this->assertCount(1, $this->messages()->page($this->other, $this->chat)['messages']);
    }

    public function testTheInstructorCanRemoveTheirOwnMessage(): void
    {
        $id = $this->say($this->instructor, 'oops');

        $this->assertTrue($this->messages()->remove($this->instructor, $this->chat, $id));
    }

    public function testNobodyElseCanRemove(): void
    {
        $id          = $this->say($this->student, 'mine');
        $otherTeach  = PeopleFactory::person('instructor', 'Oth', 'Teach', null, $this->institution);
        $chair       = PeopleFactory::person('chairperson', 'Cha', 'Ir', null, $this->institution);

        foreach ([$this->student, $this->other, $otherTeach, $chair] as $user) {
            $this->assertFalse($this->messages()->remove($user, $this->chat, $id));
        }

        $this->assertNull(db_connect()->table('messages')->where('id', $id)->get()->getRowArray()['removed_at']);
        $this->assertSame(0, db_connect()->table('audit_logs')->countAllResults());
    }

    public function testADirectMessageCannotBeRemovedByAnyone(): void
    {
        $direct = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
        $id     = (int) $this->messages()->send($this->student, $direct, 'private', 'u-direct-0001')['message']['id'];

        $this->assertFalse($this->messages()->remove($this->instructor, $direct, $id));
        $this->assertFalse($this->messages()->remove($this->student, $direct, $id));
    }

    public function testAMessageFromAnotherConversationIsNotFound(): void
    {
        $direct = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
        $id     = (int) $this->messages()->send($this->student, $direct, 'elsewhere', 'u-direct-0002')['message']['id'];

        $this->assertFalse($this->messages()->remove($this->instructor, $this->chat, $id));
    }

    public function testRemovingTwiceIsHarmlessAndAuditedOnce(): void
    {
        $id = $this->say($this->student, 'once');

        $this->assertTrue($this->messages()->remove($this->instructor, $this->chat, $id));
        $this->assertTrue($this->messages()->remove($this->instructor, $this->chat, $id));

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'messages.message_removed')->countAllResults());
    }

    public function testNoTextLeaksAfterRemoval(): void
    {
        $id = $this->say($this->student, self::SECRET);
        $this->messages()->remove($this->instructor, $this->chat, $id);

        $page    = json_encode($this->messages()->page($this->student, $this->chat), JSON_THROW_ON_ERROR);
        $service = new ConversationService();
        $rows    = (new ConversationPresenter(new MessageCipher($this->config())))->present($this->student, $service->listFor($this->student));
        $audit   = json_encode(db_connect()->table('audit_logs')->get()->getResultArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::SECRET, $page);
        $this->assertStringNotContainsString(self::SECRET, json_encode($rows, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::SECRET, $audit);
        $this->assertSame('Message removed', $rows[0]['preview']);
    }

    private function config(): Messages
    {
        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';

        return $config;
    }
}
