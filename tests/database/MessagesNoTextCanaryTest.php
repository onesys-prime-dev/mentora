<?php

declare(strict_types=1);

use App\Services\Messaging\BlockService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageNotifier;
use App\Services\Messaging\MessageRateLimiter;
use App\Services\Messaging\MessageReencryptor;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\RateLimitedException;
use App\Services\Messaging\ReportReviewService;
use App\Services\Messaging\ReportService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * The anti-goal of spec 1: what someone wrote must not turn up in an audit entry, a log, a
 * notification, an email, a stored report column, or a refusal. It is readable only in the
 * conversation to a participant and in an administrator's report view.
 *
 * @internal
 */
final class MessagesNoTextCanaryTest extends DatabaseTestCase
{
    use ParentScenario;

    private const PHRASE = 'canary-phrase-7f3a91c2';

    protected bool $isolateTests = true;

    private FakeEmail $mail;

    private int $chat;

    private User $admin;

    private User $classmate;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->mail = FakeEmail::install();
        $this->buildParentScenario('CN');
        $this->enableMessages();
        $this->admin     = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->classmate = PeopleFactory::person('student', 'Cla', 'Ssmate', null, $this->institution);
        \Tests\Support\CourseFactory::enroll($this->offering, $this->classmate);
        $this->chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        $this->releaseMessages();

        parent::tearDown();
    }

    private function logSize(): int
    {
        $file = WRITEPATH . 'logs/log-' . date('Y-m-d') . '.log';

        return is_file($file) ? (int) filesize($file) : 0;
    }

    private function logSince(int $offset): string
    {
        $file = WRITEPATH . 'logs/log-' . date('Y-m-d') . '.log';

        return is_file($file) ? (string) file_get_contents($file, false, null, $offset) : '';
    }

    private function everyStoredPlace(): string
    {
        $db = db_connect();

        return json_encode([
            $db->table('audit_logs')->get()->getResultArray(),
            $db->table('notifications')->get()->getResultArray(),
            $db->table('message_reports')->get()->getResultArray(),
            $db->table('user_blocks')->get()->getResultArray(),
            $this->mail->sent,
        ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function testNoStageOfTheLifeOfAMessageLeaksItsText(): void
    {
        $logStart = $this->logSize();
        $messages = new MessageService($this->parentCipher());
        $phrase   = self::PHRASE;

        // Send (and so notify and email) in a course chat and a direct thread.
        $first  = (int) $messages->send($this->instructor, $this->chat, "Announcement {$phrase}", 'uuid-t-1')['message']['id'];
        $second = (int) $messages->send($this->child, $this->chat, "A reply {$phrase}", 'uuid-c-1')['message']['id'];
        $direct = (int) (new ConversationService())->startDirect($this->child, (int) $this->classmate->id)['id'];
        $dm     = (int) $messages->send($this->classmate, $direct, "Private {$phrase}", 'uuid-k-1')['message']['id'];

        // Read state, hide for me, lock and unlock, block, remove, report, review with removal.
        $messages->markRead($this->child, $this->chat, $first);
        $messages->hideForMe($this->child, $this->chat, $second);
        (new ConversationService())->setLocked($this->instructor, $this->chat, true);
        (new ConversationService())->setLocked($this->instructor, $this->chat, false);
        (new BlockService())->block($this->child, (int) $this->classmate->id);
        $this->assertTrue((new ReportService($this->parentCipher()))->report($this->child, $this->chat, $first, 'harassment', "Note {$phrase}"));
        $this->assertTrue((new ReportService($this->parentCipher()))->report($this->child, $direct, $dm, 'spam', "Note two {$phrase}"));
        $reportId = (int) db_connect()->table('message_reports')->where('message_id', $first)->get()->getRow('id');
        $this->assertTrue((new ReportReviewService($this->parentCipher()))->review($this->admin, $reportId, 'actioned', true));
        $this->assertTrue($messages->remove($this->instructor, $this->chat, (int) $messages->send($this->instructor, $this->chat, "Oops {$phrase}", 'uuid-t-2')['message']['id']));

        // The sweep, the preview list, the rotation.
        (new MessageNotifier())->notifyConversation($this->chat);
        (new ConversationService())->listFor($this->child);
        (new MessageReencryptor($this->parentCipher()))->run(false);

        // A refusal for over-sending.
        for ($i = 0; $i < MessageRateLimiter::MESSAGES_PER_MINUTE; $i++) {
            db_connect()->table('messages')->insert(['conversation_id' => $this->chat, 'sender_id' => $this->instructor->id, 'body_enc' => 'x', 'client_uuid' => bin2hex(random_bytes(12)), 'created_at' => date('Y-m-d H:i:s')]);
        }

        try {
            $messages->send($this->instructor, $this->chat, "Too many {$phrase}", 'uuid-t-3');
            $this->fail('the limit must apply');
        } catch (RateLimitedException $e) {
            $this->assertStringNotContainsString($phrase, $e->getMessage());
        }

        $this->assertStringNotContainsString($phrase, $this->everyStoredPlace(), 'audit, notifications, reports, blocks and mail hold no text');
        $this->assertStringNotContainsString($phrase, $this->logSince($logStart), 'the log holds no text');
        $this->assertGreaterThan(0, count($this->mail->sent) + db_connect()->table('notifications')->countAllResults(), 'the flow did notify');
    }

    public function testTheTextIsThereWhereItBelongs(): void
    {
        $messages = new MessageService($this->parentCipher());
        $id       = (int) $messages->send($this->instructor, $this->chat, 'Visible ' . self::PHRASE, 'uuid-t-9')['message']['id'];
        (new ReportService($this->parentCipher()))->report($this->child, $this->chat, $id, 'other', null);
        $reportId = (int) db_connect()->table('message_reports')->get()->getRow('id');

        $this->assertStringContainsString(self::PHRASE, json_encode($messages->page($this->child, $this->chat)));
        $this->assertStringContainsString(self::PHRASE, json_encode((new ReportReviewService($this->parentCipher()))->view($this->admin, $reportId)));
        $this->assertStringNotContainsString(self::PHRASE, json_encode((new ReportReviewService($this->parentCipher()))->list($this->admin)));
    }
}
