<?php

declare(strict_types=1);

use App\Services\Messaging\BlockService;
use App\Services\Messaging\ChatStateService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\ReportService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * FR11: in a read-only institution block, unblock, report, mute, archive and mark read still work;
 * in a past one everything is read-only, safety actions included.
 *
 * @internal
 */
final class ReadOnlyInstitutionSafetyTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    private int $institution;

    private User $ann;

    private User $ben;

    private int $thread;

    private int $message;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = DeanFactory::college('ROS1')['institution'];
        $this->ann         = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->ben         = PeopleFactory::person('student', 'Ben', 'Ng', null, $this->institution);
        $this->thread      = (int) (new ConversationService())->startDirect($this->ben, (int) $this->ann->id)['id'];

        $sent = (new MessageService($this->cipher()))->send($this->ben, $this->thread, 'Hello', 'uuid-ros-1');
        $this->assertTrue($sent['ok']);
        $this->message = (int) db_connect()->table('messages')->where('conversation_id', $this->thread)->get()->getRow('id');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function cipher(): MessageCipher
    {
        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';

        return new MessageCipher($config);
    }

    private function institutionIs(string $status): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => $status]);
    }

    public function testReadOnlyInstitutionAllowsBlockReportMuteArchiveRead(): void
    {
        $this->institutionIs('read_only');
        $state = new ChatStateService();

        $this->assertTrue($state->setMuted($this->ann, $this->thread, true)['ok']);
        $this->assertTrue($state->setArchived($this->ann, $this->thread, true)['ok']);
        $this->assertTrue($state->markRead($this->ann, $this->thread)['ok']);
        $this->assertTrue((new ReportService($this->cipher()))->report($this->ann, $this->thread, $this->message, 'spam', null));
        $this->assertTrue((new BlockService())->block($this->ann, (int) $this->ben->id));
        $this->assertTrue((new BlockService())->unblock($this->ann, (int) $this->ben->id));

        // Posting still stops.
        $this->assertFalse((new MessageService($this->cipher()))->send($this->ann, $this->thread, 'Hi', 'uuid-ros-2')['ok']);
    }

    public function testPastInstitutionRefusesSafetyActions(): void
    {
        $this->institutionIs('past');
        $state = new ChatStateService();

        $this->assertFalse($state->setMuted($this->ann, $this->thread, true)['ok']);
        $this->assertFalse($state->setArchived($this->ann, $this->thread, true)['ok']);
        $this->assertFalse($state->markRead($this->ann, $this->thread)['ok']);
        $this->assertFalse((new ReportService($this->cipher()))->report($this->ann, $this->thread, $this->message, 'spam', null));
        $this->assertFalse((new BlockService())->block($this->ann, (int) $this->ben->id));

        $db = db_connect();
        $this->assertSame(0, $db->table('message_reports')->countAllResults());
        $this->assertSame(0, $db->table('user_blocks')->countAllResults());

        $db->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => (int) $this->ann->id, 'blocked_id' => (int) $this->ben->id, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertFalse((new BlockService())->unblock($this->ann, (int) $this->ben->id));
        $this->assertSame(1, $db->table('user_blocks')->countAllResults());
        $this->assertSame(0, (int) $db->table('conversation_participants')->where(['conversation_id' => $this->thread, 'user_id' => (int) $this->ann->id])->get()->getRow('is_muted'));
    }
}
