<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageActions;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\ReportService;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Delete for everyone: the text is erased, the window and switch are enforced, reports keep their copy.
 *
 * @internal
 */
final class MessageActionsDeleteEveryoneTest extends DatabaseTestCase
{
    private const KEY    = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';
    private const SECRET = 'the-secret-words';

    protected bool $isolateTests = true;

    private int $institution;

    private User $student;

    private User $instructor;

    private int $conversation;

    private int $message;

    private MessageCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();

        $d           = DeanFactory::department('S1');
        $institution = $this->institution = $d['institution'];
        $program     = DeanFactory::program(DeanFactory::subDepartment($d['department'], 'SA'), 'SA');
        $offering    = DeanFactory::offering($program, DeanFactory::term($institution), 'MS1');

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $institution);
        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $this->instructor);

        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';
        $this->cipher          = new MessageCipher($config);

        $this->conversation = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
        $this->message      = $this->post($this->student, self::SECRET);
    }

    private function actions(): MessageActions
    {
        return new MessageActions(new MessageService($this->cipher), null, null, null, $this->cipher);
    }

    private function post(User $sender, string $text, ?int $replyTo = null): int
    {
        db_connect()->table('messages')->insert([
            'conversation_id' => $this->conversation, 'sender_id' => $sender->id,
            'body_enc' => $this->cipher->encrypt($text, $this->conversation, (int) $sender->id),
            'reply_to_message_id' => $replyTo, 'client_uuid' => bin2hex(random_bytes(16)), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, mixed> */
    private function row(?int $id = null): array
    {
        return db_connect()->table('messages')->where('id', $id ?? $this->message)->get()->getRowArray();
    }

    public function testTheStoredTextBecomesEmptyAndTheAuditHoldsNoText(): void
    {
        $result = $this->actions()->deleteForEveryone($this->student, $this->conversation, $this->message);

        $row = $this->row();
        $this->assertTrue($result['ok']);
        $this->assertSame('', $this->cipher->decrypt((string) $row['body_enc'], $this->conversation, (int) $this->student->id));
        $this->assertNotNull($row['deleted_everyone_at']);
        $this->assertNotNull($row['changed_at']);

        $audit = db_connect()->table('audit_logs')->where('action', 'messages.message_deleted_everyone')->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString(self::SECRET, json_encode($audit));
    }

    public function testAReportMadeBeforeKeepsItsReadableSnapshot(): void
    {
        $this->assertTrue((new ReportService($this->cipher))->report($this->instructor, $this->conversation, $this->message, 'spam', null));

        $this->actions()->deleteForEveryone($this->student, $this->conversation, $this->message);

        $snapshot = db_connect()->table('message_reports')->get()->getRowArray()['snapshot_enc'];
        $plain    = $this->cipher->decrypt((string) $snapshot, $this->conversation, (int) $this->instructor->id);
        $this->assertStringContainsString(self::SECRET, (string) $plain);
    }

    public function testSomeoneElsesMessageIsNotAllowed(): void
    {
        $result = $this->actions()->deleteForEveryone($this->instructor, $this->conversation, $this->message);

        $this->assertSame(['ok' => false, 'reason' => 'not_allowed'], $result);
        $this->assertNull($this->row()['deleted_everyone_at']);
    }

    public function testAtMinuteSixteenItIsTooLate(): void
    {
        db_connect()->table('messages')->where('id', $this->message)->update(['created_at' => date('Y-m-d H:i:s', time() - 16 * 60)]);

        $this->assertSame(['ok' => false, 'reason' => 'too_late'], $this->actions()->deleteForEveryone($this->student, $this->conversation, $this->message));
        $this->assertNull($this->row()['deleted_everyone_at']);
    }

    public function testWithTheSwitchOffItIsRefused(): void
    {
        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_delete_everyone' => 0]);

        $this->assertSame(['ok' => false, 'reason' => 'switched_off'], $this->actions()->deleteForEveryone($this->student, $this->conversation, $this->message));
    }

    public function testAnUnknownOrForeignMessageIsNotFound(): void
    {
        $outsider = PeopleFactory::person('student', 'Out', 'Sider', null, $this->institution);

        $this->assertSame('not_found', $this->actions()->deleteForEveryone($this->student, $this->conversation, 999999)['reason']);
        $this->assertSame('not_found', $this->actions()->deleteForEveryone($outsider, $this->conversation, $this->message)['reason']);
    }

    public function testCallingTwiceIsHarmlessWithOneAuditRow(): void
    {
        $this->actions()->deleteForEveryone($this->student, $this->conversation, $this->message);
        $first = $this->row()['deleted_everyone_at'];

        $this->assertTrue($this->actions()->deleteForEveryone($this->student, $this->conversation, $this->message)['ok']);
        $this->assertSame($first, $this->row()['deleted_everyone_at']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'messages.message_deleted_everyone')->countAllResults());
    }

    public function testThePayloadHidesTheBodyAndDropsTheFlagButRepliesAndReactionsSurvive(): void
    {
        $reply = $this->post($this->instructor, 'answer', $this->message);
        db_connect()->table('message_reactions')->insert(['message_id' => $this->message, 'user_id' => $this->instructor->id, 'emoji' => '👍', 'created_at' => date('Y-m-d H:i:s')]);
        $service = new MessageService($this->cipher);

        $before = $service->page($this->student, $this->conversation)['messages'];
        $this->assertTrue($before[0]['can_delete_everyone']);
        $this->assertFalse($before[1]['can_delete_everyone']);

        $this->actions()->deleteForEveryone($this->student, $this->conversation, $this->message);
        $after = $service->page($this->instructor, $this->conversation)['messages'];

        $this->assertTrue($after[0]['deleted_everyone']);
        $this->assertNull($after[0]['body']);
        $this->assertFalse($after[0]['can_delete_everyone']);
        $this->assertSame('answer', $after[1]['body']);
        $this->assertSame('Message removed', $after[1]['reply']['text']);
        $this->assertSame(1, db_connect()->table('message_reactions')->where('message_id', $this->message)->countAllResults());
        $this->assertSame($this->message, (int) $this->row($reply)['reply_to_message_id']);
    }
}
