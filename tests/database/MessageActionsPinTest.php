<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageActions;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Pin: up to three messages per chat; course chats are pinned by the instructor only.
 *
 * @internal
 */
final class MessageActionsPinTest extends DatabaseTestCase
{
    private const KEY    = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';
    private const SECRET = 'pin-secret-text';

    protected bool $isolateTests = true;

    private User $student;

    private User $instructor;

    private int $direct;

    private int $course;

    private MessageCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();

        $d           = DeanFactory::college('P1');
        $institution = $d['institution'];
        $program     = DeanFactory::program(DeanFactory::department($d['college'], 'PA'), 'PA');
        $offering    = DeanFactory::offering($program, DeanFactory::term($institution), 'MP1');

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $institution);
        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $this->instructor);

        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';
        $this->cipher          = new MessageCipher($config);

        $this->direct = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
        $this->course = (int) (new CourseChatSync())->syncOffering($offering)['conversation_id'];
    }

    private function actions(): MessageActions
    {
        return new MessageActions(new MessageService($this->cipher), null, null, null, $this->cipher);
    }

    private function post(int $conversation, User $sender, string $text): int
    {
        db_connect()->table('messages')->insert([
            'conversation_id' => $conversation, 'sender_id' => $sender->id,
            'body_enc' => $this->cipher->encrypt($text, $conversation, (int) $sender->id),
            'client_uuid' => bin2hex(random_bytes(16)), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return db_connect()->table('messages')->where('id', $id)->get()->getRowArray();
    }

    public function testAStudentCannotPinInACourseChatButTheInstructorCan(): void
    {
        $id = $this->post($this->course, $this->student, 'hello');

        $this->assertSame(['ok' => false, 'reason' => 'not_allowed'], $this->actions()->pinMessage($this->student, $this->course, $id, true));
        $this->assertNull($this->row($id)['pinned_at']);

        $this->assertTrue($this->actions()->pinMessage($this->instructor, $this->course, $id, true)['ok']);
        $row = $this->row($id);
        $this->assertNotNull($row['pinned_at']);
        $this->assertSame((int) $this->instructor->id, (int) $row['pinned_by']);
        $this->assertNotNull($row['changed_at']);
    }

    public function testEitherSideCanPinInADirectChat(): void
    {
        $a = $this->post($this->direct, $this->student, 'one');
        $b = $this->post($this->direct, $this->instructor, 'two');

        $this->assertTrue($this->actions()->pinMessage($this->student, $this->direct, $a, true)['ok']);
        $this->assertTrue($this->actions()->pinMessage($this->instructor, $this->direct, $b, true)['ok']);
    }

    public function testTheFourthPinIsLimitedAndPinningAgainChangesNothing(): void
    {
        $ids = [];

        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->post($this->direct, $this->student, 'm' . $i);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->actions()->pinMessage($this->student, $this->direct, $ids[$i], true)['ok']);
        }

        $this->assertSame(['ok' => false, 'reason' => 'limit'], $this->actions()->pinMessage($this->student, $this->direct, $ids[3], true));
        $this->assertTrue($this->actions()->pinMessage($this->student, $this->direct, $ids[0], true)['ok']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'messages.message_pinned')->where('target_id', $ids[0])->countAllResults());
    }

    /** @return list<int> three pinned messages plus a fourth, unpinned one */
    private function threePinnedAndOneSpare(): array
    {
        $ids = [];

        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->post($this->direct, $this->student, 'm' . $i);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->actions()->pinMessage($this->student, $this->direct, $ids[$i], true)['ok']);
        }

        return $ids;
    }

    public function testAnErasedPinnedMessageNoLongerCountsTowardTheLimit(): void
    {
        $ids = $this->threePinnedAndOneSpare();
        $this->assertSame('limit', $this->actions()->pinMessage($this->student, $this->direct, $ids[3], true)['reason']);

        db_connect()->table('messages')->where('id', $ids[0])->update(['deleted_everyone_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->actions()->pinMessage($this->student, $this->direct, $ids[3], true)['ok']);
    }

    public function testARemovedPinnedMessageNoLongerCountsTowardTheLimit(): void
    {
        $ids = $this->threePinnedAndOneSpare();

        db_connect()->table('messages')->where('id', $ids[1])->update(['removed_at' => date('Y-m-d H:i:s'), 'removed_by' => $this->instructor->id]);

        $this->assertTrue($this->actions()->pinMessage($this->student, $this->direct, $ids[3], true)['ok']);
    }

    public function testUnpinClearsAndFreesASlot(): void
    {
        $id = $this->post($this->direct, $this->student, 'x');
        $this->actions()->pinMessage($this->student, $this->direct, $id, true);

        $this->assertTrue($this->actions()->pinMessage($this->instructor, $this->direct, $id, false)['ok']);
        $row = $this->row($id);
        $this->assertNull($row['pinned_at']);
        $this->assertNull($row['pinned_by']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'messages.message_unpinned')->countAllResults());
    }

    public function testMissingOrErasedMessagesAreNotFound(): void
    {
        $id = $this->post($this->direct, $this->student, 'gone');
        db_connect()->table('messages')->where('id', $id)->update(['deleted_everyone_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('not_found', $this->actions()->pinMessage($this->student, $this->direct, $id, true)['reason']);
        $this->assertSame('not_found', $this->actions()->pinMessage($this->student, $this->direct, 999999, true)['reason']);
    }

    public function testThePageListsPinnedPreviewsNewestFirstWithoutCiphertext(): void
    {
        $long  = $this->post($this->direct, $this->student, str_repeat('a', 100));
        $short = $this->post($this->direct, $this->instructor, self::SECRET);
        $this->actions()->pinMessage($this->student, $this->direct, $long, true);
        db_connect()->table('messages')->where('id', $long)->update(['pinned_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->actions()->pinMessage($this->student, $this->direct, $short, true);

        $page   = (new MessageService($this->cipher))->page($this->student, $this->direct);
        $pinned = $page['pinned'];

        $this->assertSame([$short, $long], array_column($pinned, 'id'));
        $this->assertSame(self::SECRET, $pinned[0]['text']);
        $this->assertSame(str_repeat('a', 80) . '…', $pinned[1]['text']);
        $this->assertSame('Ins Tructor', $pinned[0]['sender_name']);
        $this->assertTrue($page['messages'][1]['pinned']);
        $this->assertTrue($page['messages'][1]['can_pin']);

        // A message erased afterwards drops out of the list.
        db_connect()->table('messages')->where('id', $short)->update(['deleted_everyone_at' => date('Y-m-d H:i:s')]);
        $this->assertSame([$long], array_column((new MessageService($this->cipher))->page($this->student, $this->direct)['pinned'], 'id'));
    }

    public function testCanPinIsFalseForAStudentInACourseChat(): void
    {
        $this->post($this->course, $this->instructor, 'welcome');

        $this->assertFalse((new MessageService($this->cipher))->page($this->student, $this->course)['messages'][0]['can_pin']);
        $this->assertTrue((new MessageService($this->cipher))->page($this->instructor, $this->course)['messages'][0]['can_pin']);
    }

    public function testTheAuditHasIdsOnly(): void
    {
        $id = $this->post($this->direct, $this->student, self::SECRET);
        $this->actions()->pinMessage($this->student, $this->direct, $id, true);

        $audit = db_connect()->table('audit_logs')->where('action', 'messages.message_pinned')->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString(self::SECRET, json_encode($audit));
        $this->assertSame($id, (int) $audit[0]['target_id']);
    }
}
